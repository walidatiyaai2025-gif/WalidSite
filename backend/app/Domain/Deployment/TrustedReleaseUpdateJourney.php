<?php

namespace App\Domain\Deployment;

use DomainException;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Opt-in, backend-only trusted update integration. No HTTP controller calls
 * this service: owner authorization, external trusted download, write/queue
 * quiescence and production database migration are separate release gates.
 *
 * An update without a real, independently restored DB+file recovery checkpoint
 * must never change the active code pointer.
 */
final class TrustedReleaseUpdateJourney
{
    /**
     * @param array{
     *   quarantine:string,incoming:string,archive:string,releases:string,
     *   current:string,public:string,environment:string,private_data:string,
     *   uploads:string,backup_vault:string,disposable_files:string
     * } $paths
     * @param callable():bool $writesQuiesced Actual site/queue write isolation.
     * @param callable():bool $healthCheck Live app smoke after cutover.
     * @return array{
     *   state:string,version:string,source_sha:string,artifact_sha256:string,
     *   previous:string,current:string,files:int,database_tables:int,database_rows:int
     * }
     */
    public function execute(
        PDO $liveDatabase,
        PDO $disposableRestoreDatabase,
        string $signedEnvelope,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        array $paths,
        callable $writesQuiesced,
        callable $healthCheck,
    ): array {
        return $this->withExclusiveUpdateLock($paths, fn (callable $lockCheck): array => $this->executeUnlocked(
            $liveDatabase, $disposableRestoreDatabase, $signedEnvelope,
            $publisherPublicKeyBase64, $pinnedHttpsOrigin, $installedVersion,
            $paths, $writesQuiesced, $healthCheck, $lockCheck,
        ));
    }

    private function executeUnlocked(
        PDO $liveDatabase,
        PDO $disposableRestoreDatabase,
        string $signedEnvelope,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        array $paths,
        callable $writesQuiesced,
        callable $healthCheck,
        callable $lockCheck,
    ): array {
        $writesQuiesced = $this->guardCallback($writesQuiesced, $lockCheck);
        $healthCheck = $this->guardCallback($healthCheck, $lockCheck);
        $lockCheck();
        if ($writesQuiesced() !== true) {
            throw new DomainException('The site must be in verified read-only maintenance before updating.');
        }

        $signed = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope, $publisherPublicKeyBase64,
            $pinnedHttpsOrigin, $installedVersion,
        );
        (new TrustedUpdateHostPreflight())->assertReady($paths, $signed['artifact_size']);

        $candidate = (new TrustedCodeCandidatePromoter())->promote(
            $signedEnvelope, $publisherPublicKeyBase64, $pinnedHttpsOrigin,
            $installedVersion, $paths['quarantine'], $paths['incoming'],
            $paths['archive'], $paths['releases'], $paths['public'],
        );

        $schema = new ReleaseSchemaCompatibility();
        $migrationState = $schema->assertCompatible($paths['current'], $candidate['directory']);

        // Bind the approved code identity to its complete, exact on-disk file
        // inventory. Do not accept a caller-supplied "true" as signed proof.
        $seal = $this->seal($candidate['directory']);
        $zipHash = hash_file('sha256', $paths['archive']);
        if (! is_string($zipHash) || ! hash_equals($candidate['artifact_sha256'], $zipHash)) {
            throw new DomainException('Signed release ZIP changed after code promotion.');
        }

        $verified = function () use ($candidate, $paths, $seal, $schema, $migrationState, $lockCheck): bool {
            try {
                $lockCheck();
                $archiveHash = hash_file('sha256', $paths['archive']);
                $current = $schema->assertCompatible($paths['current'], $candidate['directory']);
                return $current === $migrationState
                    && is_string($archiveHash)
                    && hash_equals($candidate['artifact_sha256'], $archiveHash)
                    && hash_equals($seal, $this->seal($candidate['directory']))
                    && $this->checkLock($lockCheck);
            } catch (\Throwable) {
                return false;
            }
        };

        $result = (new VerifiedRecoveryCutover())->execute(
            $liveDatabase, $disposableRestoreDatabase,
            $paths['environment'], $paths['private_data'], $paths['uploads'],
            $paths['backup_vault'], $paths['disposable_files'],
            $paths['releases'], $paths['current'], $paths['public'],
            $candidate['directory'],
            $writesQuiesced, $verified, $healthCheck,
        );

        return [
            'state' => $result['state'],
            'version' => $candidate['version'],
            'source_sha' => $candidate['source_sha'],
            'artifact_sha256' => $candidate['artifact_sha256'],
            'previous' => $result['previous'],
            'current' => $result['current'],
            'files' => $result['files'],
            'database_tables' => $result['database_tables'],
            'database_rows' => $result['database_rows'],
        ];
    }

    /**
     * One complete backend chain: publisher catalog -> signature verification ->
     * immutable artifact -> restorable DB/files -> health-gated code switch.
     *
     * The external owner-authenticated web/controller layer MUST independently
     * establish real write+queue maintenance and authorization. Both discovery
     * and update run under the SAME private lock to prevent concurrent checks
     * from racing a code change.
     *
     * @param array<string,string> $paths Existing private update configuration.
     * @return array<string,int|string>
     */
    public function discoverAndExecute(
        PDO $liveDatabase,
        PDO $disposableRestoreDatabase,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        array $paths,
        callable $writesQuiesced,
        callable $healthCheck,
        ?TrustedReleaseCatalog $catalog = null,
        ?TrustedReleaseDownloader $downloader = null,
    ): array {
        return $this->withExclusiveUpdateLock($paths, function (callable $lockCheck) use (
            $liveDatabase, $disposableRestoreDatabase, $publisherPublicKeyBase64,
            $pinnedHttpsOrigin, $installedVersion, $paths, $writesQuiesced,
            $healthCheck, $catalog, $downloader,
        ): array {
            if ($this->guardCallback($writesQuiesced, $lockCheck)() !== true) {
                throw new DomainException('The site must be read-only before checking for trusted updates.');
            }
            $available = ($catalog ?? new TrustedReleaseCatalog())->check(
                $pinnedHttpsOrigin, $publisherPublicKeyBase64, $installedVersion,
            );
            $lockCheck();
            return $this->downloadAndExecuteUnlocked(
                $liveDatabase, $disposableRestoreDatabase,
                $available['envelope'], $publisherPublicKeyBase64,
                $pinnedHttpsOrigin, $installedVersion,
                $paths, $writesQuiesced, $healthCheck, $downloader, $lockCheck,
            );
        });
    }

    /**
     * Live-source entrypoint: publisher signed metadata -> pinned HTTPS ZIP
     * acquisition -> real recovery proof -> health-gated code pointer update.
     *
     * There is no arbitrary download URL argument, and the web-facing owner
     * authorization/maintenance implementation must be provided separately.
     *
     * @param array<string,string> $paths Same private paths as execute().
     * @param callable():bool $writesQuiesced External maintenance/queue gate.
     * @param callable():bool $healthCheck Live application health probe.
     * @return array<string,int|string>
     */
    public function downloadAndExecute(
        PDO $liveDatabase,
        PDO $disposableRestoreDatabase,
        string $signedEnvelope,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        array $paths,
        callable $writesQuiesced,
        callable $healthCheck,
        ?TrustedReleaseDownloader $downloader = null,
    ): array {
        return $this->withExclusiveUpdateLock($paths, fn (callable $lockCheck): array => $this->downloadAndExecuteUnlocked(
            $liveDatabase, $disposableRestoreDatabase, $signedEnvelope,
            $publisherPublicKeyBase64, $pinnedHttpsOrigin, $installedVersion,
            $paths, $writesQuiesced, $healthCheck,
            $downloader, $lockCheck,
        ));
    }

    private function downloadAndExecuteUnlocked(
        PDO $liveDatabase,
        PDO $disposableRestoreDatabase,
        string $signedEnvelope,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        array $paths,
        callable $writesQuiesced,
        callable $healthCheck,
        ?TrustedReleaseDownloader $downloader,
        callable $lockCheck,
    ): array {
        if ($this->guardCallback($writesQuiesced, $lockCheck)() !== true) {
            throw new DomainException('The site must be read-only before fetching or applying a release.');
        }

        $signed = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope, $publisherPublicKeyBase64,
            $pinnedHttpsOrigin, $installedVersion,
        );
        (new TrustedUpdateHostPreflight())->assertReady($paths, $signed['artifact_size']);

        $archiveName = basename((string) parse_url($signed['artifact_url'], PHP_URL_PATH));
        $incoming = realpath($paths['incoming']);
        if ($incoming === false || is_link($paths['incoming'])
            || $paths['archive'] !== $incoming.'/'.$archiveName) {
            throw new DomainException('Configured release location must match the exact signed artifact name.');
        }

        $received = ($downloader ?? new TrustedReleaseDownloader())->download(
            $signedEnvelope, $publisherPublicKeyBase64,
            $pinnedHttpsOrigin, $installedVersion,
            $paths['incoming'], $paths['public'],
        );
        $lockCheck();
        if ($received['path'] !== $paths['archive']
            || ! hash_equals($signed['artifact_sha256'], $received['artifact_sha256'])) {
            throw new DomainException('Downloaded artifact is not the approved signed release.');
        }

        // execute() rechecks the write gate, Ed25519 publisher, archive SHA,
        // complete candidate seal, isolated file + MariaDB restore, then the
        // health and rollback behavior of the atomic code pointer.
        return $this->executeUnlocked(
            $liveDatabase, $disposableRestoreDatabase, $signedEnvelope,
            $publisherPublicKeyBase64, $pinnedHttpsOrigin, $installedVersion,
            $paths, $writesQuiesced, $healthCheck, $lockCheck,
        );
    }

    /**
     * One lock from first trust check/download through restoration and health.
     * The two entrypoints MUST NOT reacquire it recursively.
     *
     * @param array<string,string> $paths
     * @param callable():array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private function withExclusiveUpdateLock(array $paths, callable $operation): array
    {
        $vault = realpath($paths['backup_vault'] ?? '');
        $public = realpath($paths['public'] ?? '');
        if ($vault === false || ! is_dir($vault) || ! is_writable($vault)
            || is_link($paths['backup_vault'] ?? '')
            || $vault !== ($paths['backup_vault'] ?? '')
            || $public === false
            || $vault === $public
            || str_starts_with($vault, rtrim($public, '/').'/')
            || str_starts_with($public, rtrim($vault, '/').'/')) {
            throw new DomainException('Trusted update lock requires an isolated writable private vault.');
        }

        $lockPath = $vault.'/.trusted-update.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new DomainException('Unsafe trusted update lock path.');
        }
        $stream = @fopen($lockPath, 'c+');
        if ($stream === false) {
            throw new DomainException('Cannot create private trusted update lock.');
        }

        try {
            $lockCheck = function () use ($stream, $lockPath): void {
                clearstatcache(true, $lockPath);
                $opened = @fstat($stream);
                $named = @lstat($lockPath);
                if (! is_array($opened) || ! is_array($named)
                    || ($opened['mode'] & 0170000) !== 0100000
                    || ($named['mode'] & 0170000) !== 0100000
                    || ($opened['nlink'] ?? 0) !== 1 || ($named['nlink'] ?? 0) !== 1
                    || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']
                    || is_link($lockPath)) {
                    throw new DomainException('Trusted update lock path or inode changed during operation.');
                }
            };
            $lockCheck();
            if (! flock($stream, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another trusted code update is already in progress.');
            }
            $lockCheck();

            $result = $operation($lockCheck);
            $lockCheck();
            return $result;
        } finally {
            @flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /** Wrap external maintenance/health callbacks with lock identity checks. */
    private function guardCallback(callable $callback, callable $lockCheck): \Closure
    {
        return static function () use ($callback, $lockCheck): bool {
            $lockCheck();
            $result = $callback();
            $lockCheck();
            return $result === true;
        };
    }

    private function checkLock(callable $lockCheck): bool
    {
        $lockCheck();
        return true;
    }

    /**
     * Hash every byte and relative name, reject linked/special/changed paths.
     * The caller is responsible for exclusive write/queue isolation.
     */
    private function seal(string $directory): string
    {
        $root = realpath($directory);
        if ($root === false || $root !== $directory || is_link($directory)) {
            throw new DomainException('Approved signed release directory is unavailable.');
        }
        $items = [];
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new DomainException('Signed candidate cannot contain filesystem links.');
            }
            $full = $item->getPathname();
            $relative = substr($full, strlen($root) + 1);
            // The seal serializes records with newlines/colons: names outside
            // the signed ZIP grammar can create ambiguous inventory records.
            if (strlen($relative) > 512
                || preg_match('#\\A(?:application|public_html|contracts)(?:/[A-Za-z0-9_.@+ -]+)*\\z#D', $relative) !== 1
                || str_contains($relative, '//')) {
                throw new DomainException('Signed candidate contains an unsafe inventory path.');
            }
            if ($item->isDir()) {
                if (realpath($full) !== $full || count($items) >= 50000) {
                    throw new DomainException('Signed candidate directory identity or count is unsafe.');
                }
                $items[] = 'd:'.$relative;
                continue;
            }
            if (! $item->isFile() || realpath($full) !== $full) {
                throw new DomainException('Signed candidate contains an unsafe entry.');
            }
            [$size, $hash] = $this->sealedFileDigest($full);
            if (($bytes += $size) > 1073741824 || count($items) >= 50000) {
                throw new DomainException('Signed candidate exceeds safe file inventory limits.');
            }
            $items[] = 'f:'.$relative.':'.$size.':'.$hash;
        }
        sort($items, SORT_STRING);
        return hash('sha256', implode("\n", $items));
    }

    /** @return array{int,string} */
    private function sealedFileDigest(string $path): array
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
            throw new DomainException('Untrusted signed candidate file identity.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Cannot securely open signed candidate file.');
        }
        try {
            $baseline = @fstat($stream);
            if (! is_array($baseline)
                || ! is_int($baseline['size']) || $baseline['size'] < 0
                || $baseline['size'] > 134217728) {
                throw new DomainException('Signed candidate file exceeds safe size limits.');
            }
            $this->assertStableCandidateFile($stream, $path, $baseline);
            $hasher = hash_init('sha256');
            // Hash the opened regular inode, never re-open a possibly swapped
            // pathname with hash_file(). Exact-length read rejects growth.
            $read = hash_update_stream($hasher, $stream, $baseline['size'] + 1);
            if ($read !== $baseline['size']) {
                throw new DomainException('Signed candidate bytes changed during attestation.');
            }
            $this->assertStableCandidateFile($stream, $path, $baseline);
            return [$baseline['size'], hash_final($hasher)];
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream @param array<string|int,mixed> $baseline */
    private function assertStableCandidateFile($stream, string $path, array $baseline): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || ($opened['nlink'] ?? 0) !== 1 || ($named['nlink'] ?? 0) !== 1
            || is_link($path) || realpath($path) !== $path) {
            throw new DomainException('Signed candidate file link or type changed during attestation.');
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (! isset($opened[$field], $named[$field], $baseline[$field])
                || $opened[$field] !== $baseline[$field]
                || $named[$field] !== $baseline[$field]) {
                throw new DomainException('Signed candidate file identity changed during attestation.');
            }
        }
    }

}

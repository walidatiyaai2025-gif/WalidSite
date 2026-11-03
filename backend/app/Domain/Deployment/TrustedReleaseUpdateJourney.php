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
        if ($writesQuiesced() !== true) {
            throw new DomainException('The site must be in verified read-only maintenance before updating.');
        }

        $candidate = (new TrustedCodeCandidatePromoter())->promote(
            $signedEnvelope, $publisherPublicKeyBase64, $pinnedHttpsOrigin,
            $installedVersion, $paths['quarantine'], $paths['incoming'],
            $paths['archive'], $paths['releases'], $paths['public'],
        );

        // Bind the approved code identity to its complete, exact on-disk file
        // inventory. Do not accept a caller-supplied "true" as signed proof.
        $seal = $this->seal($candidate['directory']);
        $zipHash = hash_file('sha256', $paths['archive']);
        if (! is_string($zipHash) || ! hash_equals($candidate['artifact_sha256'], $zipHash)) {
            throw new DomainException('Signed release ZIP changed after code promotion.');
        }

        $verified = function () use ($candidate, $paths, $seal): bool {
            try {
                $archiveHash = hash_file('sha256', $paths['archive']);
                return is_string($archiveHash)
                    && hash_equals($candidate['artifact_sha256'], $archiveHash)
                    && hash_equals($seal, $this->seal($candidate['directory']));
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
        if ($writesQuiesced() !== true) {
            throw new DomainException('The site must be read-only before fetching or applying a release.');
        }

        $signed = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope, $publisherPublicKeyBase64,
            $pinnedHttpsOrigin, $installedVersion,
        );
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
        if ($received['path'] !== $paths['archive']
            || ! hash_equals($signed['artifact_sha256'], $received['artifact_sha256'])) {
            throw new DomainException('Downloaded artifact is not the approved signed release.');
        }

        // execute() rechecks the write gate, Ed25519 publisher, archive SHA,
        // complete candidate seal, isolated file + MariaDB restore, then the
        // health and rollback behavior of the atomic code pointer.
        return $this->execute(
            $liveDatabase, $disposableRestoreDatabase, $signedEnvelope,
            $publisherPublicKeyBase64, $pinnedHttpsOrigin, $installedVersion,
            $paths, $writesQuiesced, $healthCheck,
        );
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
            if ($item->isDir()) {
                $items[] = 'd:'.$relative;
                continue;
            }
            if (! $item->isFile() || realpath($full) !== $full) {
                throw new DomainException('Signed candidate contains an unsafe entry.');
            }
            $size = filesize($full);
            $stats = stat($full);
            if ($size === false || $stats === false || ($stats['nlink'] ?? 0) !== 1
                || ($bytes += $size) > 1073741824 || count($items) > 50000) {
                throw new DomainException('Signed candidate exceeds safe file inventory limits.');
            }
            $hash = hash_file('sha256', $full);
            if (! is_string($hash)) {
                throw new DomainException('Cannot attest signed candidate bytes.');
            }
            $items[] = 'f:'.$relative.':'.$size.':'.$hash;
        }
        sort($items, SORT_STRING);
        return hash('sha256', implode("\n", $items));
    }
}

<?php

namespace App\Domain\Deployment;

use DomainException;
use ZipArchive;

/**
 * Read-only code-only cPanel upgrade compatibility proof for an ALREADY
 * publisher-authenticated, integrity-verified ZIP. Never extracts or runs PHP.
 *
 * Unlike physical host layout checks, an unchanged host can still reject a
 * signed candidate containing migrations or modified stable web entrypoints.
 * The result is informational and MUST NOT authorize a code cutover.
 */
final class CpanelArchiveCodeOnlyPreflight
{
    /**
     * @return array{state:string,migrations:int}
     */
    public function assertCompatible(
        string $installedRoot,
        string $publicDocumentRoot,
        string $verifiedZip,
        string $signedSha256,
        string $installedVersion,
        string $targetVersion,
    ): array {
        $versionPattern = '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D';
        $root = realpath($installedRoot);
        $public = realpath($publicDocumentRoot);
        $archive = realpath($verifiedZip);
        if (! preg_match($versionPattern, $installedVersion)
            || ! preg_match($versionPattern, $targetVersion)
            || version_compare($targetVersion, $installedVersion, '<=')
            || $root === false || $root !== $installedRoot || is_link($installedRoot)
            || $public === false || $public !== $publicDocumentRoot || is_link($publicDocumentRoot)
            || $archive === false || $archive !== $verifiedZip || is_link($verifiedZip)
            || ! is_file($archive) || ! is_readable($archive)
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $signedSha256)) {
            throw new DomainException('A verified immutable cPanel candidate and installed release are required.');
        }

        // After an Admin-managed upgrade, the original application is retained
        // solely as private runtime state; actual executable code lives at the
        // stable dispatcher's approved version pointer. Never compare against
        // the legacy application's Composer/migrations for a second update.
        $pointer = $root.'/.walidsite-active-release.json';
        if (file_exists($pointer) || is_link($pointer)) {
            try {
                $active = json_decode(
                    $this->readPinned($pointer, 512), true, 8, JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                throw new DomainException('Installed release pointer is malformed.');
            }
            if (! is_array($active)
                || array_keys($active) !== ['schema', 'version', 'root']
                || $active['schema'] !== 1 || $active['version'] !== $installedVersion
                || ! is_string($active['root'])
                || ! preg_match('#\\A\\.walidsite-releases/v[0-9]+\\.[0-9]+\\.[0-9]+-[a-f0-9]{40}\\z#D', $active['root'])
                || ! str_starts_with($active['root'], '.walidsite-releases/v'.$installedVersion.'-')) {
                throw new DomainException('Installed release pointer is not approved for this version.');
            }
            $versionedRoot = $root.'/'.$active['root'];
            clearstatcache(true, $versionedRoot);
            $releaseStat = @lstat($versionedRoot);
            if (! is_array($releaseStat) || is_link($versionedRoot)
                || realpath($versionedRoot) !== $versionedRoot
                || ($releaseStat['mode'] & 0170000) !== 0040000
                || ($releaseStat['mode'] & 0022) !== 0
                || is_link($versionedRoot.'/application')
                || realpath($versionedRoot.'/application') !== $versionedRoot.'/application') {
                throw new DomainException('Installed immutable release root is unsafe.');
            }
            $root = $versionedRoot;
        }

        $installedVersionPath = $root.'/application/VERSION';
        if (trim($this->readPinned($installedVersionPath, 32)) !== $installedVersion) {
            throw new DomainException('Installed code identity is incompatible with this update.');
        }

        // Hash the same open ZIP inode that passed path and permission checks.
        // An archive hardlink, unsafe mode or altered bytes fails before
        // ZipArchive inspects any executable entry (nothing is extracted).
        if (! hash_equals($signedSha256, $this->hashPinned($archive, 1073741824))) {
            throw new DomainException('Signed update ZIP bytes changed before compatibility inspection.');
        }

        // This same installed-release inventory is enforced again by the
        // physical code-pointer cutover; never use a synthetic filename list.
        $oldMigrations = (new ReleaseSchemaCompatibility())->inventory($root);
        // A code-only switch may not silently change Composer's dependency
        // graph or PHP platform requirements. Any such change needs a
        // separately tested full-runtime upgrade and recovery path.
        $installedComposer = [];
        foreach (['composer.json' => 1048576, 'composer.lock' => 8388608] as $entry => $limit) {
            $contents = $this->readPinned($root.'/application/'.$entry, $limit);
            if (! is_array(json_decode($contents, true))) {
                throw new DomainException('Installed Composer dependency metadata is invalid.');
            }
            $installedComposer[$entry] = hash('sha256', $contents);
        }
        $installedWeb = [];
        foreach (['index.php', '.htaccess'] as $entry) {
            $installedWeb[$entry] = $this->hashPinned($public.'/'.$entry, 2097152);
        }

        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('ZIP compatibility inspection requires the PHP zip extension.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new DomainException('Verified candidate ZIP cannot be opened.');
        }
        $migrations = [];
        $stableWeb = [];
        $candidateComposer = [];
        $candidateVersion = null;
        $seen = [];
        $bytes = 0;
        try {
            if ($zip->numFiles < 5 || $zip->numFiles > 20000) {
                throw new DomainException('Candidate ZIP entry inventory is unsupported.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! is_array($stat) || ! is_string($stat['name'] ?? null)
                    || ! is_int($stat['size'] ?? null)) {
                    throw new DomainException('Candidate ZIP has unreadable entries.');
                }
                $name = $stat['name'];
                $dir = str_ends_with($name, '/');
                $relevant = $name === 'application/VERSION'
                    || in_array($name, ['public_html/index.php', 'public_html/.htaccess',
                        'application/composer.json', 'application/composer.lock'], true)
                    || str_starts_with($name, 'application/database/migrations/');
                if (! $relevant) {
                    if ($name === 'application/database/migrations') {
                        throw new DomainException('Candidate migration root is not a directory.');
                    }
                    continue;
                }
                if (isset($seen[$name])) {
                    throw new DomainException('Duplicate candidate identity or migration entry.');
                }
                $seen[$name] = true;
                if (! $zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    throw new DomainException('Candidate entry type is unavailable.');
                }
                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attributes >> 16) & 0170000;
                    if ($mode !== 0 && $mode !== ($dir ? 0040000 : 0100000)) {
                        throw new DomainException('Candidate migrations cannot contain links or special files.');
                    }
                }

                if ($dir) {
                    $relativeDir = substr($name, strlen('application/database/migrations/'), -1);
                    if ($name !== 'application/database/migrations/'
                        && ! preg_match('#\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\z#D', $relativeDir)) {
                        throw new DomainException('Unsupported migration directory in candidate ZIP.');
                    }
                    if ($stat['size'] !== 0) {
                        throw new DomainException('Candidate migration directory has data.');
                    }
                    continue;
                }

                $limit = $name === 'application/composer.lock' ? 8388608
                    : ($name === 'application/composer.json' ? 1048576 : 2097152);
                if ($stat['size'] > $limit || ($bytes += $stat['size']) > 67108864) {
                    throw new DomainException('Candidate migration or entrypoint exceeds safe inspection bounds.');
                }
                $content = $zip->getFromIndex($i);
                if (! is_string($content) || strlen($content) !== $stat['size']) {
                    throw new DomainException('Candidate migration or entrypoint cannot be read completely.');
                }
                if ($name === 'application/VERSION') {
                    if ($stat['size'] > 32) {
                        throw new DomainException('Candidate version is too large.');
                    }
                    $candidateVersion = trim($content);
                } elseif (str_starts_with($name, 'application/composer.')) {
                    if (! is_array(json_decode($content, true))) {
                        throw new DomainException('Candidate Composer dependency metadata is invalid.');
                    }
                    $candidateComposer[substr($name, strlen('application/'))] = hash('sha256', $content);
                } elseif (str_starts_with($name, 'public_html/')) {
                    $stableWeb[substr($name, strlen('public_html/'))] = hash('sha256', $content);
                } else {
                    $relative = substr($name, strlen('application/database/migrations/'));
                    if (! preg_match('#\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php\z#D', $relative)
                        || count($migrations) >= 5000) {
                        throw new DomainException('Candidate includes an unsafe migration inventory.');
                    }
                    $migrations[$relative] = hash('sha256', $content);
                }
            }
        } finally {
            $zip->close();
        }

        if (! hash_equals($signedSha256, $this->hashPinned($archive, 1073741824))) {
            throw new DomainException('Candidate ZIP changed during compatibility inspection.');
        }
        if (trim($this->readPinned($installedVersionPath, 32)) !== $installedVersion) {
            throw new DomainException('Installed code identity changed during ZIP inspection.');
        }
        foreach (['composer.json' => 1048576, 'composer.lock' => 8388608] as $entry => $limit) {
            if (! hash_equals($installedComposer[$entry],
                hash('sha256', $this->readPinned($root.'/application/'.$entry, $limit)))) {
                throw new DomainException('Installed Composer metadata changed during ZIP inspection.');
            }
        }
        if ($candidateVersion !== $targetVersion || $oldMigrations !== $this->sorted($migrations)) {
            throw new DomainException('Changed database migrations require a separately verified upgrade and recovery.');
        }
        if ($candidateComposer !== $installedComposer) {
            throw new DomainException('Changed Composer dependencies require a separately verified runtime upgrade.');
        }
        foreach (['index.php', '.htaccess'] as $entry) {
            $onDisk = $this->hashPinned($public.'/'.$entry, 2097152);
            if (! isset($stableWeb[$entry])
                || ! hash_equals($installedWeb[$entry], $onDisk)
                || ! hash_equals($stableWeb[$entry], $onDisk)) {
                throw new DomainException('Candidate changes the immutable cPanel web entrypoint.');
            }
        }

        return ['state' => 'code_only_compatible', 'migrations' => count($migrations)];
    }

    /**
     * Read signed/installed identity bytes only through a held, bounded,
     * regular, singly-linked and non-group/world-writable file descriptor.
     * Pathname prechecks alone can race a file replacement after stat().
     */
    private function readPinned(string $path, int $maximum): string
    {
        return $this->withPinned($path, $maximum, static function ($stream, int $size) use ($maximum): string {
            $bytes = @stream_get_contents($stream, $maximum + 1);
            if (! is_string($bytes) || strlen($bytes) !== $size) {
                throw new DomainException('Pinned update identity changed during read.');
            }
            return $bytes;
        });
    }

    private function hashPinned(string $path, int $maximum): string
    {
        return $this->withPinned($path, $maximum, static function ($stream, int $size): string {
            $context = hash_init('sha256');
            if (hash_update_stream($context, $stream, $size + 1) !== $size) {
                throw new DomainException('Pinned update archive or installed file changed during hashing.');
            }
            return hash_final($context);
        });
    }

    /** @param callable(resource,int):string $reader */
    private function withPinned(string $path, int $maximum, callable $reader): string
    {
        clearstatcache(true, $path);
        $namedBeforeOpen = @lstat($path);
        // Do not open a FIFO or device before checking its type: a blocking
        // fopen() of a malicious named pipe could hang the owner preflight.
        if (! is_array($namedBeforeOpen)
            || ($namedBeforeOpen['mode'] & 0170000) !== 0100000
            || ($namedBeforeOpen['nlink'] ?? 0) !== 1
            || ($namedBeforeOpen['mode'] & 0022) !== 0
            || ! is_int($namedBeforeOpen['size'])
            || $namedBeforeOpen['size'] < 1 || $namedBeforeOpen['size'] > $maximum
            || is_link($path) || realpath($path) !== $path) {
            throw new DomainException('Untrusted update file pathname or type.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Update identity file is unavailable.');
        }
        try {
            $original = @fstat($stream);
            if (! is_array($original)
                || ($original['mode'] & 0170000) !== 0100000
                || ($original['nlink'] ?? 0) !== 1
                || ($original['mode'] & 0022) !== 0
                || ! is_int($original['size']) || $original['size'] < 1
                || $original['size'] > $maximum) {
                throw new DomainException('Update identity file has an unsafe inode, mode or size.');
            }
            $this->assertPinned($stream, $path, $original);
            $value = $reader($stream, $original['size']);
            $this->assertPinned($stream, $path, $original);
            return $value;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream
     * @param array<string|int,mixed> $original
     */
    private function assertPinned($stream, string $path, array $original): void
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = @fstat($stream);
        if (! is_array($named) || ! is_array($opened)
            || is_link($path) || realpath($path) !== $path
            || ($named['mode'] & 0170000) !== 0100000
            || ($named['nlink'] ?? 0) !== 1) {
            throw new DomainException('Update identity path changed while pinned.');
        }
        foreach (['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'] as $field) {
            if (! isset($named[$field], $opened[$field], $original[$field])
                || $named[$field] !== $original[$field]
                || $opened[$field] !== $original[$field]) {
                throw new DomainException('Update identity inode changed while pinned.');
            }
        }
    }

    /**
     * @param array<string,string> $inventory
     * @return array<string,string>
     */
    private function sorted(array $inventory): array
    {
        ksort($inventory, SORT_STRING);

        return $inventory;
    }
}

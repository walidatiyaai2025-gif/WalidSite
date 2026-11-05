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

        $installedVersionPath = $root.'/application/VERSION';
        if (! is_file($installedVersionPath) || is_link($installedVersionPath)
            || filesize($installedVersionPath) === false
            || filesize($installedVersionPath) > 32
            || trim((string) file_get_contents($installedVersionPath)) !== $installedVersion) {
            throw new DomainException('Installed code identity is incompatible with this update.');
        }

        $digest = hash_file('sha256', $archive);
        if (! is_string($digest) || ! hash_equals($signedSha256, $digest)) {
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
            $path = $root.'/application/'.$entry;
            $size = @filesize($path);
            $stat = @stat($path);
            if (! is_file($path) || is_link($path) || realpath($path) !== $path
                || ! is_readable($path) || $size === false || $size < 1
                || $size > $limit || $stat === false || ($stat['nlink'] ?? 0) !== 1) {
                throw new DomainException('Installed Composer dependency identity is unsafe.');
            }
            $contents = @file_get_contents($path);
            if (! is_string($contents) || strlen($contents) !== $size
                || ! is_array(json_decode($contents, true))) {
                throw new DomainException('Installed Composer dependency metadata is invalid.');
            }
            $installedComposer[$entry] = hash('sha256', $contents);
        }
        foreach (['index.php', '.htaccess'] as $entry) {
            if (! is_file($public.'/'.$entry) || is_link($public.'/'.$entry)) {
                throw new DomainException('Stable installed web entrypoint is unavailable.');
            }
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

        $after = hash_file('sha256', $archive);
        if (! is_string($after) || ! hash_equals($signedSha256, $after)) {
            throw new DomainException('Candidate ZIP changed during compatibility inspection.');
        }
        if ($candidateVersion !== $targetVersion || $oldMigrations !== $this->sorted($migrations)) {
            throw new DomainException('Changed database migrations require a separately verified upgrade and recovery.');
        }
        if ($candidateComposer !== $installedComposer) {
            throw new DomainException('Changed Composer dependencies require a separately verified runtime upgrade.');
        }
        foreach (['index.php', '.htaccess'] as $entry) {
            $onDisk = hash_file('sha256', $public.'/'.$entry);
            if (! isset($stableWeb[$entry]) || ! is_string($onDisk)
                || ! hash_equals($stableWeb[$entry], $onDisk)) {
                throw new DomainException('Candidate changes the immutable cPanel web entrypoint.');
            }
        }

        return ['state' => 'code_only_compatible', 'migrations' => count($migrations)];
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

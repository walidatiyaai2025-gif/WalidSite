<?php

namespace App\Domain\Deployment;

use DomainException;
use ZipArchive;

/**
 * Inspection-only gate for a publisher-verified complete cPanel code release.
 * It never extracts files, switches code, runs PHP, or changes the database.
 */
final class TrustedReleaseArchive
{
    private const MAX_FILES = 20000;
    private const MAX_FILE_BYTES = 134217728; // 128 MiB
    private const MAX_TOTAL_BYTES = 1073741824; // 1 GiB
    private const MAX_RATIO = 200;

    /** @var list<string> */
    private const REQUIRED = [
        'application/bootstrap/app.php',
        'application/vendor/autoload.php',
        'public_html/index.php',
        'public_html/.htaccess',
    ];

    /**
     * @param array{artifact_url:string,artifact_size:int,artifact_sha256:string} $signedRelease
     * @return array{files:int,uncompressed_bytes:int}
     */
    public function inspect(
        array $signedRelease,
        string $privateStagingDirectory,
        string $archiveFile,
    ): array {
        // A valid ZIP structure cannot replace the publisher-signed digest.
        (new TrustedReleaseMetadata())->verifyStagedArchive(
            $signedRelease, $privateStagingDirectory, $archiveFile,
        );

        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('ZIP validation is not available on this host.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archiveFile, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new DomainException('Release archive is damaged or unreadable.');
        }

        try {
            if ($zip->numFiles < count(self::REQUIRED) || $zip->numFiles > self::MAX_FILES) {
                throw new DomainException('Release archive entry count is unsafe.');
            }

            $seen = [];
            $files = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || ! isset($stat['name'], $stat['size'], $stat['comp_size'])
                    || ! is_string($stat['name'])) {
                    throw new DomainException('Unreadable release archive entry.');
                }

                $name = $stat['name'];
                $directory = str_ends_with($name, '/');
                $normalized = $directory ? substr($name, 0, -1) : $name;

                if ($normalized === '' || strlen($name) > 512
                    || str_contains($name, '\\') || str_contains($name, "\0")
                    || str_starts_with($name, '/') || str_contains($name, '//')
                    || preg_match('/^[a-zA-Z]:/', $name)
                    || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $normalized)
                    || ! preg_match('#^(?:application|public_html|contracts)(?:/[A-Za-z0-9_.@+ -]+)*$#D', $normalized)
                    || isset($seen[$normalized])) {
                    throw new DomainException('Unsafe or duplicate release archive path.');
                }

                $seen[$normalized] = true;
                $parts = explode('/', $normalized);
                foreach ($parts as $part) {
                    if ($part === '' || $part[0] === '.' && $part !== '.htaccess') {
                        throw new DomainException('Hidden release archive entries are forbidden.');
                    }
                }

                // Never install environment secrets or user-generated private files.
                if (preg_match('#(?:^|/)(?:\.env(?:\..*)?|site-installed\.lock|site-first-run\.json|site-setup-token|id_rsa|id_ed25519)$#iD', $normalized)
                    || str_starts_with($normalized, 'application/storage/')
                    || str_starts_with($normalized, 'public_html/storage/')
                    || str_starts_with($normalized, 'public_html/uploads/')) {
                    throw new DomainException('Release archive contains preserved runtime data.');
                }

                $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr);
                if ($attributes === false) {
                    throw new DomainException('Unreadable archive entry attributes.');
                }
                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $mode = ($attr >> 16) & 0170000;
                    if ($mode !== 0 && $mode !== ($directory ? 0040000 : 0100000)) {
                        throw new DomainException('Archive contains a link or special file.');
                    }
                }

                if ($directory) {
                    if ($stat['size'] !== 0) {
                        throw new DomainException('Invalid release directory entry.');
                    }
                    continue;
                }

                if ($stat['size'] > self::MAX_FILE_BYTES
                    || $stat['comp_size'] < 0
                    || ($stat['comp_size'] === 0 && $stat['size'] !== 0)
                    || ($stat['size'] > 1048576
                        && $stat['size'] > $stat['comp_size'] * self::MAX_RATIO)) {
                    throw new DomainException('Release archive entry exceeds safety budget.');
                }

                $total += $stat['size'];
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new DomainException('Release archive exceeds expansion budget.');
                }
                $files[$normalized] = true;
            }

            foreach (self::REQUIRED as $required) {
                if (! isset($files[$required])) {
                    throw new DomainException('Release archive is not a complete application package.');
                }
            }

            return ['files' => count($files), 'uncompressed_bytes' => $total];
        } finally {
            $zip->close();
        }
    }
}

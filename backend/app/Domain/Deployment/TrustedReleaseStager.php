<?php

namespace App\Domain\Deployment;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Materializes verified release code in a private quarantine, never in the
 * running application or public document root. No executable is invoked.
 */
final class TrustedReleaseStager
{
    private const MAX_ENTRY_BYTES = 134217728; // 128 MiB
    private const MAX_TOTAL_BYTES = 1073741824; // 1 GiB

    /**
     * @param array{artifact_url:string,artifact_size:int,artifact_sha256:string} $signedRelease
     * @return array{directory:string,files:int,bytes:int}
     */
    public function stage(
        array $signedRelease,
        string $archiveDirectory,
        string $archiveFile,
        string $privateRoot,
        string $publicDocumentRoot,
    ): array {
        $private = realpath($privateRoot);
        $public = realpath($publicDocumentRoot);
        $archive = realpath($archiveDirectory);
        if ($private === false || $public === false || $archive === false
            || ! is_dir($private) || ! is_dir($public)
            || is_link($privateRoot) || is_link($archiveDirectory)
            || $private === $public || $this->isWithin($private, $public) || $this->isWithin($public, $private)
            || ! $this->isWithin($archive, $private) || $this->isWithin($archive, $public)
            || ! is_writable($private)) {
            throw new DomainException('A separate writable private release root is required.');
        }

        // This also checks the signed digest, filesize and structural ZIP safety.
        $inventory = (new TrustedReleaseArchive())->inspect(
            $signedRelease, $archiveDirectory, $archiveFile,
        );
        $base = $private.'/release-staging';
        if (is_link($base) || (file_exists($base) && ! is_dir($base))) {
            throw new DomainException('Private release staging base is unsafe.');
        }
        if (! is_dir($base) && ! mkdir($base, 0700) && ! is_dir($base)) {
            throw new DomainException('Cannot create private release staging base.');
        }

        $stage = $base.'/candidate-'.bin2hex(random_bytes(16));
        if (! mkdir($stage, 0700)) {
            throw new RuntimeException('Cannot create isolated release candidate.');
        }

        $snapshotDirectory = $base.'/verified-archive-'.bin2hex(random_bytes(16));
        try {
            if (! mkdir($snapshotDirectory, 0700)) {
                throw new RuntimeException('Cannot create private archive snapshot.');
            }
            $snapshotFile = $snapshotDirectory.'/'.basename($archiveFile);
            // ZipArchive accepts only a pathname, not a pinned descriptor.
            // First freeze a bounded private copy, then verify the signed
            // SHA-256 and archive structure of that *exact* copy.
            $this->freezeArchive($archiveFile, $snapshotFile, $signedRelease['artifact_size']);
            $frozenInventory = (new TrustedReleaseArchive())->inspect(
                $signedRelease, $snapshotDirectory, $snapshotFile,
            );
            if ($frozenInventory !== $inventory) {
                throw new DomainException('Frozen release inventory changed.');
            }
            $zip = new ZipArchive();
            if ($zip->open($snapshotFile, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new DomainException('Verified private ZIP could not be opened.');
            }

            try {
                $bytes = 0;
                $files = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (! is_string($name)) {
                        throw new DomainException('Archive entry disappeared during staging.');
                    }
                    $destination = $stage.'/'.$name;
                    if (str_ends_with($name, '/')) {
                        if (! is_dir($destination) && ! mkdir($destination, 0700, true)) {
                            throw new RuntimeException('Cannot stage release directory.');
                        }
                        continue;
                    }

                    $parent = dirname($destination);
                    if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                        throw new RuntimeException('Cannot stage release directory.');
                    }
                    if (file_exists($destination) || is_link($destination)) {
                        throw new DomainException('Duplicate staged archive destination.');
                    }

                    $source = $zip->getStream($name);
                    if ($source === false) {
                        throw new DomainException('Cannot decode signed release entry.');
                    }
                    $output = @fopen($destination, 'xb');
                    if ($output === false) {
                        fclose($source);
                        throw new RuntimeException('Cannot write private release entry.');
                    }

                    $entryBytes = 0;
                    try {
                        while (! feof($source)) {
                            $chunk = fread($source, 65536);
                            if ($chunk === false || ($chunk === '' && ! feof($source))) {
                                throw new DomainException('Failed reading release ZIP stream.');
                            }
                            $size = strlen($chunk);
                            $entryBytes += $size;
                            $bytes += $size;
                            if ($entryBytes > self::MAX_ENTRY_BYTES || $bytes > self::MAX_TOTAL_BYTES) {
                                throw new DomainException('Archive extraction exceeded safety limits.');
                            }

                            $offset = 0;
                            while ($offset < $size) {
                                $written = fwrite($output, substr($chunk, $offset));
                                if ($written === false || $written === 0) {
                                    throw new RuntimeException('Cannot finish private release write.');
                                }
                                $offset += $written;
                            }
                        }
                    } finally {
                        fclose($source);
                        fclose($output);
                    }
                    if (! chmod($destination, 0600)) {
                        throw new RuntimeException('Cannot restrict staged release file permissions.');
                    }
                    $files++;
                }
            } finally {
                $zip->close();
            }

            // Reverify the exact private archive used by ZipArchive,
            // not the mutable incoming path. Never stage bytes from an
            // archive different from the publisher-signed digest.
            (new TrustedReleaseMetadata())->verifyStagedArchive(
                $signedRelease, $snapshotDirectory, $snapshotFile,
            );
            if ($files !== $inventory['files'] || $bytes !== $inventory['uncompressed_bytes']) {
                throw new DomainException('Staged files do not match verified ZIP inventory.');
            }

            return ['directory' => $stage, 'files' => $files, 'bytes' => $bytes];
        } catch (Throwable $failure) {
            $this->eraseCandidate($stage);
            throw $failure;
        } finally {
            // Archive snapshots are never part of the staged release tree.
            // Preserve no executable duplicate or untrusted temporary ZIP.
            if (is_dir($snapshotDirectory)) {
                $this->eraseCandidate($snapshotDirectory);
            }
        }
    }

    private function freezeArchive(string $incoming, string $snapshot, int $signedSize): void
    {
        $source = @fopen($incoming, 'rb');
        if ($source === false) {
            throw new DomainException('Signed archive cannot be opened for freezing.');
        }
        try {
            $target = @fopen($snapshot, 'xb');
            if ($target === false) {
                throw new DomainException('Private signed archive snapshot cannot be created.');
            }
            try {
                $copied = @stream_copy_to_stream($source, $target, $signedSize + 1);
                if ($copied !== $signedSize || ! fflush($target)) {
                    throw new DomainException('Signed archive changed during snapshot.');
                }
            } finally {
                fclose($target);
            }
        } finally {
            fclose($source);
        }
        if (! chmod($snapshot, 0600)) {
            throw new DomainException('Private archive snapshot permissions are unsafe.');
        }
    }

    private function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, '/').'/');
    }

    private function eraseCandidate(string $stage): void
    {
        if (! is_dir($stage)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($stage);
    }
}

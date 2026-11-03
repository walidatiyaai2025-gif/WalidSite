<?php

namespace App\Domain\Deployment;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Full-byte, private file recovery checkpoint for an application update.
 *
 * Deliberately NOT a MariaDB backup or authorization to update. The release
 * orchestrator must also prove a real database backup AND isolated restore.
 */
final class PrivateRecoverySnapshot
{
    private const MAX_FILES = 50000;
    private const MAX_TOTAL_BYTES = 2147483648; // 2 GiB. Larger sites fail closed.

    /**
     * @return array{directory:string,files:int,bytes:int,manifest_sha256:string}
     */
    public function create(
        string $environmentFile,
        string $privateUserData,
        string $publicUploads,
        string $privateBackupRoot,
    ): array {
        $root = realpath($privateBackupRoot);
        $sources = [
            'environment' => realpath($environmentFile),
            'private-data' => realpath($privateUserData),
            'uploads' => realpath($publicUploads),
        ];

        if ($root === false || ! is_dir($root) || is_link($privateBackupRoot)
            || ! is_writable($root) || $sources['environment'] === false
            || ! is_file($environmentFile) || is_link($environmentFile)
            || $sources['private-data'] === false || ! is_dir($privateUserData) || is_link($privateUserData)
            || $sources['uploads'] === false || ! is_dir($publicUploads) || is_link($publicUploads)) {
            throw new DomainException('Recovery paths must be existing non-linked private sources.');
        }

        // The uploads parent is treated as a public document root. A backup
        // placed beside uploads under public_html would disclose .env bytes.
        $publicRoot = realpath(dirname($publicUploads));
        if ($publicRoot === false || $publicRoot === $root
            || $this->within($root, $publicRoot) || $this->within($publicRoot, $root)) {
            throw new DomainException('Recovery backup must be outside the public document root.');
        }

        foreach ($sources as $source) {
            if ($source === $root || $this->within($source, $root) || $this->within($root, $source)) {
                throw new DomainException('Recovery backup cannot overlap a source.');
            }
        }
        if ($this->within($sources['private-data'], $sources['uploads'])
            || $this->within($sources['uploads'], $sources['private-data'])) {
            throw new DomainException('Recovery data roots must be disjoint.');
        }

        $name = 'recovery-'.bin2hex(random_bytes(16));
        $working = $root.'/.'.$name.'.partial';
        $completed = $root.'/'.$name;
        if (! mkdir($working, 0700)) {
            throw new RuntimeException('Cannot create private recovery snapshot.');
        }

        try {
            $manifest = [];
            $total = 0;
            $count = 0;

            // Environment config is deliberately preserved verbatim, not logged.
            $this->copyVerified($sources['environment'], $working.'/environment/.env', 'environment/.env',
                $manifest, $total, $count);

            foreach (['private-data', 'uploads'] as $key) {
                if (! mkdir($working.'/'.$key, 0700)) {
                    throw new RuntimeException('Cannot create private backup data directory.');
                }
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sources[$key], FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST,
                );
                foreach ($iterator as $entry) {
                    if ($entry->isLink()) {
                        throw new DomainException('Linked user data cannot be backed up.');
                    }
                    $relative = substr($entry->getPathname(), strlen($sources[$key]) + 1);
                    $segments = explode(DIRECTORY_SEPARATOR, $relative);
                    foreach ($segments as $segment) {
                        if ($segment === '' || $segment === '.' || $segment === '..') {
                            throw new DomainException('Unsafe recovery source path.');
                        }
                    }
                    $destination = $working.'/'.$key.'/'.$relative;
                    if ($entry->isDir()) {
                        if (! mkdir($destination, 0700) && ! is_dir($destination)) {
                            throw new RuntimeException('Cannot copy private backup directory.');
                        }
                        continue;
                    }
                    if (! $entry->isFile()) {
                        throw new DomainException('Special device or unsupported user data encountered.');
                    }
                    $parent = dirname($destination);
                    if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
                        throw new RuntimeException('Cannot prepare backup directory.');
                    }
                    $this->copyVerified($entry->getPathname(), $destination, $key.'/'.$relative,
                        $manifest, $total, $count);
                }
            }
            ksort($manifest, SORT_STRING);
            $json = json_encode(['schema' => 1, 'files' => $manifest],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($working.'/manifest.json', $json, LOCK_EX) !== strlen($json)
                || ! chmod($working.'/manifest.json', 0600)) {
                throw new RuntimeException('Recovery inventory could not be made private.');
            }
            if (! rename($working, $completed)) {
                throw new RuntimeException('Cannot commit private recovery snapshot.');
            }
            return [
                'directory' => $completed,
                'files' => $count,
                'bytes' => $total,
                'manifest_sha256' => hash('sha256', $json),
            ];
        } catch (Throwable $error) {
            $this->erase($working);
            throw $error;
        }
    }

    /**
     * Verifies a backed-up snapshot by actually restoring its content into a
     * fresh, isolated directory; never touches the original application.
     *
     * @return array{files:int,bytes:int}
     */
    public function drill(string $snapshotDirectory, string $expectedManifestSha256, string $disposableRoot): array
    {
        $snapshot = realpath($snapshotDirectory);
        $root = realpath($disposableRoot);
        if ($snapshot === false || $root === false || ! is_dir($snapshot) || ! is_dir($root)
            || is_link($snapshotDirectory) || is_link($disposableRoot)
            || $snapshot === $root || $this->within($root, $snapshot)
            || $this->within($snapshot, $root) || ! is_writable($root)
            || ! preg_match('/^[a-f0-9]{64}$/D', $expectedManifestSha256)) {
            throw new DomainException('Recovery drill requires a separate disposable destination.');
        }

        $inventory = $snapshot.'/manifest.json';
        if (! is_file($inventory) || is_link($inventory)) {
            throw new DomainException('Recovery inventory missing or linked.');
        }
        $json = file_get_contents($inventory);
        if ($json === false || ! hash_equals($expectedManifestSha256, hash('sha256', $json))) {
            throw new DomainException('Recovery inventory integrity check failed.');
        }
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_keys($decoded) !== ['schema', 'files']
            || $decoded['schema'] !== 1 || ! is_array($decoded['files'])) {
            throw new DomainException('Unrecognized recovery inventory.');
        }
        $target = $root.'/restored-'.bin2hex(random_bytes(16));
        if (! mkdir($target, 0700)) {
            throw new RuntimeException('Cannot create disposable recovery target.');
        }
        try {
            $bytes = 0;
            $files = 0;
            foreach ($decoded['files'] as $name => $expected) {
                if (! is_string($name) || ! preg_match('#^(?:environment|private-data|uploads)/(?:[A-Za-z0-9_.@+ -]+/)*[A-Za-z0-9_.@+ -]+$#D', $name)
                    || ! is_array($expected) || array_keys($expected) !== ['bytes', 'sha256']
                    || ! is_int($expected['bytes']) || $expected['bytes'] < 0
                    || ! is_string($expected['sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $expected['sha256'])) {
                    throw new DomainException('Unsafe recovery inventory entry.');
                }
                $origin = $snapshot.'/'.$name;
                if (! is_file($origin) || is_link($origin)
                    || realpath($origin) !== $origin
                    || filesize($origin) !== $expected['bytes']
                    || ! hash_equals($expected['sha256'], hash_file('sha256', $origin))) {
                    throw new DomainException('Recovery snapshot data is corrupt.');
                }
                $destination = $target.'/'.$name;
                if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
                    throw new RuntimeException('Cannot create recovery drill directory.');
                }
                if (! copy($origin, $destination) || ! chmod($destination, 0600)
                    || ! hash_equals($expected['sha256'], hash_file('sha256', $destination))) {
                    throw new RuntimeException('Recovery restore validation failed.');
                }
                $bytes += $expected['bytes'];
                $files++;
                if ($files > self::MAX_FILES || $bytes > self::MAX_TOTAL_BYTES) {
                    throw new DomainException('Recovery inventory exceeds safety budget.');
                }
            }
            // Retain the restored fixture for explicit inspection/cleanup.
            return ['files' => $files, 'bytes' => $bytes];
        } catch (Throwable $error) {
            $this->erase($target);
            throw $error;
        }
    }

    /** @param array<string,array{bytes:int,sha256:string}> $manifest */
    private function copyVerified(
        string $source, string $destination, string $name, array &$manifest, int &$total, int &$count,
    ): void {
        if (is_link($source) || ! is_file($source) || ! is_readable($source)) {
            throw new DomainException('Recovery source is not a regular readable file.');
        }
        $status = stat($source);
        if ($status === false || ($status['nlink'] ?? 0) !== 1) {
            throw new DomainException('Hard-linked private recovery inputs are forbidden.');
        }
        $size = filesize($source);
        if ($size === false || $size < 0 || ++$count > self::MAX_FILES
            || ($total += $size) > self::MAX_TOTAL_BYTES) {
            throw new DomainException('Recovery snapshot exceeds configured storage safety budget.');
        }
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
            throw new RuntimeException('Cannot create recovery directory.');
        }
        if (! copy($source, $destination) || ! chmod($destination, 0600)) {
            throw new RuntimeException('Cannot snapshot private file.');
        }
        $digest = hash_file('sha256', $source);
        if ($digest === false || ! hash_equals($digest, hash_file('sha256', $destination))
            || filesize($destination) !== $size) {
            throw new DomainException('Backup source changed or snapshot is incomplete.');
        }
        $manifest[$name] = ['bytes' => $size, 'sha256' => $digest];
    }

    private function within(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function erase(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}

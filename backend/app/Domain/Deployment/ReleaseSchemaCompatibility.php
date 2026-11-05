<?php

namespace App\Domain\Deployment;

use DomainException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Fail-closed compatibility gate for code-only updates.
 *
 * An atomic code switch is NOT a database migration or a reversible schema
 * upgrade. Until live migration + verified database rollback are integrated,
 * require identical Laravel migration scripts in old and new releases.
 */
final class ReleaseSchemaCompatibility
{
    /** @return array<string,string> */
    public function inventory(string $releaseDirectory): array
    {
        $root = realpath($releaseDirectory);
        if ($root === false || ! is_dir($root) || is_link($releaseDirectory)
            || $root !== $releaseDirectory) {
            throw new DomainException('Schema compatibility needs a real immutable release directory.');
        }

        $relativeRoot = '/application/database/migrations';
        $folder = $root.$relativeRoot;
        // Never follow symlinked ancestors even if the migration folder is
        // missing: a release cannot smuggle its database tree elsewhere.
        foreach (['application', 'application/database', 'application/database/migrations'] as $segment) {
            if (is_link($root.'/'.$segment)) {
                throw new DomainException('Linked migration ancestry is unsafe.');
            }
        }
        if (! file_exists($folder)) {
            return [];
        }
        if (! is_dir($folder) || realpath($folder) !== $folder) {
            throw new DomainException('Migration root is not a real directory.');
        }

        $items = [];
        $count = 0;
        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new DomainException('Migration files must never be symbolic links.');
            }
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                if (realpath($path) !== $path) {
                    throw new DomainException('Migration directory escaped its root.');
                }
                continue;
            }
            if (! $entry->isFile() || realpath($path) !== $path
                || ! str_ends_with($path, '.php')) {
                throw new DomainException('Unexpected migration data or unsupported file type.');
            }
            $relative = substr($path, strlen($folder) + 1);
            if (! preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$#D', $relative)) {
                throw new DomainException('Unsafe migration filename.');
            }
            // Hash the same opened regular inode that passes size/link/path
            // validation. Opening the pathname again for hash_file() admits
            // a race against rename, replacement and hard-link aliases.
            [$size, $hash] = $this->migrationDigest($path);
            if (++$count > 5000 || ($total += $size) > 67108864) {
                throw new DomainException('Migration inventory exceeds supported security limits.');
            }
            $items[$relative] = $hash;
        }
        ksort($items, SORT_STRING);

        return $items;
    }

    /** @return array{int,string} */
    private function migrationDigest(string $path): array
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path) {
            throw new DomainException('Untrusted migration file identity.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new DomainException('Unable to open migration for verification.');
        }

        try {
            $before = @fstat($handle);
            if (! is_array($before)) {
                throw new DomainException('Unable to identify migration inode.');
            }
            $this->assertStableMigration($handle, $path, $before);
            $size = $before['size'];
            if (! is_int($size) || $size < 0 || $size > 67108864) {
                throw new DomainException('Migration file exceeds supported security limits.');
            }
            $hasher = hash_init('sha256');
            // Bound a single file even before the aggregate 64-MiB budget.
            $read = hash_update_stream($hasher, $handle, $size + 1);
            if ($read !== $size) {
                throw new DomainException('Migration changed while hashing.');
            }
            $this->assertStableMigration($handle, $path, $before);
            return [$size, hash_final($hasher)];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<string|int,mixed> $before
     */
    private function assertStableMigration($handle, string $path, array $before): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($handle);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || is_link($path) || realpath($path) !== $path
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']) {
            throw new DomainException('Migration pathname or inode changed during compatibility inspection.');
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($opened[$field] !== $before[$field] || $named[$field] !== $before[$field]) {
                throw new DomainException('Migration metadata changed during compatibility inspection.');
            }
        }
    }

    /** @return array{previous:array<string,string>,candidate:array<string,string>} */
    public function assertCompatible(string $currentPointer, string $candidateDirectory): array
    {
        if (! is_link($currentPointer)) {
            throw new DomainException('An immutable current release pointer is required.');
        }
        $previous = realpath($currentPointer);
        if ($previous === false) {
            throw new DomainException('Previously installed code is not available.');
        }
        $old = $this->inventory($previous);
        $next = $this->inventory($candidateDirectory);
        if ($old !== $next) {
            throw new DomainException('A database migration change needs a proven schema migration and rollback plan.');
        }

        return ['previous' => $old, 'candidate' => $next];
    }
}

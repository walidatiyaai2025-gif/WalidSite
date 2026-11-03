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
            $size = filesize($path);
            $stat = stat($path);
            if ($size === false || $stat === false || ($stat['nlink'] ?? 0) !== 1
                || ++$count > 5000 || ($total += $size) > 67108864) {
                throw new DomainException('Migration inventory exceeds supported security limits.');
            }
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new DomainException('Unable to verify migration bytes.');
            }
            $items[$relative] = $hash;
        }
        ksort($items, SORT_STRING);

        return $items;
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

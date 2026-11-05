<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;
use Throwable;

/**
 * POSIX-only code pointer cutover. Intentionally not wired to any HTTP route:
 * orchestration must independently attest signed code, DB+file backup and restore.
 */
final class AtomicReleaseSwitcher
{
    /**
     * @param callable():bool $verifiedBackup Real recoverable DB+private files gate, not just a backup filename.
     * @param callable():bool $healthCheck Post-switch application smoke, throws/false on failure.
     * @return array{state:string,previous:string,current:string}
     */
    public function switch(
        string $releaseRoot,
        string $currentLink,
        string $publicDocumentRoot,
        string $candidateDirectory,
        callable $verifiedBackup,
        callable $healthCheck,
    ): array {
        $root = realpath($releaseRoot);
        $candidate = realpath($candidateDirectory);
        if ($root === false || ! is_dir($root) || is_link($releaseRoot)
            || $candidate === false || ! is_dir($candidate) || is_link($candidateDirectory)
            || dirname($currentLink) !== dirname($releaseRoot)
            || basename($currentLink) !== 'current'
            || ! is_link($currentLink) || ! is_link($publicDocumentRoot)
            || readlink($publicDocumentRoot) !== $currentLink.'/public_html'
            || ! $this->under($candidate, $root)
            || basename($candidate)[0] === '.'
            || ! is_writable(dirname($currentLink)) || ! is_writable($root)) {
            throw new DomainException('Host lacks the approved atomic current/public_html symlink layout.');
        }

        $lockFile = $root.'/.release-switch.lock';
        if (is_link($lockFile)) {
            throw new DomainException('Release lock is not a regular file.');
        }
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new DomainException('Cannot acquire private release lock.');
        }
        try {
            $this->assertSecureLockIdentity($lock, $lockFile);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another code update is running.');
            }
            // Recheck the actual inode after exclusive locking: a pre-open
            // is_link() check alone cannot prevent a swapped or aliased lock.
            $this->assertSecureLockIdentity($lock, $lockFile);
            $previous = realpath($currentLink);
            if ($previous === false || ! is_dir($previous) || ! $this->under($previous, $root)
                || $previous === $candidate || ! $this->complete($candidate) || ! $this->complete($previous)
                || realpath($publicDocumentRoot) !== $previous.'/public_html') {
                throw new DomainException('Unverified or incompatible immutable release topology.');
            }
            // Orchestrator supplies a restore-tested backup verifier. Without it
            // there is NO filesystem pointer mutation at all.
            if ($verifiedBackup() !== true) {
                throw new DomainException('Code switch requires an independently verified recovery backup.');
            }
            // Verify current still points to the same release immediately before swap.
            if (realpath($currentLink) !== $previous) {
                throw new DomainException('Concurrent release change detected.');
            }

            $this->replacePointer($currentLink, $candidate);
            try {
                if (realpath($currentLink) !== $candidate || $healthCheck() !== true) {
                    throw new RuntimeException('New release failed application health verification.');
                }
            } catch (Throwable $problem) {
                try {
                    $this->replacePointer($currentLink, $previous);
                    if (realpath($currentLink) !== $previous) {
                        throw new RuntimeException('Rollback pointer verification failed.');
                    }
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException('Code rollback failed; recovery must be performed manually.', 0, $rollbackError);
                }
                throw new RuntimeException('Code health check failed; previous release restored.', 0, $problem);
            }
            return ['state' => 'switched', 'previous' => $previous, 'current' => $candidate];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function replacePointer(string $link, string $target): void
    {
        $temporary = dirname($link).'/.current-next-'.bin2hex(random_bytes(16));
        if (! @symlink($target, $temporary)) {
            throw new RuntimeException('Host does not support secure symlink code cutover.');
        }
        try {
            if (! @rename($temporary, $link)) {
                throw new RuntimeException('Host cannot atomically replace the release pointer.');
            }
        } finally {
            if (is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * The private lock must be a unique regular inode, identical to the one
     * at the lock path. No hard links, symlinks or pre-open path swaps.
     *
     * @param resource $lock
     */
    private function assertSecureLockIdentity($lock, string $path): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($lock);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || $opened['dev'] !== $named['dev']
            || $opened['ino'] !== $named['ino']
            || is_link($path)) {
            throw new DomainException('Unsafe atomic release lock identity; no code switch was performed.');
        }
    }

    private function complete(string $release): bool
    {
        foreach (['application/bootstrap/app.php', 'application/vendor/autoload.php', 'public_html/index.php', 'public_html/.htaccess'] as $path) {
            $full = $release.'/'.$path;
            if (! is_file($full) || ! is_readable($full)) {
                return false;
            }
            $relative = '';
            foreach (explode('/', $path) as $segment) {
                $relative .= '/'.$segment;
                if (is_link($release.$relative)) {
                    return false;
                }
            }
            if (realpath($full) !== $full) {
                return false;
            }
        }
        return true;
    }

    private function under(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}

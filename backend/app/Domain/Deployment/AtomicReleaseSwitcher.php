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
        $rootStat = $root === false ? false : @lstat($root);
        $candidate = realpath($candidateDirectory);
        if ($root === false || ! is_dir($root) || is_link($releaseRoot)
            || ! is_array($rootStat)
            || ($rootStat['mode'] & 0170000) !== 0040000
            || ($rootStat['mode'] & 0022) !== 0
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
            // The stable releases directory is part of the code pointer's
            // trust boundary. Locking a safe inode is insufficient when an
            // external callback changes the directory holding that inode.
            $leaseCheck = function () use ($root, $rootStat, $lock, $lockFile): void {
                clearstatcache(true, $root);
                $currentRoot = @lstat($root);
                if (! is_array($currentRoot) || is_link($root)
                    || realpath($root) !== $root
                    || ($currentRoot['mode'] & 0170000) !== 0040000
                    || ($currentRoot['mode'] & 0022) !== 0
                    || $currentRoot['dev'] !== $rootStat['dev']
                    || $currentRoot['ino'] !== $rootStat['ino']
                    || $currentRoot['mode'] !== $rootStat['mode']) {
                    throw new DomainException('Release root identity or permissions changed; manual verification required.');
                }
                $this->assertSecureLockIdentity($lock, $lockFile);
            };
            $leaseCheck();
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another code update is running.');
            }
            // Recheck the actual inode after exclusive locking: a pre-open
            // is_link() check alone cannot prevent a swapped or aliased lock.
            $leaseCheck();
            $previous = realpath($currentLink);
            if ($previous === false || ! is_dir($previous) || ! $this->under($previous, $root)
                || $previous === $candidate || ! $this->complete($candidate) || ! $this->complete($previous)
                || realpath($publicDocumentRoot) !== $previous.'/public_html') {
                throw new DomainException('Unverified or incompatible immutable release topology.');
            }
            // Orchestrator supplies a restore-tested backup verifier. A
            // callback may be long-running and must not change the pathname
            // whose inode this process holds locked: another updater could
            // otherwise acquire a different file under the same lock name.
            $leaseCheck();
            $backupVerified = $verifiedBackup();
            $leaseCheck();
            if ($backupVerified !== true) {
                throw new DomainException('Code switch requires an independently verified recovery backup.');
            }
            // Verify lock identity and current code under the same exclusive
            // lease immediately before any irreversible pointer mutation.
            if (realpath($currentLink) !== $previous
                || ! $this->publicLinkMatches($publicDocumentRoot, $currentLink, $previous)) {
                throw new DomainException('Concurrent release or public document root change detected.');
            }
            $leaseCheck();

            $this->replacePointer($currentLink, $candidate);
            try {
                if (realpath($currentLink) !== $candidate || $healthCheck() !== true) {
                    throw new RuntimeException('New release failed application health verification.');
                }
                // A health callback is external code and may change the live
                // pointer or the lease pathname. Never report success based
                // solely on the state observed before calling it.
                $leaseCheck();
                if (realpath($currentLink) !== $candidate
                    || ! $this->publicLinkMatches($publicDocumentRoot, $currentLink, $candidate)) {
                    throw new RuntimeException('Release pointer changed during health verification.');
                }
            } catch (Throwable $problem) {
                // If ownership of the lock or pointer is no longer proven,
                // an automatic rollback might overwrite another deployer's
                // newer release. Stop and require verified manual recovery.
                try {
                    $leaseCheck();
                    if (realpath($currentLink) !== $candidate
                        || ! $this->publicLinkMatches($publicDocumentRoot, $currentLink, $candidate)) {
                        throw new DomainException('Release or public root ownership lost during health check.');
                    }
                } catch (Throwable $ownershipFailure) {
                    throw new RuntimeException(
                        'Release ownership changed; manual recovery required before further updates.',
                        0,
                        $ownershipFailure,
                    );
                }
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

    /**
     * The live public entrypoint must still use the approved stable chain,
     * not merely resolve to the expected files via a replacement direct
     * symlink. No rollback is safe after the external health callback
     * changes the public routing independently of the locked code pointer.
     */
    private function publicLinkMatches(string $publicDocumentRoot, string $currentLink, string $release): bool
    {
        clearstatcache(true, $publicDocumentRoot);

        return is_link($publicDocumentRoot)
            && readlink($publicDocumentRoot) === $currentLink.'/public_html'
            && realpath($publicDocumentRoot) === $release.'/public_html';
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
            || ($opened['mode'] & 0022) !== 0
            || ($named['mode'] & 0022) !== 0
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

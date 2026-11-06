<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Coordinates an actual *isolated* files + MariaDB recovery drill before a
 * code-only atomic cutover. Never creates a DB, fetches an untrusted release,
 * runs migrations or restores a live database.
 *
 * Integration must separately authorize the owner, verify the signed release,
 * prepare a trusted immutable candidate and quiesce all writers. This class is
 * intentionally not connected to a public/admin HTTP endpoint.
 */
final class VerifiedRecoveryCutover
{
    /**
     * @param callable():bool $writesQuiesced Fail closed unless the host has stopped writes/queues.
     * @param callable():bool $candidateVerified Caller must attest the staged, signed release bytes.
     * @param callable():bool $healthCheck Post-switch application health probe.
     * @return array{state:string,previous:string,current:string,files:int,database_tables:int,database_rows:int}
     */
    public function execute(
        PDO $liveDatabase,
        PDO $disposableRestoreDatabase,
        string $environmentFile,
        string $privateUserData,
        string $publicUploads,
        string $privateBackupRoot,
        string $disposableFileRestoreRoot,
        string $releaseRoot,
        string $currentLink,
        string $publicDocumentRoot,
        string $approvedCandidate,
        callable $writesQuiesced,
        callable $candidateVerified,
        callable $healthCheck,
    ): array {
        if ($writesQuiesced() !== true || $candidateVerified() !== true) {
            throw new DomainException('Verified recovery requires write isolation and a signed code candidate.');
        }

        $vault = realpath($privateBackupRoot);
        $vaultStat = $vault === false ? false : @lstat($vault);
        $restoration = realpath($disposableFileRestoreRoot);
        $restoreStat = $restoration === false ? false : @lstat($restoration);
        $release = realpath($releaseRoot);
        $public = realpath($publicDocumentRoot);
        if ($vault === false || $restoration === false || $release === false || $public === false
            || is_link($privateBackupRoot) || is_link($disposableFileRestoreRoot)
            || $vault === $restoration || $vault === $release || $vault === $public
            || $this->overlap($vault, $restoration) || $this->overlap($vault, $release)
            || $this->overlap($vault, $public) || $this->overlap($restoration, $public)
            || $this->overlap($restoration, $release)
            || ! is_dir($vault) || ! is_writable($vault)
            || ! is_array($vaultStat)
            || ($vaultStat['mode'] & 0170000) !== 0040000
            || ($vaultStat['mode'] & 0022) !== 0
            || ! is_dir($restoration) || ! is_writable($restoration)
            || is_link($disposableFileRestoreRoot)
            || ! is_array($restoreStat)
            || ($restoreStat['mode'] & 0170000) !== 0040000
            || ($restoreStat['mode'] & 0077) !== 0
            || $restoreStat['uid'] !== $vaultStat['uid']) {
            throw new DomainException('Backups and restore drills require separate private locations.');
        }

        $lockFile = $vault.'/.verified-cutover.lock';
        if (is_link($lockFile)) {
            throw new DomainException('Unsafe recovery orchestration lock.');
        }
        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            throw new DomainException('Cannot secure private recovery lock.');
        }

        try {
            // The inner recovery lease must protect the directory containing
            // the verified files and MariaDB backups, not just its lock file.
            // A chmod or directory rename during a callback revokes authority
            // before backups or the executable code pointer can be changed.
            $lockCheck = function () use (
                $lock, $lockFile, $vault, $vaultStat, $restoration, $restoreStat,
            ): bool {
                clearstatcache(true, $vault);
                $currentVault = @lstat($vault);
                if (! is_array($currentVault) || is_link($vault)
                    || realpath($vault) !== $vault
                    || ($currentVault['mode'] & 0170000) !== 0040000
                    || ($currentVault['mode'] & 0022) !== 0
                    || $currentVault['dev'] !== $vaultStat['dev']
                    || $currentVault['ino'] !== $vaultStat['ino']
                    || $currentVault['mode'] !== $vaultStat['mode']) {
                    throw new DomainException('Unsafe recovery vault identity or permissions; operation must stop.');
                }
                // The disposable restore tree can be poisoned or replaced
                // independently of the backup vault. It must retain its
                // original private directory inode throughout callbacks,
                // file and DB drills, and the eventual code switch.
                clearstatcache(true, $restoration);
                $currentRestore = @lstat($restoration);
                if (! is_array($currentRestore) || is_link($restoration)
                    || realpath($restoration) !== $restoration
                    || ($currentRestore['mode'] & 0170000) !== 0040000
                    || ($currentRestore['mode'] & 0077) !== 0
                    || $currentRestore['dev'] !== $restoreStat['dev']
                    || $currentRestore['ino'] !== $restoreStat['ino']
                    || $currentRestore['mode'] !== $restoreStat['mode']
                    || $currentRestore['uid'] !== $restoreStat['uid']) {
                    throw new DomainException('Unsafe disposable restore root identity; operation must stop.');
                }
                return $this->assertSecureLockIdentity($lock, $lockFile);
            };
            $lockCheck();
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another verified recovery process is already running.');
            }
            // The pathname may have been exchanged while flock was pending.
            // Never back up real data under a stale or hard-linked lock.
            $lockCheck();

            // Preserve both vault and lock identities throughout all external
            // callbacks, backup/restore work and the final code switch.
            $guard = function (callable $callback) use ($lockCheck): bool {
                $lockCheck();
                $result = $callback();
                $lockCheck();
                return $result === true;
            };
            $guardWrites = fn (): bool => $guard($writesQuiesced);
            $guardCandidate = fn (): bool => $guard($candidateVerified);
            $guardHealth = fn (): bool => $guard($healthCheck);

            // These checks are repeated under the lock before any backups.
            if ($guardWrites() !== true || $guardCandidate() !== true) {
                throw new DomainException('Update preconditions changed after locking.');
            }

            $files = (new PrivateRecoverySnapshot())->create(
                $environmentFile, $privateUserData, $publicUploads, $vault,
            );
            $lockCheck();
            $fileDrill = (new PrivateRecoverySnapshot())->drill(
                $files['directory'], $files['manifest_sha256'], $restoration,
            );
            if ($fileDrill['files'] !== $files['files'] || $fileDrill['bytes'] !== $files['bytes']) {
                throw new DomainException('Private file restore proof does not match the backup.');
            }

            $lockCheck();
            $database = (new MariaDbRecoverySnapshot())->create(
                $liveDatabase, $vault, $publicDocumentRoot,
            );
            $lockCheck();
            $dbDrill = (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $disposableRestoreDatabase, $database['directory'], $database['manifest_sha256'],
            );
            if ($dbDrill['tables'] !== $database['tables'] || $dbDrill['rows'] !== $database['rows']) {
                throw new DomainException('MariaDB restore proof does not match the source backup.');
            }

            $lockCheck();

            // A successful restore drill never authorizes a later cutover if
            // the caller has resumed writes or candidate identity has changed.
            $result = (new AtomicReleaseSwitcher())->switch(
                $releaseRoot, $currentLink, $publicDocumentRoot, $approvedCandidate,
                fn (): bool => $guardWrites() && $guardCandidate(),
                $guardHealth,
            );

            $lockCheck();
            return [
                'state' => $result['state'],
                'previous' => $result['previous'],
                'current' => $result['current'],
                'files' => $fileDrill['files'],
                'database_tables' => $dbDrill['tables'],
                'database_rows' => $dbDrill['rows'],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Require the opened descriptor and the visible lock pathname to be the
     * SAME single-linked regular inode. Checking is_link() before fopen()
     * alone leaves a path-swap race and accepts hard-linked lock aliases.
     *
     * @param resource $lock
     */
    private function assertSecureLockIdentity($lock, string $path): bool
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
            throw new DomainException('Unsafe recovery lock identity; operation must stop.');
        }
        return true;
    }

    private function overlap(string $left, string $right): bool
    {
        return str_starts_with($left, rtrim($right, '/').'/')
            || str_starts_with($right, rtrim($left, '/').'/');
    }
}

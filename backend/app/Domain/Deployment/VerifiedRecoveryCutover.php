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
        $restoration = realpath($disposableFileRestoreRoot);
        $release = realpath($releaseRoot);
        $public = realpath($publicDocumentRoot);
        if ($vault === false || $restoration === false || $release === false || $public === false
            || is_link($privateBackupRoot) || is_link($disposableFileRestoreRoot)
            || $vault === $restoration || $vault === $release || $vault === $public
            || $this->overlap($vault, $restoration) || $this->overlap($vault, $release)
            || $this->overlap($vault, $public) || $this->overlap($restoration, $public)
            || $this->overlap($restoration, $release)
            || ! is_dir($vault) || ! is_writable($vault)
            || ! is_dir($restoration) || ! is_writable($restoration)) {
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
            $this->assertSecureLockIdentity($lock, $lockFile);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another verified recovery process is already running.');
            }
            // The pathname may have been exchanged while flock was pending.
            // Never back up real data under a stale or hard-linked lock.
            $this->assertSecureLockIdentity($lock, $lockFile);

            // These checks are repeated under the lock before any backups.
            if ($writesQuiesced() !== true || $candidateVerified() !== true) {
                throw new DomainException('Update preconditions changed after locking.');
            }

            $files = (new PrivateRecoverySnapshot())->create(
                $environmentFile, $privateUserData, $publicUploads, $vault,
            );
            $fileDrill = (new PrivateRecoverySnapshot())->drill(
                $files['directory'], $files['manifest_sha256'], $restoration,
            );
            if ($fileDrill['files'] !== $files['files'] || $fileDrill['bytes'] !== $files['bytes']) {
                throw new DomainException('Private file restore proof does not match the backup.');
            }

            $database = (new MariaDbRecoverySnapshot())->create(
                $liveDatabase, $vault, $publicDocumentRoot,
            );
            $dbDrill = (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $disposableRestoreDatabase, $database['directory'], $database['manifest_sha256'],
            );
            if ($dbDrill['tables'] !== $database['tables'] || $dbDrill['rows'] !== $database['rows']) {
                throw new DomainException('MariaDB restore proof does not match the source backup.');
            }

            // A successful restore drill never authorizes a later cutover if
            // the caller has resumed writes or candidate identity has changed.
            $result = (new AtomicReleaseSwitcher())->switch(
                $releaseRoot, $currentLink, $publicDocumentRoot, $approvedCandidate,
                fn (): bool => $writesQuiesced() === true && $candidateVerified() === true,
                $healthCheck,
            );

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
            throw new DomainException('Unsafe recovery lock identity; no backup or cutover was performed.');
        }
    }

    private function overlap(string $left, string $right): bool
    {
        return str_starts_with($left, rtrim($right, '/').'/')
            || str_starts_with($right, rtrim($left, '/').'/');
    }
}

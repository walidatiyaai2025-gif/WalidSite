<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use Throwable;

/**
 * Backend-only first-install cPanel recovery transaction.
 *
 * Coordinates the SAME private file lease shared by HTTP/CLI/queues with a
 * private release maintenance barrier and a disposable DB+file restore drill.
 * It NEVER deploys executable code, runs migrations, or authorizes a browser
 * update by itself. An authorized integration must independently verify
 * non-Laravel/direct-SQL/foreign-cron writers are stopped; the callback is
 * trusted host evidence, NOT a request or session boolean.
 *
 * The invoking HTTP handler MUST finish its own shared request lease before
 * calling this service. An active writer makes LOCK_EX fail closed.
 */
final class CpanelOwnerBackupCoordinator
{
    /**
     * @param callable():bool $externalWritersStopped Independently verified
     *     host-level no-writer predicate; never user-controlled.
     * @param ?CpanelConsoleWriterLease $ownerHttpLease Existing HTTP
     *     request-scoped lease from Laravel DI after owner+CSRF checks, never
     *     a value from request/session. Null for independent trusted CLI use.
     * @return array{id:string,version:string,files:int,bytes:int,tables:int,rows:int,file_sha256:string,database_sha256:string,code_update_performed:bool}
     */
    public function prove(
        PDO $live,
        PDO $disposable,
        string $environmentFile,
        string $privateData,
        string $publicUploads,
        string $applicationBase,
        string $publicRoot,
        string $vault,
        string $restore,
        string $installedVersion,
        string $previousRoot,
        callable $externalWritersStopped,
        ?CpanelConsoleWriterLease $ownerHttpLease = null,
    ): array {
        // Fail before creating even a lock file if the caller has not supplied
        // independent evidence of foreign writer isolation.
        if ($externalWritersStopped() !== true) {
            throw new DomainException('Independent host writer isolation is required before backup.');
        }

        $home = realpath(dirname($applicationBase));
        if ($home === false || $home !== dirname($applicationBase)
            || $applicationBase !== $home.'/application'
            || $publicRoot !== $home.'/public_html'
            || is_link($applicationBase) || is_link($publicRoot)
            || ! is_dir($applicationBase) || ! is_dir($publicRoot)) {
            throw new DomainException('An original physical cPanel install is required for owner backup.');
        }

        // The backup receipt must attest the *actual* installed code, not a
        // caller-supplied version string. This coordinator deliberately
        // supports only the first physical application tree: a chained active
        // pointer needs separate, verified running-release lineage support.
        // Refuse both before creating any private lock or recovery vault.
        $actualVersion = (new TrustedInstalledVersion())->read($applicationBase.'/VERSION');
        $activePointer = $home.'/.walidsite-active-release.json';
        clearstatcache(true, $activePointer);
        if ($actualVersion === null || ! hash_equals($actualVersion, $installedVersion)
            || @lstat($activePointer) !== false) {
            throw new DomainException('Installed code identity is not verified for a first-install recovery.');
        }

        $writers = $ownerHttpLease ?? new CpanelConsoleWriterLease();
        $maintenance = new CpanelReleaseMaintenance();
        $token = null;
        // A future owner-only HTTP controller is already guarded by a
        // shared lease. Convert THAT SAME held descriptor, rather than
        // deadlocking by taking another lock against our own request.
        // A failed nonblocking promotion abandons its stale shared proof;
        // the caller must abort before ANY data or release mutation.
        // CLI/host invocations without a request lease use the independent
        // exclusive acquisition as before.
        if ($ownerHttpLease !== null) {
            $writers->promoteSharedForUpdate($home);
        } else {
            $writers->beginExclusiveForUpdate($home);
        }
        try {
            $writers->assertExclusiveHeld();
            if ($externalWritersStopped() !== true) {
                throw new DomainException('Host writer isolation changed before maintenance.');
            }

            $barrier = $maintenance->begin($home, $previousRoot, $installedVersion, 300);
            $token = $barrier['token'];
            $assertQuiesced = static function () use (
                $writers, $maintenance, $home, $token, $previousRoot,
                $externalWritersStopped,
            ): bool {
                $writers->assertExclusiveHeld();
                $maintenance->assertActive($home, $token, $previousRoot);
                return $externalWritersStopped() === true;
            };

            // OwnerUpdateRecoveryBackup tests .env, uploads and private data
            // against an independent file drill and empty disposable MariaDB.
            // It rechecks the actual held lock and maintenance before writing
            // a 0600, fsynced receipt; this service changes NO live code.
            $proof = (new OwnerUpdateRecoveryBackup())->createAndProve(
                $live, $disposable, $environmentFile, $privateData,
                $publicUploads, $applicationBase, $publicRoot, $vault,
                $restore, $installedVersion, $assertQuiesced,
            );
            if ($assertQuiesced() !== true) {
                throw new DomainException('Writer isolation changed after recovery drill.');
            }

            // Backup-only transaction: no code pointer was switched, so the
            // live, unchanged original release can safely resume once this
            // exact barrier is cleared under the held exclusive writer lease.
            $maintenance->clear($home, $token);
            $token = null;
            return [...$proof, 'code_update_performed' => false];
        } finally {
            try {
                // Errors during the backup NEVER silently expose new code.
                // There is no cutover in this service. If the marker became
                // tampered/expired, clear() fails and deliberately leaves the
                // recovery barrier in place for manual intervention.
                if ($token !== null) {
                    $writers->assertExclusiveHeld();
                    $maintenance->clear($home, $token);
                }
            } finally {
                $writers->end();
            }
        }
    }
}

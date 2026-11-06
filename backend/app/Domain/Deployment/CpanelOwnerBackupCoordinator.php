<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use Throwable;

/**
 * Backend-only cPanel recovery transaction for verified running releases.
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
     * @param ?string $runningApplication Actual server-derived Laravel base
     *     path after the stable dispatcher. Null is first-install legacy only.
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
        ?string $runningApplication = null,
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

        // Backups always snapshot the ORIGINAL private .env/storage and public
        // uploads, even when the stable dispatcher now runs versioned code.
        // The receipt version, however, must match the ACTUAL running code.
        // Do not accept an environment/session version or a guessed pointer.
        $firstVersion = (new TrustedInstalledVersion())->read($applicationBase.'/VERSION');
        $running = $runningApplication ?? $applicationBase;
        $expectedPreviousRoot = $running === $applicationBase
            ? 'legacy' : substr(dirname($running), strlen($home) + 1);
        if ($firstVersion === null
            || (new TrustedInstalledReleasePin())->read($running, $publicRoot, $firstVersion)
                !== $installedVersion
            || $previousRoot !== $expectedPreviousRoot
            || ($previousRoot !== 'legacy'
                && (preg_match(
                    '#\\A\\.walidsite-releases/v'.preg_quote($installedVersion, '#').'-[a-f0-9]{40}\\z#D',
                    $previousRoot,
                ) !== 1 || $running !== $home.'/'.$previousRoot.'/application'))) {
            throw new DomainException('Installed code identity is not verified for owner recovery.');
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
            // Re-read the private stable-dispatcher pointer and running
            // VERSION under the SAME exclusive writer descriptor. A swapped
            // pointer or code tree during lock handoff cannot create a backup
            // receipt labelled with another release.
            if ((new TrustedInstalledReleasePin())->read($running, $publicRoot, $firstVersion)
                !== $installedVersion) {
                throw new DomainException('Installed release lineage changed before backup.');
            }

            // Backup Now is a separate entrypoint from signed code install.
            // Validate the ACTUAL PDO connections under the exclusive writer
            // lease before even creating a maintenance marker or recovery
            // vault. A same-schema, populated, or non-MariaDB restore target
            // must never begin a destructive recovery rehearsal.
            (new CpanelDisposableMariaDbGuard())->assertIndependentAndEmpty($live, $disposable);

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

            // Backup-only transaction: no code pointer was switched. The
            // unchanged verified running release may resume after the exact
            // maintenance barrier is cleared under our writer lease.
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

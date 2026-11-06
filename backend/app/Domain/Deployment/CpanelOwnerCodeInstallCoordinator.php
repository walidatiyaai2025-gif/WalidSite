<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Verified physical cPanel code-only release orchestration, including chained releases.
 *
 * The same HTTP writer descriptor is promoted to EXCLUSIVE and held across
 * private maintenance, independently restored DB/file backup, atomic pointer
 * switch, remote HTTPS health and final maintenance clear. Any failure keeps
 * old PHP running or leaves a private recovery barrier for manual inspection.
 *
 * No migrations are run. Changed migration inventory or Composer dependencies
 * are explicitly unsupported by CpanelArchiveCodeOnlyPreflight and the pointer.
 * Chained code-only updates use the same verified stable original private data
 * and independently checked running-release pointer as owner Backup Now.
 */
final class CpanelOwnerCodeInstallCoordinator
{
    /** @return array{state:string,version:string,source_sha:string,backup_id:string,from:string,to:string} */
    public function install(
        CpanelOwnerCodeUpdateHost $host,
        CpanelConsoleWriterLease $requestLease,
        PDO $live,
        PDO $disposable,
        string $envelope,
        string $publisherKey,
        string $origin,
        string $installedVersion,
        string $homeDirectory,
        string $archiveDirectory,
        string $archiveFile,
        ?string $runningApplication = null,
    ): array {
        $home = realpath($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)
            || ! is_dir($home.'/application') || is_link($home.'/application')
            || ! is_dir($home.'/public_html') || is_link($home.'/public_html')) {
            throw new DomainException('Verified physical cPanel roots are required for code installation.');
        }
        // The first-install VERSION remains a permanent trust anchor. Later
        // verified running releases are selected solely by the stable private
        // pointer and server-derived Laravel base path, never by a browser
        // field, .env version override or arbitrary candidate folder.
        $firstVersion = (new TrustedInstalledVersion())->read($home.'/application/VERSION');
        $running = $runningApplication ?? $home.'/application';
        $previousRoot = $running === $home.'/application'
            ? 'legacy' : substr(dirname($running), strlen($home) + 1);
        if ($firstVersion === null
            || (new TrustedInstalledReleasePin())->read(
                $running, $home.'/public_html', $firstVersion,
            ) !== $installedVersion
            || ($previousRoot !== 'legacy'
                && (preg_match(
                    '#\\A\\.walidsite-releases/v'.preg_quote($installedVersion, '#').'-[a-f0-9]{40}\\z#D',
                    $previousRoot,
                ) !== 1 || $running !== $home.'/'.$previousRoot.'/application'))) {
            throw new DomainException('Installed running release identity is not verified for code installation.');
        }
        // A trusted host adapter supplies independently checked real-world
        // writer shutdown; the application can only isolate cooperating HTTP
        // and console writers with its own shared/exclusive lease.
        if ($host->externalWritersStopped() !== true) {
            throw new DomainException('Independent host writer isolation is unavailable.');
        }
        // Validate the actual PDO objects in the core orchestrator, even
        // when it is called without the Admin HTTP controller's preflight.
        // A duplicate PDO connection pointed at production is not a restore
        // sandbox. Validate BEFORE any maintenance marker or backup is made.
        (new CpanelDisposableMariaDbGuard())->assertIndependentAndEmpty($live, $disposable);

        $signed = (new TrustedReleaseMetadata())->verify(
            $envelope, $publisherKey, $origin, $installedVersion,
        );
        // This trusted front controller is shipped inside the installed
        // physical application, not selected by an HTTP parameter.
        $front = $home.'/application/resources/deployment/cpanel-stable-index.php';
        (new CpanelPhysicalHostPreflight())->assertReady(
            $home, $front, $installedVersion, $signed['artifact_size'],
        );
        (new TrustedReleaseArchive())->inspect($signed, $archiveDirectory, $archiveFile);
        (new CpanelArchiveCodeOnlyPreflight())->assertCompatible(
            $home, $home.'/public_html', $archiveFile,
            $signed['artifact_sha256'], $installedVersion, $signed['version'],
        );
        $candidate = $home.'/.walidsite-releases/v'.$signed['version'].'-'.$signed['source_sha'];
        $attestor = new CpanelSignedCandidateAttestor();
        $attestor->attest(
            $envelope, $publisherKey, $origin, $installedVersion,
            $archiveDirectory, $archiveFile, $home,
        );

        $maintenance = new CpanelReleaseMaintenance();
        $token = null;
        $releaseSwitched = false;
        // The request is itself one HTTP shared reader. Its exact descriptor
        // must be promoted; opening a second exclusive descriptor deadlocks.
        $requestLease->promoteSharedForUpdate($home);
        try {
            $requestLease->assertExclusiveHeld();
            if ($host->externalWritersStopped() !== true) {
                throw new DomainException('Foreign writers resumed before maintenance.');
            }
            // Under the held exclusive writer descriptor, re-attest the
            // actual selected release and immutable first-install trust pin
            // before any private maintenance marker or recovery snapshot.
            if ((new TrustedInstalledReleasePin())->read(
                $running, $home.'/public_html', $firstVersion,
            ) !== $installedVersion) {
                throw new DomainException('Running release lineage changed before code maintenance.');
            }
            // The disposable PDO may be replaced or populated while release
            // metadata and staged files are authenticated. Verify the actual
            // source and recovery schemas again under our exclusive lease,
            // immediately before creating a maintenance marker or backup.
            (new CpanelDisposableMariaDbGuard())->assertIndependentAndEmpty($live, $disposable);
            $token = $maintenance->begin($home, $previousRoot, $installedVersion, 300)['token'];
            $quiesced = static function () use (
                $requestLease, $host, $maintenance, $home, $token, $previousRoot,
            ): bool {
                $requestLease->assertExclusiveHeld();
                $maintenance->assertActive($home, $token, $previousRoot);
                return $host->externalWritersStopped() === true;
            };

            // A receipt is issued only after independent restore into a
            // separate, empty recovery database and disposable file tree.
            $proof = (new OwnerUpdateRecoveryBackup())->createAndProve(
                $live, $disposable,
                $home.'/application/.env', $home.'/application/storage/app',
                $home.'/public_html/uploads',
                $home.'/application', $home.'/public_html',
                $home.'/.walidsite-backups', $home.'/.walidsite-restore-drills',
                $installedVersion, $quiesced,
            );
            if ($quiesced() !== true) {
                throw new DomainException('Write isolation lost after restore rehearsal.');
            }
            // A receipt's counts/version alone are not a backup proof: match
            // the exact current private receipt and BOTH snapshot digests
            // under pinned home/vault/receipt inode checks at every gate.
            $receiptValid = static function () use ($home, $proof, $quiesced): bool {
                return $quiesced() === true
                    && (new CpanelOwnerRecoveryHistory())->matchesFreshProof($home, $proof);
            };
            $signedValid = static function () use (
                $attestor, $envelope, $publisherKey, $origin,
                $installedVersion, $archiveDirectory, $archiveFile, $home,
                $signed, $quiesced,
            ): bool {
                try {
                    if ($quiesced() !== true) {
                        return false;
                    }
                    $check = $attestor->attest(
                        $envelope, $publisherKey, $origin, $installedVersion,
                        $archiveDirectory, $archiveFile, $home,
                    );
                    return $check['version'] === $signed['version']
                        && $check['source_sha'] === $signed['source_sha'];
                } catch (Throwable) {
                    return false;
                }
            };
            $health = static function () use ($host, $token, $signed): bool {
                // The host must perform a fresh, TLS-verified network probe
                // through the stable dispatcher against the candidate runtime.
                return $host->probeNewRelease(
                    $token, $signed['version'], $signed['source_sha'],
                ) === true;
            };

            $switched = (new CpanelImmutableCodePointer())->activate(
                $home, $candidate, $signed['version'], $signed['source_sha'],
                $front, $token, $quiesced, $receiptValid, $signedValid, $health,
            );
            $releaseSwitched = true;
            if ($quiesced() !== true || $signedValid() !== true
                || $receiptValid() !== true) {
                throw new RuntimeException('Release evidence changed after health verification.');
            }

            // After this clear, the stable physical front controller may serve
            // new PHP. A failure MUST NOT be represented as a successful
            // install; preserve the private maintenance barrier for recovery.
            $maintenance->clear($home, $token);
            $token = null;

            return [
                'state' => 'installed_verified',
                'version' => $switched['version'],
                'source_sha' => $signed['source_sha'],
                'backup_id' => $proof['id'],
                'from' => $switched['from'],
                'to' => $switched['to'],
            ];
        } finally {
            try {
                if ($token !== null && ! $releaseSwitched
                    && @lstat($home.'/.walidsite-active-release.json') === false) {
                    // Original-install rollback: the initial private pointer
                    // remains absent, so no code was switched. For chained
                    // releases a restored previous pointer is NOT sufficient
                    // to auto-clear maintenance without separately proving
                    // its exact immutable identity; keep the recovery barrier.
                    try {
                        $requestLease->assertExclusiveHeld();
                        $maintenance->clear($home, $token);
                    } catch (Throwable) {
                        // An expired or mutated lease is an intentional
                        // recovery barrier, not permission to unlink it.
                    }
                }
            } finally {
                $requestLease->end();
            }
        }
    }
}

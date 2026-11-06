<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * First physical cPanel code-only release orchestration.
 *
 * The same HTTP writer descriptor is promoted to EXCLUSIVE and held across
 * private maintenance, independently restored DB/file backup, atomic pointer
 * switch, remote HTTPS health and final maintenance clear. Any failure keeps
 * old PHP running or leaves a private recovery barrier for manual inspection.
 *
 * No migrations are run. Changed migration inventory or Composer dependencies
 * are explicitly unsupported by CpanelArchiveCodeOnlyPreflight and the pointer.
 * Chained updates require a separate verified running-root backup contract.
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
    ): array {
        $home = realpath($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)
            || ! is_dir($home.'/application') || is_link($home.'/application')
            || ! is_dir($home.'/public_html') || is_link($home.'/public_html')
            || (new TrustedInstalledVersion())->read($home.'/application/VERSION') !== $installedVersion
            || @lstat($home.'/.walidsite-active-release.json') !== false) {
            throw new DomainException('Only a verified original physical cPanel install is supported.');
        }
        // A trusted host adapter supplies independently checked real-world
        // writer shutdown; the application can only isolate cooperating HTTP
        // and console writers with its own shared/exclusive lease.
        if ($host->externalWritersStopped() !== true) {
            throw new DomainException('Independent host writer isolation is unavailable.');
        }

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
            $token = $maintenance->begin($home, 'legacy', $installedVersion, 300)['token'];
            $quiesced = static function () use (
                $requestLease, $host, $maintenance, $home, $token,
            ): bool {
                $requestLease->assertExclusiveHeld();
                $maintenance->assertActive($home, $token, 'legacy');
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
            $receiptValid = static function () use ($home, $proof, $installedVersion, $quiesced): bool {
                try {
                    if ($quiesced() !== true) {
                        return false;
                    }
                    $history = (new CpanelOwnerRecoveryHistory())->inspect($home);
                    foreach ($history['receipts'] as $item) {
                        if ($item['id'] === $proof['id']
                            && $item['version'] === $installedVersion
                            && $item['files'] === $proof['files']
                            && $item['tables'] === $proof['tables']
                            && $item['rows'] === $proof['rows']) {
                            return true;
                        }
                    }
                    return false;
                } catch (Throwable) {
                    return false;
                }
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
                    // No code was switched; remove a still-live lease only
                    // when we retain its exclusive writer ownership.
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

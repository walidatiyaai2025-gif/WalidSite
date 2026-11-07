<?php

namespace App\Domain\Deployment;

use DomainException;
use PDO;

/**
 * Host-authorized signed release acquisition and code-only installation from
 * the PERMANENT cPanel CLI launcher. No web or user-supplied invocation exists.
 *
 * Discovery never grants update authority. Without the independently
 * registered CpanelOwnerCodeUpdateHost capability the method refuses before
 * network/download/backup/code writes. The existing owner installer performs
 * the actual independent restore drill, exclusive writer drain, maintenance,
 * atomic pointer cutover and remote HTTPS health verification.
 */
final class CpanelCronTrustedCodeInstaller
{
    /**
     * @return array{state:string,version:string,source_sha:string,backup_id:string,from:string,to:string}
     */
    public function install(
        string $homeDirectory,
        string $runningApplication,
        string $publicDocumentRoot,
        string $installedVersion,
        string $publisherOrigin,
        string $publisherKey,
        ?CpanelOwnerCodeUpdateHost $host,
        CpanelConsoleWriterLease $lease,
        PDO $liveDatabase,
        ?TrustedReleaseCatalog $catalog = null,
        ?TrustedReleaseDownloader $downloader = null,
    ): array {
        // A real host adapter is a capability registered by a trusted
        // operator, never a boolean inferred from .env or remote metadata.
        if ($host === null) {
            throw new DomainException('Independent cPanel code update host capability is missing.');
        }
        $lease->assertSharedHeld();
        $home = realpath($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($home)
            || $publicDocumentRoot !== $home.'/public_html'
            || realpath($publicDocumentRoot) !== $publicDocumentRoot
            || is_link($publicDocumentRoot)
            || ! is_dir($home.'/application')
            || is_link($home.'/application')
            || $runningApplication !== realpath($runningApplication)) {
            throw new DomainException('Trusted physical installed cPanel layout is required.');
        }
        $first = (new TrustedInstalledVersion())->read($home.'/application/VERSION');
        if ($first === null || (new TrustedInstalledReleasePin())->read(
            $runningApplication, $publicDocumentRoot, $first
        ) !== $installedVersion) {
            throw new DomainException('Running release identity changed before scheduled update.');
        }
        if ($host->externalWritersStopped() !== true) {
            throw new DomainException('Uncontrolled host writers block scheduled updates.');
        }

        // Both catalog and artifact requests use TLS peer validation, checked
        // public DNS pinning, strict time/byte budgets and no redirects.
        $available = ($catalog ?? new TrustedReleaseCatalog())->check(
            $publisherOrigin, $publisherKey, $installedVersion,
        );
        $signed = (new TrustedReleaseMetadata())->verify(
            $available['envelope'], $publisherKey, $publisherOrigin, $installedVersion,
        );
        if ($signed !== $available['release']) {
            throw new DomainException('Signed catalog identity changed.');
        }
        $lease->assertSharedHeld();

        $front = $home.'/application/resources/deployment/cpanel-stable-index.php';
        (new CpanelPhysicalHostPreflight())->assertReady(
            $home, $front, $installedVersion, $signed['artifact_size'],
        );

        $root = $home.'/application/storage/app/private';
        $inboxRoot = $root.'/verified-updates';
        $this->privateDirectory($root, false);
        $this->privateDirectory($inboxRoot, true);
        // Fixed, SHA-bound directory means a crashed attempt is retryable
        // only with the exact same Ed25519-signed archive bytes.
        $inbox = $inboxRoot.'/cron-'.$signed['version'].'-'.$signed['source_sha'];
        $this->privateDirectory($inbox, true);
        $name = basename($signed['artifact_url']);
        $archive = $inbox.'/'.$name;
        if (is_link($archive)) {
            throw new DomainException('Scheduled release ZIP pathname was replaced.');
        }
        if (file_exists($archive)) {
            // Never trust a cached ZIP by filename or an earlier Cron receipt.
            (new TrustedReleaseArchive())->inspect($signed, $inbox, $archive);
        } else {
            $downloaded = ($downloader ?? new TrustedReleaseDownloader())->download(
                $available['envelope'], $publisherKey, $publisherOrigin,
                $installedVersion, $inbox, $publicDocumentRoot,
            );
            if ($downloaded['path'] !== $archive
                || ! hash_equals($signed['artifact_sha256'], $downloaded['artifact_sha256'])) {
                throw new DomainException('Publisher archive identity changed after downloading.');
            }
        }
        $lease->assertSharedHeld();

        // Compatibility is checked against the *currently installed* code,
        // not an unverified future version. Changed Composer dependencies
        // or migration inventory fail closed; this is CODE ONLY.
        (new CpanelArchiveCodeOnlyPreflight())->assertCompatible(
            $home, $publicDocumentRoot, $archive,
            $signed['artifact_sha256'], $installedVersion, $signed['version'],
        );
        $target = $home.'/.walidsite-releases/v'.$signed['version'].'-'.$signed['source_sha'];
        if (is_link($target)) {
            throw new DomainException('Scheduled release candidate is linked.');
        }
        if (! file_exists($target)) {
            (new CpanelTrustedCodeStager())->stage(
                $available['envelope'], $publisherKey, $publisherOrigin,
                $installedVersion, $home, $front, $inbox, $archive,
            );
        }
        // A prior crash or retry must not allow a partially staged / altered
        // candidate: compare every physical file to the signed archive again.
        $attested = (new CpanelSignedCandidateAttestor())->attest(
            $available['envelope'], $publisherKey, $publisherOrigin,
            $installedVersion, $inbox, $archive, $home,
        );
        if ($attested['version'] !== $signed['version']
            || $attested['source_sha'] !== $signed['source_sha']) {
            throw new DomainException('Verified staged code identity is inconsistent.');
        }
        $lease->assertSharedHeld();
        if ($host->externalWritersStopped() !== true) {
            throw new DomainException('External writers resumed before scheduled release.');
        }
        $disposable = $host->disposableRestoreConnection();
        (new CpanelDisposableMariaDbGuard())->assertIndependentAndEmpty(
            $liveDatabase, $disposable
        );

        // The SAME previously acquired shared Cron lease is converted to
        // exclusive by the existing installer; no second lock is opened.
        // That installer releases the descriptor on success or failure.
        $installed = (new CpanelOwnerCodeInstallCoordinator())->install(
            $host, $lease, $liveDatabase, $disposable,
            $available['envelope'], $publisherKey, $publisherOrigin,
            $installedVersion, $home, $inbox, $archive, $runningApplication,
        );
        if ($installed['state'] !== 'installed_verified'
            || $installed['version'] !== $signed['version']
            || $installed['source_sha'] !== $signed['source_sha']) {
            throw new DomainException('Scheduled release did not return verified installation.');
        }
        return $installed;
    }

    private function privateDirectory(string $path, bool $mayCreate): void
    {
        if (is_link($path)) {
            throw new DomainException('Scheduled release private directory is linked.');
        }
        if (! is_dir($path)) {
            if (! $mayCreate || file_exists($path) || ! @mkdir($path, 0700)) {
                throw new DomainException('Scheduled release private directory is unavailable.');
            }
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || realpath($path) !== $path
            || ($stat['mode'] & 0170000) !== 0040000
            || ($stat['mode'] & 0077) !== 0
            || ! is_writable($path)) {
            throw new DomainException('Scheduled release private directory permissions are unsafe.');
        }
    }
}

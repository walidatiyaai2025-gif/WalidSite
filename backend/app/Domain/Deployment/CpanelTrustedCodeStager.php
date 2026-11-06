<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;

/**
 * Owner-only, authenticated ZIP -> versioned private cPanel code preparation.
 *
 * This operation NEVER changes the active release, runs a migration, enters
 * maintenance, or calls a health URL. It is an independently useful phase of
 * the Admin updater, not authorization to execute/activate the candidate.
 *
 * The caller must pass the envelope fetched afresh from the private,
 * owner-bound TrustedOwnerUpdateInbox receipt, not from HTTP parameters.
 */
final class CpanelTrustedCodeStager
{
    /**
     * @return array{state:string,version:string,source_sha:string,artifact_sha256:string,files:int}
     */
    public function stage(
        string $signedEnvelope,
        string $publisherPublicKey,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        string $homeDirectory,
        string $trustedFrontController,
        string $inboxDirectory,
        string $archiveFile,
    ): array {
        $home = realpath($homeDirectory);
        if ($home === false || $home !== $homeDirectory || is_link($homeDirectory)
            || ! is_dir($home) || ! is_writable($home)) {
            throw new DomainException('BLOCKED HOSTING: unsupported private cPanel home.');
        }

        $application = $home.'/application';
        $public = $home.'/public_html';
        $private = $application.'/storage/app/private';
        $privateReal = realpath($private);
        $inbox = realpath($inboxDirectory);
        if ($privateReal === false || $privateReal !== $private
            || $inbox === false || $inbox !== $inboxDirectory
            || is_link($private) || is_link($inboxDirectory)
            || ! str_starts_with($inbox, $private.'/verified-updates/')
            || dirname($archiveFile) !== $inbox
            || is_link($archiveFile) || ! is_file($archiveFile)) {
            throw new DomainException('Verified owner ZIP must stay in its isolated private inbox.');
        }

        // Authenticating the publisher metadata is separate from reading the
        // receipt; never trust a browser-supplied version, path or checksum.
        $signed = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope, $publisherPublicKey, $pinnedHttpsOrigin, $installedVersion,
        );
        (new TrustedReleaseArchive())->inspect($signed, $inbox, $archiveFile);
        (new CpanelPhysicalHostPreflight())->assertReady(
            $home, $trustedFrontController, $installedVersion, $signed['artifact_size'],
        );
        (new CpanelArchiveCodeOnlyPreflight())->assertCompatible(
            $home, $public, $archiveFile, $signed['artifact_sha256'],
            $installedVersion, $signed['version'],
        );

        // The outer release directory contains only immutable, versioned
        // executable code. It is never the document root or the live app.
        $releaseRoot = $home.'/.walidsite-releases';
        if (is_link($releaseRoot) || (file_exists($releaseRoot) && ! is_dir($releaseRoot))) {
            throw new DomainException('Private cPanel code release root is unsafe.');
        }
        if (! is_dir($releaseRoot)
            && ! @mkdir($releaseRoot, 0700)) {
            throw new RuntimeException('Cannot create private code release directory.');
        }
        clearstatcache(true, $releaseRoot);
        $rootStat = @lstat($releaseRoot);
        if (! is_array($rootStat)
            || realpath($releaseRoot) !== $releaseRoot
            || ($rootStat['mode'] & 0170000) !== 0040000
            || ($rootStat['mode'] & 0077) !== 0
            || ! is_writable($releaseRoot)) {
            throw new DomainException('Private code release directory permissions are unsafe.');
        }

        // Two owner browser sessions must not stage the same version
        // concurrently; directory rename alone lacks a no-replace guarantee.
        // This lease is private and deliberately distinct from the live
        // code-switch and backup locks (which staging never acquires).
        $lockPath = $releaseRoot.'/.walidsite-stage.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new DomainException('Private stage lock is unsafe.');
        }
        // Newly created leases must be private even under a permissive
        // process umask. Never chmod an existing lease: insecure preexisting
        // files are rejected rather than silently rehabilitated.
        $created = false;
        if (! file_exists($lockPath) && ! is_link($lockPath)) {
            $lock = @fopen($lockPath, 'x+');
            $created = $lock !== false;
        } else {
            $lock = false;
        }
        if ($lock === false) {
            $lock = @fopen($lockPath, 'c+');
        }
        if ($lock === false) {
            throw new DomainException('Cannot acquire private code staging lock.');
        }
        if ($created && ! @chmod($lockPath, 0600)) {
            fclose($lock);
            throw new DomainException('Cannot secure newly created private staging lease.');
        }
        try {
            $guard = static function () use ($lock, $lockPath, $releaseRoot, $rootStat): void {
                clearstatcache(true, $releaseRoot);
                clearstatcache(true, $lockPath);
                $actualRoot = @lstat($releaseRoot);
                $opened = @fstat($lock);
                $named = @lstat($lockPath);
                if (! is_array($actualRoot) || ! is_array($opened) || ! is_array($named)
                    || is_link($releaseRoot) || is_link($lockPath)
                    || realpath($releaseRoot) !== $releaseRoot
                    || $actualRoot['dev'] !== $rootStat['dev']
                    || $actualRoot['ino'] !== $rootStat['ino']
                    || $actualRoot['mode'] !== $rootStat['mode']
                    || ($opened['mode'] & 0170000) !== 0100000
                    || ($named['mode'] & 0170000) !== 0100000
                    || ($opened['mode'] & 0777) !== 0600
                    || ($named['mode'] & 0777) !== 0600
                    || $opened['dev'] !== $named['dev']
                    || $opened['ino'] !== $named['ino']
                    || $opened['nlink'] !== 1 || $named['nlink'] !== 1) {
                    throw new DomainException('Private staging lease identity or permissions changed.');
                }
            };
            $guard();
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new DomainException('Another private code staging operation is active.');
            }
            $guard();
            $target = $releaseRoot.'/v'.$signed['version'].'-'.$signed['source_sha'];
            if (file_exists($target) || is_link($target)) {
                throw new DomainException('Signed version is already staged.');
            }
            // The promoter verifies Ed25519 again, snapshots and rehashes
            // this exact ZIP, rejects traversal/links, then promotes a whole
            // candidate atomically. NEVER change a live cPanel pointer here.
            $candidate = (new TrustedCodeCandidatePromoter())->promote(
                $signedEnvelope, $publisherPublicKey, $pinnedHttpsOrigin,
                $installedVersion, $private, $inbox, $archiveFile, $releaseRoot, $public,
                verifiedPhysicalCpanelLayout: true,
            );
            $guard();
            if ($candidate['version'] !== $signed['version']
                || ! hash_equals($candidate['artifact_sha256'], $signed['artifact_sha256'])
                || ! hash_equals($candidate['source_sha'], $signed['source_sha'])) {
                throw new DomainException('Staged code identity is inconsistent with the publisher.');
            }
            return [
                'state' => 'staged_not_installed',
                'version' => $candidate['version'],
                'source_sha' => $candidate['source_sha'],
                'artifact_sha256' => $candidate['artifact_sha256'],
                'files' => $candidate['files'],
            ];
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

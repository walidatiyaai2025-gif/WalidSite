<?php

namespace App\Domain\Deployment;

use DomainException;
use RuntimeException;

/**
 * Trusted ZIP -> private quarantined extraction -> atomic promotion of the
 * complete application into a versioned code directory.
 *
 * Neither changes the active 'current' pointer nor runs SQL/PHP. The caller
 * must separately satisfy VerifiedRecoveryCutover before activation.
 */
final class TrustedCodeCandidatePromoter
{
    /**
     * @return array{directory:string,version:string,source_sha:string,artifact_sha256:string,files:int}
     */
    public function promote(
        string $signedEnvelope,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        string $privateQuarantineRoot,
        string $archiveDirectory,
        string $archiveFile,
        string $releaseRoot,
        string $publicDocumentRoot,
        bool $verifiedPhysicalCpanelLayout = false,
    ): array {
        $quarantine = realpath($privateQuarantineRoot);
        $releases = realpath($releaseRoot);
        $public = realpath($publicDocumentRoot);
        // Legacy symlink deployments resolve public_html inside releases.
        // The independently verified ONE-ZIP cPanel topology instead keeps a
        // physical document root beside a *private* .walidsite-releases dir.
        // Opt in only for that exact layout after physical host preflight.
        $physicalCpanel = $verifiedPhysicalCpanelLayout
            && $releases !== false && $public !== false
            && basename($releases) === '.walidsite-releases'
            && $public === dirname($releases).'/public_html'
            && ! is_link($publicDocumentRoot);
        if ($quarantine === false || $releases === false || $public === false
            || is_link($privateQuarantineRoot) || is_link($releaseRoot)
            || ! is_dir($quarantine) || ! is_dir($releases)
            || ! is_writable($quarantine) || ! is_writable($releases)
            || $this->overlap($quarantine, $releases)
            || $this->overlap($quarantine, $public)
            || $quarantine === $public || $releases === $public
            || (! $this->inside($public, $releases) && ! $physicalCpanel)) {
            throw new DomainException('Trusted promotion requires distinct private quarantine and versioned code roots.');
        }

        $quarantineStat = stat($quarantine);
        $releaseStat = stat($releases);
        if ($quarantineStat === false || $releaseStat === false
            || $quarantineStat['dev'] !== $releaseStat['dev']) {
            throw new DomainException('Cross-filesystem code promotion cannot be guaranteed atomic.');
        }

        $release = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope,
            $publisherPublicKeyBase64,
            $pinnedHttpsOrigin,
            $installedVersion,
        );
        $target = $releases.'/v'.$release['version'].'-'.$release['source_sha'];
        if (file_exists($target) || is_link($target)) {
            throw new DomainException('The verified version has already been staged.');
        }

        // Stager first checks actual bytes against the signed SHA256, rejects
        // ZIP slip/symlinks/secret runtime files and extracts under 0700 dirs.
        $staged = (new TrustedReleaseStager())->stage(
            $release, $archiveDirectory, $archiveFile, $quarantine, $publicDocumentRoot,
        );

        // Recheck the candidate before moving it. A code release is only
        // eligible for cutover when both application and docroot are complete.
        $candidate = $staged['directory'];
        foreach ([
            'application/bootstrap/app.php',
            'application/vendor/autoload.php',
            'public_html/index.php',
            'public_html/.htaccess',
        ] as $path) {
            if (! is_file($candidate.'/'.$path) || is_link($candidate.'/'.$path)) {
                throw new DomainException('The signed candidate is incomplete after extraction.');
            }
        }
        if (file_exists($target) || is_link($target)
            || ! @rename($candidate, $target) || realpath($target) !== $target) {
            throw new RuntimeException('Atomic promotion of signed code is unavailable on this host.');
        }

        // Preserve the signed ZIP and old/current code for auditing and
        // rollback; no code pointer changes here.
        return [
            'directory' => $target,
            'version' => $release['version'],
            'source_sha' => $release['source_sha'],
            'artifact_sha256' => $release['artifact_sha256'],
            'files' => $staged['files'],
        ];
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/').'/');
    }

    private function overlap(string $left, string $right): bool
    {
        return $this->inside($left, $right) || $this->inside($right, $left);
    }
}

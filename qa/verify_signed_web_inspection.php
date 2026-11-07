<?php
/**
 * Independently consume a private signed Web candidate using the SAME PHP
 * metadata/archive checks used by Admin/Cron. Never publish or apply code.
 *
 * Usage: WALIDSITE_PUBLISHER_PUBLIC_KEY_B64=... WALIDSITE_TRUSTED_RELEASE_ORIGIN=...
 *        php qa/verify_signed_web_inspection.php <private-directory> <version> <source-sha>
 */
declare(strict_types=1);

require __DIR__.'/../backend/app/Domain/Deployment/TrustedReleaseMetadata.php';
require __DIR__.'/../backend/app/Domain/Deployment/TrustedReleaseArchive.php';

use App\Domain\Deployment\TrustedReleaseArchive;
use App\Domain\Deployment\TrustedReleaseMetadata;

function rejectSignedInspection(): never
{
    fwrite(STDERR, "SIGNED WEB INSPECTION: BLOCKED (not published).\n");
    exit(2);
}

try {
    if ($argc !== 4 || ! preg_match('/^(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)$/D', $argv[2])
        || ! preg_match('/^[a-f0-9]{40}$/D', $argv[3])) {
        rejectSignedInspection();
    }
    $path = $argv[1];
    $directory = realpath($path);
    if ($directory === false || is_link($path) || ! is_dir($directory)
        || (fileperms($directory) & 0077) !== 0) {
        rejectSignedInspection();
    }
    $catalog = $directory.'/latest.json';
    $catalogStat = @lstat($catalog);
    if (! is_array($catalogStat) || is_link($catalog)
        || ($catalogStat['mode'] & 0170000) !== 0100000
        || $catalogStat['size'] < 1 || $catalogStat['size'] > 16384
        || $catalogStat['nlink'] !== 1
        || ($catalogStat['mode'] & 0077) !== 0) {
        rejectSignedInspection();
    }
    $publicKey = getenv('WALIDSITE_PUBLISHER_PUBLIC_KEY_B64');
    $origin = getenv('WALIDSITE_TRUSTED_RELEASE_ORIGIN');
    if (! is_string($publicKey) || $publicKey === ''
        || ! is_string($origin) || $origin === '') {
        rejectSignedInspection();
    }
    $envelope = @file_get_contents($catalog);
    if (! is_string($envelope) || strlen($envelope) !== $catalogStat['size']) {
        rejectSignedInspection();
    }
    $metadata = new TrustedReleaseMetadata();
    $release = $metadata->verify($envelope, $publicKey, $origin, '0.0.0');
    if ($release['version'] !== $argv[2] || $release['source_sha'] !== $argv[3]) {
        rejectSignedInspection();
    }
    $archive = $directory.'/WalidSite-update-v'.$argv[2].'.zip';
    $inspected = (new TrustedReleaseArchive())->inspect($release, $directory, $archive);
    if ($inspected['files'] < 4 || $inspected['uncompressed_bytes'] < 1) {
        rejectSignedInspection();
    }
    echo json_encode([
        'schema' => 'walidsite.signed-web-inspection.v1',
        'version' => $release['version'],
        'source_sha' => $release['source_sha'],
        'artifact_sha256' => $release['artifact_sha256'],
        'publisher_signature_verified' => true,
        'archive_integrity_verified' => true,
        'inspected_files' => $inspected['files'],
        'published' => false,
        'deployed' => false,
        'production_ready' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable) {
    rejectSignedInspection();
}

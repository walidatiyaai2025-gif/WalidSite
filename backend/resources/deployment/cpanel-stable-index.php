<?php
/**
 * Stable cPanel web entrypoint. The document root stays a real public_html
 * directory, while immutable Laravel code is selected by an atomic, private
 * version pointer. This file is shipped only by the trusted Setup builder.
 *
 * Never read a release path, signature or update token from the HTTP request.
 */
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$home = dirname(__DIR__);
$original = $home.'/application';
$root = $original;
$pointer = $home.'/.walidsite-active-release.json';
$maintenance = $home.'/.walidsite-update-maintenance.json';
$forcedPrevious = null;

if (file_exists($maintenance) || is_link($maintenance)) {
    // An interrupted update must never expose unverified code to visitors.
    // A lease expiring after a crashed PHP worker serves its known previous
    // release; manual recovery removes the marker after inspecting the vault.
    if (is_link($maintenance) || ! is_file($maintenance)
        || filesize($maintenance) === false || filesize($maintenance) > 1024) {
        http_response_code(503);
        exit('Site update maintenance is unavailable.');
    }
    try {
        $lease = json_decode((string) file_get_contents($maintenance), true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        http_response_code(503);
        exit('Site update maintenance is unavailable.');
    }
    if (! is_array($lease)
        || array_keys($lease) !== ['schema', 'expires_at', 'previous_root', 'previous_version', 'probe_sha256']
        || $lease['schema'] !== 1 || ! is_int($lease['expires_at'])
        || ! is_string($lease['previous_root']) || ! is_string($lease['previous_version'])
        || ! is_string($lease['probe_sha256'])
        || ! preg_match('/\A[a-f0-9]{64}\z/D', $lease['probe_sha256'])
        || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $lease['previous_version'])
        || ($lease['previous_root'] !== 'legacy'
            && (! preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $lease['previous_root'])
                || ! str_starts_with($lease['previous_root'], '.walidsite-releases/v'.$lease['previous_version'].'-')))) {
        http_response_code(503);
        exit('Site update maintenance is unavailable.');
    }
    if ($lease['expires_at'] > time()) {
        $probe = $_SERVER['HTTP_X_WALIDSITE_RELEASE_PROBE'] ?? null;
        if (! is_string($probe) || ! preg_match('/\A[a-f0-9]{64}\z/D', $probe)
            || ! hash_equals($lease['probe_sha256'], hash('sha256', $probe))) {
            http_response_code(503);
            header('Cache-Control: no-store');
            header('Retry-After: 30');
            exit('Site update maintenance in progress.');
        }
    } else {
        // Safe crash fallback: ignore a possibly unverified active pointer.
        $forcedPrevious = $lease;
    }
}

if ($forcedPrevious !== null && $forcedPrevious['previous_root'] === 'legacy') {
    $state = null;
} elseif ($forcedPrevious !== null) {
    $state = [
        'schema' => 1, 'version' => $forcedPrevious['previous_version'],
        'root' => $forcedPrevious['previous_root'],
    ];
} elseif (file_exists($pointer) || is_link($pointer)) {
    if (is_link($pointer) || ! is_file($pointer)
        || filesize($pointer) === false || filesize($pointer) > 512) {
        http_response_code(503);
        exit('Site release pointer unavailable.');
    }
    try {
        $state = json_decode((string) file_get_contents($pointer), true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        http_response_code(503);
        exit('Site release pointer unavailable.');
    }
}

if (isset($state) && $state !== null) {
    if (! is_array($state) || array_keys($state) !== ['schema', 'version', 'root']
        || $state['schema'] !== 1 || ! is_string($state['version'])
        || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $state['version'])
        || ! is_string($state['root'])
        || ! preg_match('#\A\.walidsite-releases/v[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z#D', $state['root'])
        || ! str_starts_with($state['root'], '.walidsite-releases/v'.$state['version'].'-')) {
        http_response_code(503);
        exit('Site release pointer unavailable.');
    }
    $releaseRoot = $home.'/'.$state['root'];
    $resolved = realpath($releaseRoot.'/application');
    if ($resolved === false || is_link($releaseRoot)
        || $resolved !== $releaseRoot.'/application'
        || ! is_file($resolved.'/vendor/autoload.php')
        || ! is_file($resolved.'/bootstrap/app.php')
        || ! is_file($resolved.'/VERSION')
        || trim((string) file_get_contents($resolved.'/VERSION')) !== $state['version']) {
        http_response_code(503);
        exit('Site release files unavailable.');
    }
    $root = $resolved;
}

// Laravel's original first-install and maintenance state must remain in
// the stable private location, never a new code release's storage directory.
if (is_file($maintenance = $original.'/storage/framework/maintenance.php')) {
    require $maintenance;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($original);
$app->useStoragePath($original.'/storage');
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());

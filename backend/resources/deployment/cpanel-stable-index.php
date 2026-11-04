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

if (file_exists($pointer) || is_link($pointer)) {
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

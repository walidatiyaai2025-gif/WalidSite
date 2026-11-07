<?php
/**
 * PRIVATE cPanel CLI Cron: signed publisher check only, no update execution.
 *
 * Packaged as HOME/application/cron/release-poll.php, never under public_html.
 * One-time cPanel Cron Jobs activation uses PHP CLI; no SSH on subsequent runs.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit(2);
}

try {
    $original = dirname(__DIR__);
    $home = dirname($original);
    $public = $home.'/public_html';
    $installedLock = $original.'/storage/app/site-installed.lock';

    // NEVER bootstrap Laravel (and potentially first-run setup) on a host
    // that has not completed a verified one-ZIP browser installation.
    if (realpath($original) !== $original
        || is_link($original) || realpath($public) !== $public || is_link($public)
        || ! is_file($original.'/.env') || is_link($original.'/.env')
        || ! is_file($installedLock) || is_link($installedLock)
        || ! is_file($original.'/vendor/autoload.php')
        || ! is_file($original.'/bootstrap/app.php')) {
        throw new RuntimeException('Private installed cPanel runtime unavailable.');
    }

    require $original.'/vendor/autoload.php';
    $app = require $original.'/bootstrap/app.php';
    $app->useEnvironmentPath($original);
    $app->useStoragePath($original.'/storage');
    $app->usePublicPath($public);
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    if (config('site.release_cron_check_enabled') !== true
        || config('site.updates_enabled') !== true) {
        throw new RuntimeException('Private release polling disabled.');
    }
    $version = (new \App\Domain\Deployment\TrustedInstalledReleasePin())->read(
        base_path(), $public, config('site.trusted_installed_version'),
    );
    $origin = config('site.trusted_release_origin');
    $key = config('site.trusted_release_public_key');
    if ($version === null || ! is_string($origin) || $origin === ''
        || ! is_string($key) || $key === ''
        || file_exists($home.'/.walidsite-update-maintenance.json')
        || is_link($home.'/.walidsite-update-maintenance.json')) {
        throw new RuntimeException('Verified signed release discovery is blocked.');
    }
    // This directory is outside DocumentRoot, owned by the application
    // identity. The poller demands 0700 and status/lock files 0600.
    $parent = $original.'/storage/app/private';
    if (! is_dir($parent) || is_link($parent)) {
        throw new RuntimeException('Private release status directory unavailable.');
    }
    $record = (new \App\Domain\Deployment\CpanelReleaseCronPoller())->poll(
        $parent.'/release-automation', $public, $origin, $key, $version,
    );
    // Print generic state only; never expose URLs, provider or private paths.
    fwrite(STDOUT, 'WalidSite release poll: '.$record['state'].PHP_EOL);
    exit($record['state'] === 'UP_TO_DATE' ? 0 : 1);
} catch (\Throwable) {
    fwrite(STDERR, 'WalidSite release poll: BLOCKED'.PHP_EOL);
    exit(2);
}

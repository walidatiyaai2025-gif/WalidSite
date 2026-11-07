<?php
/**
 * PERMANENT private cPanel CLI Cron launcher. Code-only updates MUST NOT
 * rewrite this file in HOME/application; it selects the same verified
 * immutable code as the stable public_html dispatcher under writer isolation.
 *
 * Cron checks SIGNED PUBLISHER CATALOG ONLY. It cannot download/install code,
 * migrate the database, grant Admin privileges or claim production deployment.
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

    // Refuse before loading any Laravel code if one-ZIP first installation
    // has not completed. The immutable candidate's own .env/storage must
    // never replace or recreate original owner data and APP_KEY.
    if (realpath($original) !== $original
        || is_link($original) || realpath($public) !== $public || is_link($public)
        || ! is_file($original.'/.env') || is_link($original.'/.env')
        || ! is_file($installedLock) || is_link($installedLock)
        || ! is_file($original.'/VERSION') || is_link($original.'/VERSION')
        || ! is_file($original.'/vendor/autoload.php')
        || is_link($original.'/vendor/autoload.php')
        || ! is_file($original.'/bootstrap/app.php')
        || is_link($original.'/bootstrap/app.php')) {
        throw new RuntimeException('Private installed cPanel runtime unavailable.');
    }

    // The initially installed autoloader contains the permanent selector and
    // writer-lease implementation. Keep the shared lease across selection,
    // Laravel bootstrap and the entire publisher network check/receipt.
    require $original.'/vendor/autoload.php';
    $writerLease = new \App\Domain\Deployment\CpanelConsoleWriterLease();
    $writerLease->beginForConsole($original);

    try {
        $running = (new \App\Domain\Deployment\CpanelCronReleaseSelector())->select(
            $original, $public, $writerLease,
        );
        $writerLease->assertSharedHeld();

        // No stale first-install bootstrap after a signed versioned update.
        // Original .env, sessions, uploads, storage and installer lock remain
        // fixed at the physical HOME/application across chained releases.
        if ($running !== $original) {
            require $running.'/vendor/autoload.php';
        }
        $app = require $running.'/bootstrap/app.php';
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
        if ($version === null || base_path() !== $running
            || ! is_string($origin) || $origin === ''
            || ! is_string($key) || $key === ''
            || file_exists($home.'/.walidsite-update-maintenance.json')
            || is_link($home.'/.walidsite-update-maintenance.json')) {
            throw new RuntimeException('Verified signed release discovery is blocked.');
        }
        $writerLease->assertSharedHeld();

        $parent = $original.'/storage/app/private';
        if (! is_dir($parent) || is_link($parent)) {
            throw new RuntimeException('Private release status directory unavailable.');
        }
        $record = (new \App\Domain\Deployment\CpanelReleaseCronPoller())->poll(
            $parent.'/release-automation', $public, $origin, $key, $version,
        );
        $writerLease->assertSharedHeld();
        $status = $record['state'];
    } finally {
        $writerLease->end();
    }

    // Only a generic, redacted state is sent to scheduled CLI output.
    fwrite(STDOUT, 'WalidSite release poll: '.$status.PHP_EOL);
    exit($status === 'UP_TO_DATE' ? 0 : 1);
} catch (\Throwable) {
    fwrite(STDERR, 'WalidSite release poll: BLOCKED'.PHP_EOL);
    exit(2);
}

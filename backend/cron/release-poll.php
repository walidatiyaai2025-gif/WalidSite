<?php
/**
 * PERMANENT private cPanel CLI Cron launcher. Code-only updates MUST NOT
 * rewrite this file in HOME/application; it selects the same verified
 * immutable code as the stable public_html dispatcher under writer isolation.
 *
 * Signed catalog polling is read-only by default. When separately opted in,
 * only a host-installed independent writer/restore/HTTPS capability may let
 * the verified code-only release installer activate a newer signed version.
 * Cron never runs arbitrary PHP, database migrations or owner web actions.
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
        // Owner-facing diagnostics must not disappear when signed publisher
        // preflight stops BEFORE catalog polling. Never infer a trusted
        // installed release from the local observed VERSION; use it only for
        // a strictly blocked, non-authorizing private receipt.
        $preflightReason = null;
        $firstPin = config('site.trusted_installed_version');
        if (! is_string($firstPin) || $firstPin === '') {
            $preflightReason = 'TRUSTED_INSTALLED_VERSION_MISSING';
        } elseif ($version === null || base_path() !== $running) {
            $preflightReason = 'INSTALLED_RUNTIME_IDENTITY_UNVERIFIED';
        } elseif (! is_string($origin) || $origin === '') {
            $preflightReason = 'TRUSTED_RELEASE_ORIGIN_MISSING';
        } elseif (! is_string($key) || $key === '') {
            $preflightReason = 'TRUSTED_RELEASE_PUBLIC_KEY_MISSING';
        } elseif (file_exists($home.'/.walidsite-update-maintenance.json')
            || is_link($home.'/.walidsite-update-maintenance.json')) {
            $preflightReason = 'RELEASE_MAINTENANCE_ACTIVE';
        }
        if ($preflightReason !== null) {
            $observed = (new \\App\\Domain\\Deployment\\TrustedInstalledVersion())->read(
                $running.'/VERSION',
            );
            if ($observed !== null) {
                $private = $original.'/storage/app/private';
                if (is_dir($private) && ! is_link($private)) {
                    (new \\App\\Domain\\Deployment\\CpanelReleaseCronPoller())->recordBlockedPreflight(
                        $private.'/release-automation', $public, $observed, $preflightReason,
                    );
                }
            }
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
        if ($record['state'] === 'BLOCKED'
            && $record['reason'] === 'VERIFIED_HOST_UPDATE_GATE_REQUIRED'
            && config('site.release_cron_apply_enabled') === true) {
            // Environment flags are NEVER a hosting capability. The trusted
            // adapter must be registered by a host operator and independently
            // attest foreign writers, isolated MariaDB restore and live TLS.
            if (! $app->bound(\App\Domain\Deployment\CpanelOwnerCodeUpdateHost::class)
                || ! is_string($record['available_version'])
                || ! is_string($record['source_sha'])) {
                throw new RuntimeException('Independent scheduled host release capability unavailable.');
            }
            /** @var \App\Domain\Deployment\CpanelOwnerCodeUpdateHost $host */
            $host = $app->make(\App\Domain\Deployment\CpanelOwnerCodeUpdateHost::class);
            $installed = (new \App\Domain\Deployment\CpanelCronTrustedCodeInstaller())->install(
                $home, $running, $public, $version,
                $record['available_version'], $record['source_sha'],
                $origin, $key, $host, $writerLease,
                \Illuminate\Support\Facades\DB::connection()->getPdo(),
            );
            // install() has already released the promoted writer lease.
            // Failure to record journal or status is NEVER a fake rollback:
            // the independently health-verified code remains installed.
            (new \App\Domain\Deployment\CpanelOwnerCodeUpdateHistory())->record(
                $home, $installed, (string) config('app.key'),
            );
            $first = (new \App\Domain\Deployment\TrustedInstalledVersion())->read(
                $original.'/VERSION',
            );
            if ($first === null) {
                throw new RuntimeException('Installed release pin missing after cutover.');
            }
            (new \App\Domain\Deployment\CpanelReleaseCronPoller())->recordDeployed(
                $parent.'/release-automation', $public, $first, $installed,
            );
            $status = 'DEPLOYED';
        }
    } finally {
        $writerLease->end();
    }

    // Only a generic, redacted state is sent to scheduled CLI output.
    fwrite(STDOUT, 'WalidSite release poll: '.$status.PHP_EOL);
    exit(in_array($status, ['UP_TO_DATE', 'DEPLOYED'], true) ? 0 : 1);
} catch (\Throwable) {
    fwrite(STDERR, 'WalidSite release poll: BLOCKED'.PHP_EOL);
    exit(2);
}

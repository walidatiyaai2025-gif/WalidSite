<?php

return [
    // First-run setup requires an explicit server-side, 32+ character secret.
    // Never put the token in source control or a public query string.
    'setup_enabled' => filter_var(env('SITE_SETUP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'setup_token' => (string) env('SITE_SETUP_TOKEN', ''),
    'setup_database_ready' => filter_var(env('SITE_SETUP_DATABASE_READY', false), FILTER_VALIDATE_BOOLEAN),
    // When an immutable cPanel release is active, the dispatcher pins
    // environmentPath() to the original private application root.
    'environment_path' => app()->environmentPath().'/.env',
    'bootstrap_path' => storage_path('app/site-first-run.json'),
    'install_lock_path' => storage_path('app/site-installed.lock'),

    // The UI only applies reviewed migrations present in the deployed code.
    // Code patches themselves must arrive via the trusted release process.
    'updates_enabled' => (bool) env('SITE_UPDATES_ENABLED', true),
    'auto_migrate' => (bool) env('SITE_AUTO_MIGRATE', false),
    'release_ref' => (string) env('SITE_RELEASE_REF', 'unidentified'),

    // Pinned trust policy: the PUBLIC verification key and publisher origin
    // are server-only configuration, never sourced from an HTTP parameter.
    // Empty defaults disable discovery safely; they never authorize Apply.
    'trusted_installed_version' => (string) env('SITE_TRUSTED_INSTALLED_VERSION', ''),
    'trusted_release_origin' => (string) env('SITE_TRUSTED_RELEASE_ORIGIN', ''),
    'trusted_release_public_key' => (string) env('SITE_TRUSTED_RELEASE_PUBLIC_KEY', ''),

    // First-install ZIP includes private CLI Cron entrypoint but never
    // enables network polling or update execution without explicit opt-in.
    // Even when enabled, the poller is read-only and cannot switch PHP code.
    'release_cron_check_enabled' => filter_var(
        env('SITE_RELEASE_CRON_CHECK_ENABLED', false), FILTER_VALIDATE_BOOLEAN
    ),
];

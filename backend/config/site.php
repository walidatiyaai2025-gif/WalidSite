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
    // Public APK delivery is OFF by default. Only a signed, post-live HTTPS
    // pairing with the exact active Web SHA and pinned production signer may
    // enable the stable /downloads/android/latest route. QA APKs never qualify.
    'android_download_enabled' => filter_var(
        env('SITE_ANDROID_DOWNLOAD_ENABLED', false), FILTER_VALIDATE_BOOLEAN
    ),
    'android_trusted_signer_sha256' => (string) env('SITE_ANDROID_TRUSTED_SIGNER_SHA256', ''),


    // Private 15-minute Cron discovery is disabled on first install.
    // Full CODE-ONLY auto apply is a separate explicit operator opt-in and
    // REQUIRES a registered real CpanelOwnerCodeUpdateHost with independent
    // foreign-writer isolation, disposable MariaDB restore and live TLS probe.
    'release_cron_check_enabled' => filter_var(
        env('SITE_RELEASE_CRON_CHECK_ENABLED', false), FILTER_VALIDATE_BOOLEAN
    ),
    'release_cron_apply_enabled' => filter_var(
        env('SITE_RELEASE_CRON_APPLY_ENABLED', false), FILTER_VALIDATE_BOOLEAN
    ),
];

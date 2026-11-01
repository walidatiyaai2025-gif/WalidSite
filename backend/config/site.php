<?php

return [
    // First-run setup requires an explicit server-side, 32+ character secret.
    // Never put the token in source control or a public query string.
    'setup_enabled' => (bool) env('SITE_SETUP_ENABLED', false),
    'setup_token' => (string) env('SITE_SETUP_TOKEN', ''),
    'install_lock_path' => storage_path('app/site-installed.lock'),

    // The UI only applies reviewed migrations present in the deployed code.
    // Code patches themselves must arrive via the trusted release process.
    'updates_enabled' => (bool) env('SITE_UPDATES_ENABLED', true),
    'auto_migrate' => (bool) env('SITE_AUTO_MIGRATE', false),
    'release_ref' => (string) env('SITE_RELEASE_REF', 'unidentified'),
];

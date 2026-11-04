<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SetupSessionCookieTest extends TestCase
{
    /**
     * The first /setup response is served before an .env exists. Persisting
     * APP_NAME during database configuration must not rename its session cookie
     * and discard the verified one-time setup-token possession session.
     */
    public function test_first_install_session_cookie_is_not_derived_from_app_name(): void
    {
        $configuration = file_get_contents(config_path('session.php'));

        $this->assertIsString($configuration);
        $this->assertStringNotContainsString("env('APP_NAME'", $configuration);
        $this->assertSame('walidsite-session', config('session.cookie'));
    }

    public function test_session_cookie_name_is_stable_across_installation_app_name_transition(): void
    {
        $before = (require config_path('session.php'))['cookie'];
        $originalName = config('app.name');

        try {
            config(['app.name' => 'laravel']);
            $duringFirstBoot = (require config_path('session.php'))['cookie'];

            config(['app.name' => 'WalidSite']);
            $afterEnvironmentSave = (require config_path('session.php'))['cookie'];
        } finally {
            config(['app.name' => $originalName]);
        }

        $this->assertSame('walidsite-session', $before);
        $this->assertSame($duringFirstBoot, $afterEnvironmentSave);
        $this->assertSame('walidsite-session', $afterEnvironmentSave);
    }
}

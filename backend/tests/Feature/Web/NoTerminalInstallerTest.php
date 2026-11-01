<?php

namespace Tests\Feature\Web;

use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\FirstRunBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NoTerminalInstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_encryption_key_bootstraps_stable_private_token_before_web_middleware(): void
    {
        $path = storage_path('app/no-terminal-bootstrap-test.json');
        $locked = storage_path('app/no-terminal-lock-test');
        $env = $this->app['env'];
        $before = config('app.key');
        config(['app.key' => null, 'site.bootstrap_path' => $path,
            'site.install_lock_path' => $locked]);
        $this->app->instance('env', 'production');
        try {
            (new FirstRunBootstrap())->prepare();
            $first = json_decode((string) file_get_contents($path), true);
            $this->assertSame($first['key'], config('app.key'));
            $this->assertSame($first['token'], config('site.setup_token'));
            $this->assertTrue(config('site.setup_enabled'));
            $this->assertFalse(config('app.debug'));
            $this->assertSame('file', config('session.driver'));
            config(['app.key' => null]);
            (new FirstRunBootstrap())->prepare();
            $this->assertSame($first['key'], config('app.key'));
            $this->assertSame($first['token'], config('site.setup_token'));
        } finally {
            $this->app->instance('env', $env);
            config(['app.key' => $before]);
            @unlink($path);
        }
    }

    public function test_browser_wizard_accepts_database_settings_without_terminal_or_env_upload(): void
    {
        $path = storage_path('app/no-terminal-env-test');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('v', 40),
            'site.setup_database_ready' => false,
            'site.environment_path' => $path,
        ]);
        $this->get('/setup?lang=ar')->assertOk()->assertSee('name="token"', false);
        $this->post('/setup/unlock', ['token' => str_repeat('v', 40)])->assertRedirect('/setup');
        $this->get('/setup?lang=ar')->assertOk()
            ->assertSee('name="db_database"', false)
            ->assertSee('name="db_password"', false)
            ->assertSee('name="site_url"', false);
        $this->post('/setup/database')->assertForbidden();

        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldReceive('probe')->once()->andReturn(true);
        $this->app->instance(DatabaseConfiguration::class, $database);

        try {
            $this->withHeader('Host', 'untrusted-preview.example.test')
                ->post('/setup/configure', [
                'site_url' => 'https://walid.example.test',
                'db_host' => 'localhost',
                'db_port' => 3306,
                'db_database' => 'walidatiya_site',
                'db_username' => 'walidatiya_writer',
                'db_password' => 'a-unique-db-password',
            ])->assertRedirect('/setup');
            $contents = file_get_contents($path);
            $this->assertStringContainsString('APP_URL="https://walid.example.test"', $contents);
            $this->assertStringNotContainsString('untrusted-preview.example.test', $contents);
            $this->assertStringContainsString('DB_DATABASE="walidatiya_site"', $contents);
            $this->assertStringContainsString('SITE_SETUP_DATABASE_READY=true', $contents);
            $this->assertStringContainsString('APP_DEBUG=false', $contents);
            $this->assertStringContainsString('APP_KEY="base64:', $contents);
            $this->assertStringContainsString('SESSION_DRIVER="file"', $contents);
        } finally {
            @unlink($path);
        }
    }

    public function test_unsafe_site_url_is_rejected_before_database_probe_or_env_write(): void
    {
        $path = storage_path('app/site-invalid-url-'.bin2hex(random_bytes(6)));
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('u', 40),
            'site.setup_database_ready' => false,
            'site.environment_path' => $path,
        ]);
        $this->post('/setup/unlock', ['token' => str_repeat('u', 40)])->assertRedirect('/setup');

        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldNotReceive('probe');
        $this->app->instance(DatabaseConfiguration::class, $database);
        try {
            $this->post('/setup/configure', [
                'site_url' => 'https://trusted.example.test/?next=https://spoofed.test',
                'db_host' => 'localhost', 'db_port' => 3306,
                'db_database' => 'site', 'db_username' => 'writer',
                'db_password' => 'private-password',
            ])->assertSessionHasErrors('site_url');
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_unreachable_saved_database_can_be_reconfigured_from_wizard_without_terminal(): void
    {
        $path = storage_path('app/no-terminal-recovery-test');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('r', 40),
            'site.setup_database_ready' => true,
            'site.environment_path' => $path,
        ]);
        $this->post('/setup/unlock', ['token' => str_repeat('r', 40)])
            ->assertRedirect('/setup');

        $defaultConnection = config('database.default');
        // Model the connection disappearing after .env was first saved,
        // without depending on real network failure or MySQL credentials.
        config(['database.default' => 'missing-setup-database-connection']);
        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldReceive('probe')->once()->andReturn(true);
        $this->app->instance(DatabaseConfiguration::class, $database);

        try {
            $this->get('/setup?lang=en')->assertOk()
                ->assertSee('The saved database connection is unavailable')
                ->assertSee('name="db_database"', false)
                ->assertDontSee('old-private-database-password');

            $this->post('/setup/configure', [
                'site_url' => 'https://recovered.example.test',
                'db_host' => 'localhost',
                'db_port' => 3306,
                'db_database' => 'recovered_site',
                'db_username' => 'recovered_writer',
                'db_password' => 'new-private-database-password',
            ])->assertRedirect('/setup');

            $contents = (string) file_get_contents($path);
            $this->assertStringContainsString('DB_DATABASE="recovered_site"', $contents);
            $this->assertStringContainsString('DB_USERNAME="recovered_writer"', $contents);
            $this->assertStringContainsString('DB_PASSWORD="new-private-database-password"', $contents);
            $this->assertStringContainsString('SITE_SETUP_DATABASE_READY=true', $contents);
            $this->assertStringNotContainsString('old-private-database-password', $contents);
        } finally {
            config(['database.default' => $defaultConnection]);
            @unlink($path);
        }
    }

    public function test_healthy_configured_database_cannot_be_switched_by_setup_request(): void
    {
        $path = storage_path('app/no-terminal-healthy-db-test');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('h', 40),
            'site.setup_database_ready' => true,
            'site.environment_path' => $path,
        ]);
        $this->post('/setup/unlock', ['token' => str_repeat('h', 40)])
            ->assertRedirect('/setup');
        // RefreshDatabase provisioned SQLite and all migrations. A healthy
        // connection must not be replaced, even with a valid setup session.
        $this->get('/setup?lang=en')->assertOk()
            ->assertDontSee('The saved database connection is unavailable');
        $this->post('/setup/configure', [
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => 'different_database',
            'db_username' => 'different_writer',
            'db_password' => 'different-private-password',
        ])->assertForbidden();
        $this->assertFileDoesNotExist($path);
    }

    public function test_invalid_database_validation_never_flashes_password_but_preserves_safe_fields(): void
    {
        $token = str_repeat('v', 40);
        $path = storage_path('app/no-terminal-validation-secret-test');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => false,
            'site.environment_path' => $path,
        ]);

        try {
            $this->post('https://localhost/setup/unlock', ['token' => $token])
                ->assertRedirect('/setup');

            $secret = 'do-not-flash-this-private-db-password-123!';
            // Validation rejects the port before any database probe or .env
            // write; redirect input must retain harmless form fields ONLY.
            $response = $this->post('https://localhost/setup/configure', [
                'site_url' => 'https://example.test',
                'db_host' => 'localhost',
                'db_port' => 70000,
                'db_database' => 'private_db',
                'db_username' => 'private_user',
                'db_password' => $secret,
            ]);
            $response->assertSessionHasErrors('db_port')
                ->assertSessionHas('_old_input.db_host', 'localhost')
                ->assertSessionHas('_old_input.site_url', 'https://example.test')
                ->assertSessionMissing('_old_input.db_password')
                ->assertDontSee($secret);

            $this->get('https://localhost/setup')
                ->assertOk()
                ->assertDontSee($secret)
                ->assertSee('name="db_password"', false);
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_invalid_setup_unlock_token_is_never_kept_in_old_session_input(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('v', 40),
            'site.setup_database_ready' => false,
        ]);

        $secret = str_repeat('Z', 257);
        $this->post('https://localhost/setup/unlock', ['token' => $secret])
            ->assertSessionHasErrors('token')
            ->assertSessionMissing('_old_input.token')
            ->assertDontSee($secret);
        $this->get('https://localhost/setup')
            ->assertOk()
            ->assertDontSee($secret)
            ->assertSee('name="token"', false);
    }

    public function test_site_url_rejects_internal_addresses_in_production(): void
    {
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->assertNull(app(DatabaseConfiguration::class)->canonicalSiteUrl('https://127.0.0.1'));
            $this->assertNull(app(DatabaseConfiguration::class)->canonicalSiteUrl('https://192.168.1.1'));
            $this->assertNull(app(DatabaseConfiguration::class)->canonicalSiteUrl('https://169.254.0.2'));
            $this->assertNull(app(DatabaseConfiguration::class)->canonicalSiteUrl('https://[::1]'));
            $this->assertNull(app(DatabaseConfiguration::class)->canonicalSiteUrl('https://[fc00::1]'));
            $this->assertSame('https://8.8.8.8', app(DatabaseConfiguration::class)->canonicalSiteUrl('https://8.8.8.8'));
            $this->assertSame('https://walidatiya.com', app(DatabaseConfiguration::class)->canonicalSiteUrl('https://walidatiya.com'));
        } finally {
            $this->app->instance('env', $old);
        }
    }

    public function test_invalid_production_site_ip_does_not_probe_or_save_database(): void
    {
        $env = $this->app['env'];
        $path = storage_path('app/invalid-private-site-test');
        config(['site.setup_enabled' => true,
            'site.setup_token' => str_repeat('p', 40),
            'site.setup_database_ready' => false,
            'site.environment_path' => $path]);
        $this->app->instance('env', 'production');
        // Production enforces CSRF before route validation. Supply a valid
        // session token so this regression exercises canonical URL safety.
        $csrf = str_repeat('c', 40);
        $this->withSession(['_token' => $csrf]);
        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldNotReceive('probe');
        $this->app->instance(DatabaseConfiguration::class, $database);
        try {
            $this->post('https://localhost/setup/unlock',
                ['token' => str_repeat('p', 40), '_token' => $csrf])->assertRedirect('/setup');
            // Unlock regenerates the session and rotates the CSRF token.
            $csrf = session()->token();
            $this->post('https://localhost/setup/configure', [
                'site_url' => 'https://127.0.0.1',
                'db_host' => 'localhost', 'db_port' => 3306,
                'db_database' => 'site', 'db_username' => 'writer',
                'db_password' => 'not-saved', '_token' => $csrf,
            ])->assertSessionHasErrors('site_url');
            $this->assertFileDoesNotExist($path);
        } finally {
            $this->app->instance('env', $env);
            @unlink($path);
        }
    }

    public function test_invalid_database_form_never_overwrites_private_configuration(): void
    {
        $path = storage_path('app/no-terminal-denied-test');
        config(['site.setup_enabled' => true,
            'site.setup_token' => str_repeat('a', 40),
            'site.environment_path' => $path,
        ]);
        $this->post('/setup/configure', [
            'db_host' => 'localhost', 'db_port' => 3306,
            'db_database' => 'other', 'db_username' => 'writer',
            'db_password' => 'secret',
        ])->assertForbidden();
        $this->assertFileDoesNotExist($path);
    }
    public function test_production_wizard_rejects_private_dns_site_url_before_database_probe_or_private_env_write(): void
    {
        $env = $this->app['env'];
        $path = storage_path('app/private-dns-site-test-'.bin2hex(random_bytes(6)));
        $token = str_repeat('d', 40);
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => false,
            'site.environment_path' => $path,
        ]);
        $this->app->instance('env', 'production');

        // Exercise HTTPS, valid session unlock and CSRF, then enforce the
        // canonical origin restriction at the real setup POST boundary.
        $this->withSession(['_token' => str_repeat('c', 40)]);
        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldNotReceive('probe');
        $database->shouldNotReceive('save');
        $this->app->instance(DatabaseConfiguration::class, $database);

        try {
            $this->post('https://localhost/setup/unlock', [
                'token' => $token,
                '_token' => str_repeat('c', 40),
            ])->assertRedirect('/setup');
            $csrf = session()->token();
            $password = 'do-not-store-my-private-database-password';

            foreach ([
                'https://metadata.google.internal',
                'https://office.lan',
                'https://gateway.home.arpa',
                'https://service.svc',
                'https://site.example.test',
            ] as $unsafe) {
                $this->post('https://localhost/setup/configure', [
                    'site_url' => $unsafe,
                    'db_host' => 'localhost',
                    'db_port' => 3306,
                    'db_database' => 'fresh_site',
                    'db_username' => 'database_user',
                    'db_password' => $password,
                    '_token' => $csrf,
                ])->assertSessionHasErrors('site_url')
                  ->assertSessionMissing('_old_input.db_password')
                  ->assertDontSee($password);
                $this->assertFileDoesNotExist($path);
            }
        } finally {
            $this->app->instance('env', $env);
            @unlink($path);
        }
    }


    public function test_setup_saving_replaces_all_duplicate_managed_keys_and_preserves_unrelated_settings(): void
    {
        $path = storage_path('app/installer-env-duplicates-'.bin2hex(random_bytes(6)));
        $previousKey = config('app.key');
        $previousToken = config('site.setup_token');
        $password = 'New pass "quoted" $cost \\ root';
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'site.setup_token' => str_repeat('s', 40),
            'site.environment_path' => $path,
        ]);
        file_put_contents($path, "# Custom configuration must remain\r\n"
            ."APP_URL=\"https://stale-first.example.org\"\r\n"
            ."export APP_URL=\"https://stale-second.example.org\"\r\n"
            ."DB_PASSWORD=\"stale-first\"\r\n"
            ."OTHER_DB_PASSWORD=\"unmanaged-value\"\r\n"
            ." export DB_PASSWORD = \"stale-second\"\r\n"
            ."DB_PASSWORD=\"stale-third\"\r\n"
            ."SITE_SETUP_TOKEN=\"stale-setup-token\"\r\n"
            ."export SITE_SETUP_TOKEN=\"old-setup-token\"\r\n"
            ."CUSTOM_SETTING=\"retained\"\r\n");

        try {
            app(DatabaseConfiguration::class)->save([
                'db_host' => 'localhost',
                'db_port' => 3306,
                'db_database' => 'verified_site',
                'db_username' => 'verified_writer',
                'db_password' => $password,
            ], 'https://walidsite.example.org');

            $contents = (string) file_get_contents($path);
            foreach (['APP_URL', 'DB_PASSWORD', 'SITE_SETUP_TOKEN', 'APP_KEY',
                'SITE_SETUP_ENABLED', 'SITE_SETUP_DATABASE_READY'] as $name) {
                $this->assertSame(1, preg_match_all(
                    '/^[ \t]*(?:export[ \t]+)?'.preg_quote($name, '/').'[ \t]*=/m',
                    $contents
                ), 'Managed key must appear only once: '.$name);
            }
            $this->assertStringContainsString('APP_URL="https://walidsite.example.org"', $contents);
            $this->assertStringContainsString('DB_PASSWORD="New pass \\"quoted\\" \\$cost \\\\ root"', $contents);
            $this->assertStringContainsString('SITE_SETUP_TOKEN="'.str_repeat('s', 40).'"', $contents);
            $this->assertStringContainsString("OTHER_DB_PASSWORD=\"unmanaged-value\"\r\n", $contents);
            $this->assertStringContainsString("CUSTOM_SETTING=\"retained\"\r\n", $contents);
            $this->assertStringContainsString("# Custom configuration must remain\r\n", $contents);
            foreach (['stale-first', 'stale-second', 'stale-third',
                'stale-setup-token', 'old-setup-token'] as $stale) {
                $this->assertStringNotContainsString($stale, $contents);
            }
        } finally {
            config(['app.key' => $previousKey, 'site.setup_token' => $previousToken]);
            @unlink($path);
        }
    }

    public function test_finishing_setup_removes_every_historical_token_and_disables_all_duplicate_switches(): void
    {
        $path = storage_path('app/installer-env-lockout-'.bin2hex(random_bytes(6)));
        $bootstrap = storage_path('app/installer-bootstrap-lockout-'.bin2hex(random_bytes(6)));
        config([
            'site.environment_path' => $path,
            'site.bootstrap_path' => $bootstrap,
        ]);
        file_put_contents($path, "# Preserve unrelated site configuration\n"
            ."SITE_SETUP_TOKEN=\"historical-secret-one\"\n"
            ."SITE_SETUP_ENABLED=true\n"
            ."export SITE_SETUP_TOKEN=\"historical-secret-two\"\n"
            ."export SITE_SETUP_ENABLED=true\n"
            ."SITE_SETUP_DATABASE_READY=true\n"
            ." SITE_SETUP_DATABASE_READY = true\n"
            ."CUSTOM_SETTING=\"preserved\"\n");
        file_put_contents($bootstrap, '{}');

        try {
            app(DatabaseConfiguration::class)->disableSetup();
            $contents = (string) file_get_contents($path);
            foreach (['SITE_SETUP_ENABLED', 'SITE_SETUP_TOKEN',
                'SITE_SETUP_DATABASE_READY'] as $name) {
                $this->assertSame(1, preg_match_all(
                    '/^[ \t]*(?:export[ \t]+)?'.preg_quote($name, '/').'[ \t]*=/m',
                    $contents
                ));
            }
            $this->assertStringContainsString('SITE_SETUP_ENABLED=false', $contents);
            $this->assertStringContainsString('SITE_SETUP_TOKEN=""', $contents);
            $this->assertStringContainsString('SITE_SETUP_DATABASE_READY=false', $contents);
            $this->assertStringContainsString('CUSTOM_SETTING="preserved"', $contents);
            $this->assertStringNotContainsString('historical-secret', $contents);
            $this->assertFileDoesNotExist($bootstrap);
        } finally {
            @unlink($path);
            @unlink($bootstrap);
        }
    }
}

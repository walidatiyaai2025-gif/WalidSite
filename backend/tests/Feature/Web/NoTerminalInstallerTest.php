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
            ->assertSee('name="db_password"', false);
        $this->post('/setup/database')->assertForbidden();

        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldReceive('probe')->once()->andReturn(true);
        $this->app->instance(DatabaseConfiguration::class, $database);

        try {
            $this->post('/setup/configure', [
                'db_host' => 'localhost',
                'db_port' => 3306,
                'db_database' => 'walidatiya_site',
                'db_username' => 'walidatiya_writer',
                'db_password' => 'a-unique-db-password',
            ])->assertRedirect('/setup');
            $contents = file_get_contents($path);
            $this->assertStringContainsString('DB_DATABASE="walidatiya_site"', $contents);
            $this->assertStringContainsString('SITE_SETUP_DATABASE_READY=true', $contents);
            $this->assertStringContainsString('APP_DEBUG=false', $contents);
            $this->assertStringContainsString('APP_KEY="base64:', $contents);
            $this->assertStringContainsString('SESSION_DRIVER="file"', $contents);
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
}

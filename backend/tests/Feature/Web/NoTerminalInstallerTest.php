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

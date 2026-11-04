<?php

namespace Tests\Feature\Api;

use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\FirstRunBootstrap;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class FirstRunSafetyTest extends TestCase
{
    public function test_private_bootstrap_is_atomically_published_with_restrictive_permissions_under_open_umask(): void
    {
        $path = storage_path('app/first-run-atomic-'.bin2hex(random_bytes(6)).'.json');
        $lock = $path.'.installed';
        $savedKey = config('app.key');
        $savedPath = config('site.bootstrap_path');
        $savedLock = config('site.install_lock_path');
        $savedEnvironment = $this->app['env'];
        $umask = umask(0000);

        try {
            config(['app.key' => null, 'site.bootstrap_path' => $path,
                'site.install_lock_path' => $lock]);
            $this->app->instance('env', 'production');

            (new FirstRunBootstrap())->prepare();
            $this->assertFileExists($path);
            clearstatcache(true, $path);
            $this->assertSame(0, fileperms($path) & 0077);
            $this->assertSame(1, stat($path)['nlink']);
            $data = json_decode((string) file_get_contents($path), true);
            $this->assertSame($data['key'], config('app.key'));
            $this->assertSame($data['token'], config('site.setup_token'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['token']);

            // Subsequent concurrent/failed requests must reuse the same key
            // and possession token, never silently rotate existing state.
            config(['app.key' => null]);
            (new FirstRunBootstrap())->prepare();
            $this->assertSame($data, json_decode((string) file_get_contents($path), true));
        } finally {
            umask($umask);
            $this->app->instance('env', $savedEnvironment);
            config(['app.key' => $savedKey, 'site.bootstrap_path' => $savedPath,
                'site.install_lock_path' => $savedLock]);
            @unlink($path);
        }
    }

    public function test_first_boot_refuses_world_readable_or_partial_existing_secret_state(): void
    {
        $path = storage_path('app/first-run-unsafe-'.bin2hex(random_bytes(6)).'.json');
        $lock = $path.'.installed';
        $savedKey = config('app.key');
        $savedPath = config('site.bootstrap_path');
        $savedLock = config('site.install_lock_path');
        $savedEnvironment = $this->app['env'];

        try {
            config(['app.key' => null, 'site.bootstrap_path' => $path,
                'site.install_lock_path' => $lock]);
            $this->app->instance('env', 'production');
            file_put_contents($path, json_encode([
                'key' => 'base64:'.base64_encode(random_bytes(32)),
                'token' => bin2hex(random_bytes(32)),
            ], JSON_THROW_ON_ERROR));
            chmod($path, 0644);
            try {
                (new FirstRunBootstrap())->prepare();
                $this->fail('An insecure first-run token file must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unsafe', strtolower($exception->getMessage()));
            }

            chmod($path, 0600);
            file_put_contents($path, '');
            try {
                (new FirstRunBootstrap())->prepare();
                $this->fail('An incomplete first-run token file must never be reused.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unavailable', strtolower($exception->getMessage()));
            }
            $this->assertNull(config('app.key'));
        } finally {
            $this->app->instance('env', $savedEnvironment);
            config(['app.key' => $savedKey, 'site.bootstrap_path' => $savedPath,
                'site.install_lock_path' => $savedLock]);
            @unlink($path);
        }
    }

    public function test_first_boot_never_reinitializes_after_installation_lock(): void
    {
        $path = storage_path('app/first-run-locked-'.bin2hex(random_bytes(6)).'.json');
        $lock = $path.'.installed';
        $savedKey = config('app.key');
        $savedPath = config('site.bootstrap_path');
        $savedLock = config('site.install_lock_path');
        $savedEnvironment = $this->app['env'];
        try {
            file_put_contents($lock, 'installed');
            config(['app.key' => null, 'site.bootstrap_path' => $path,
                'site.install_lock_path' => $lock]);
            $this->app->instance('env', 'production');
            $this->expectException(RuntimeException::class);
            (new FirstRunBootstrap())->prepare();
        } finally {
            $this->app->instance('env', $savedEnvironment);
            config(['app.key' => $savedKey, 'site.bootstrap_path' => $savedPath,
                'site.install_lock_path' => $savedLock]);
            @unlink($lock);
            @unlink($path);
        }
        $this->assertFileDoesNotExist($path);
    }

    public function test_external_host_rejects_loopback_or_private_canonical_url_despite_testing_env(): void
    {
        $previousRequest = $this->app->make('request');
        try {
            // Reproduces inherited APP_ENV=testing on an otherwise public host.
            $this->assertTrue(app()->environment('testing'));
            $this->app->instance('request', Request::create(
                'https://walidatiya.com/setup/configure', 'POST'
            ));
            $database = new DatabaseConfiguration();
            $this->assertNull($database->canonicalSiteUrl('http://localhost'));
            $this->assertNull($database->canonicalSiteUrl('https://localhost'));
            $this->assertNull($database->canonicalSiteUrl('http://127.0.0.1'));
            $this->assertNull($database->canonicalSiteUrl('https://127.0.0.1'));
            $this->assertNull($database->canonicalSiteUrl('https://10.0.0.8'));
            $this->assertSame('https://walidatiya.com',
                $database->canonicalSiteUrl('https://walidatiya.com/'));
            $this->assertSame('https://valid.example.test',
                $database->canonicalSiteUrl('https://valid.example.test'));
        } finally {
            $this->app->instance('request', $previousRequest);
        }
    }

    public function test_real_local_development_host_keeps_explicit_http_loopback_setup(): void
    {
        $previousRequest = $this->app->make('request');
        try {
            $this->assertTrue(app()->environment('testing'));
            $this->app->instance('request', Request::create('http://127.0.0.1/setup'));
            $database = new DatabaseConfiguration();
            $this->assertSame('http://127.0.0.1',
                $database->canonicalSiteUrl('http://127.0.0.1'));
            $this->assertNull($database->canonicalSiteUrl('http://walidatiya.com'));
        } finally {
            $this->app->instance('request', $previousRequest);
        }
    }
}

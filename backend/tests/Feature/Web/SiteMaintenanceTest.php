<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\User;
use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SiteMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_is_not_public_by_default(): void
    {
        $this->get('/setup')->assertNotFound();
        $this->get('/')->assertOk();
    }

    public function test_setup_redirects_new_site_requires_server_secret_and_uses_session(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('x', 40),
            'site.setup_database_ready' => true,
        ]);

        $this->get('/')->assertRedirect('/setup');
        $this->get('/setup?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('name="token"', false)
            ->assertDontSee('Step 3: Activate owner account');

        $this->post('/setup/database')->assertForbidden();
        $this->post('/setup/unlock', ['token' => 'wrong'])->assertSessionHasErrors('token');
        $this->post('/setup/unlock', ['token' => str_repeat('x', 40)])
            ->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Pending migrations');
        $this->post('/setup/database')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Activate owner account');
    }


    public function test_full_install_activates_only_canonical_cv_owner_and_permanently_locks_setup(): void
    {
        $token = str_repeat('s', 40);
        $path = storage_path('app/site-install-test-'.bin2hex(random_bytes(8)).'.lock');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.install_lock_path' => $path,
            'site.setup_database_ready' => true,
            'site.environment_path' => storage_path('app/site-test-environment'),
            'site.bootstrap_path' => storage_path('app/site-test-bootstrap'),
        ]);

        try {
            $this->post('/setup/unlock', ['token' => $token])->assertRedirect('/setup');
            $this->post('/setup/database')->assertRedirect('/setup');

            $password = 'An-Independent-Test-Password-123!';
            $this->post('/setup/finish', [
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertRedirect('/admin/login');

            $ownerEmail = app(SiteInstaller::class)->ownerEmail();
            $owner = User::where('email', $ownerEmail)->firstOrFail();
            $this->assertSame('active', $owner->status);
            $this->assertTrue($owner->hasRole('owner'));
            $this->assertTrue(Hash::check($password, $owner->password_hash));
            $this->assertFileExists($path);
            $this->get('/setup')->assertNotFound();
            $this->get('/')->assertOk();
        } finally {
            @unlink($path);
            @unlink((string) config('site.environment_path'));
        }
    }

    public function test_setup_is_locked_when_an_owner_already_exists_even_without_marker(): void
    {
        config(['site.setup_enabled' => true, 'site.setup_token' => str_repeat('t', 40)]);
        $owner = User::create([
            'email' => 'protected@example.test',
            'password_hash' => 'already-configured',
            'status' => 'active',
        ]);
        $role = Role::firstOrCreate(['name' => 'owner']);
        $owner->roles()->attach($role->id);

        $this->get('/setup')->assertNotFound();
        $this->get('/')->assertOk();
    }

    public function test_update_center_is_owner_only_and_reports_real_migration_status(): void
    {
        $this->get('/admin/updates')->assertRedirect('/admin/login');
        $user = User::create([
            'email' => 'regular@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($adminRole->id);
        $this->actingAs($user);
        $this->get('/admin/updates')->assertForbidden();
        $this->post('/admin/updates/apply')->assertForbidden();

        $ownerRole = Role::firstOrCreate(['name' => 'owner']);
        $user->roles()->attach($ownerRole->id);
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الترحيلات المنتظرة');
        $this->assertTrue(app(SiteUpdater::class)->inspect()['ready']);
        $this->post('/admin/updates/apply')->assertRedirect();
    }

    public function test_manual_updates_can_be_disabled_without_hiding_status(): void
    {
        config(['site.updates_enabled' => false]);
        $owner = User::create([
            'email' => 'owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $role = Role::firstOrCreate(['name' => 'owner']);
        $owner->roles()->attach($role->id);
        $this->actingAs($owner)->get('/admin/updates')->assertOk();
        $this->post('/admin/updates/apply')->assertForbidden();
    }


    public function test_production_setup_rejects_plain_http_for_every_installer_step(): void
    {
        $previousEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');
        // This regression targets the transport guard; CSRF is independently
        // enforced and must not intercept the request before the guard.
        $this->withSession(['_token' => str_repeat('c', 40)]);
        $token = str_repeat('h', 40);
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => true,
        ]);

        try {
            $this->get('http://localhost/setup')->assertForbidden();
            $this->get('http://localhost/setup?lang=ar')->assertForbidden();
            $this->post('http://localhost/setup/unlock', ['_token' => session()->token(), 'token' => $token])->assertForbidden();
            $this->post('http://localhost/setup/configure', [
                '_token' => session()->token(),
                'db_host' => 'localhost',
                'db_port' => 3306,
                'db_database' => 'private_data',
                'db_username' => 'private_writer',
                'db_password' => 'do-not-log-this-secret',
            ])->assertForbidden();
            $this->post('http://localhost/setup/database', ['_token' => session()->token()])->assertForbidden();
            $this->post('http://localhost/setup/finish', [
                '_token' => session()->token(),
                'password' => 'Never-Accept-Over-Plain-HTTP!',
                'password_confirmation' => 'Never-Accept-Over-Plain-HTTP!',
            ])->assertForbidden();

            $this->assertNotTrue(session('site_setup_authorized'));
            $this->assertNotTrue(session('site_setup_migrated'));
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }
    }

    public function test_production_https_setup_works_and_cannot_downgrade_after_unlock(): void
    {
        $previousEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => str_repeat('c', 40)]);
        $token = str_repeat('s', 40);
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => false,
        ]);

        try {
            $this->get('https://localhost/setup')->assertOk()->assertSee('Server setup token');
            $this->post('https://localhost/setup/unlock', ['_token' => session()->token(), 'token' => $token])->assertRedirect();
            $this->get('https://localhost/setup')->assertOk()->assertSee('name="db_password"', false);

            // Successful HTTPS authorization does not permit HTTP downgrade.
            $this->get('http://localhost/setup')->assertForbidden();
            $this->post('http://localhost/setup/unlock', ['_token' => session()->token(), 'token' => $token])->assertForbidden();
            $this->post('http://localhost/setup/database', ['_token' => session()->token()])->assertForbidden();
            $this->post('http://localhost/setup/finish', [
                '_token' => session()->token(),
                'password' => 'Strong-Test-Owner-Password-123!',
                'password_confirmation' => 'Strong-Test-Owner-Password-123!',
            ])->assertForbidden();

            $this->get('https://localhost/setup')->assertOk()->assertSee('name="db_password"', false);

            // A disabled installer stays indistinguishable (404).
            config(['site.setup_enabled' => false]);
            $this->get('http://localhost/setup')->assertNotFound();
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }
    }
}

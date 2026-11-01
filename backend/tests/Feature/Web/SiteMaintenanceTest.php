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
}

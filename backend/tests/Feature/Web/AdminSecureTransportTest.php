<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSecureTransportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::create([
            'email' => 'tls-admin-owner@example.test',
            'status' => 'active',
            'password_hash' => Hash::make('Synthetic-Strong-Password-123!'),
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return $user;
    }

    public function test_production_plaintext_admin_requests_fail_before_login_session_and_csrf(): void
    {
        $oldEnvironment = $this->app['env'];
        $owner = $this->owner();
        $this->app->instance('env', 'production');

        try {
            // GET must not render a credential-entry form over plaintext.
            $this->get('http://localhost/admin/login')->assertForbidden();
            // Malformed login input MUST hit the HTTPS guard before validator.
            $this->post('http://localhost/admin/login', [
                'email' => $owner->email,
            ])->assertForbidden();
            $this->post('http://localhost/admin/login', [
                'email' => $owner->email,
                'password' => 'Synthetic-Strong-Password-123!',
            ])->assertForbidden();
            $this->assertGuest('web');

            // All admin route files are covered, including ones registered
            // separately from web.php; even a missing CSRF token gets 403.
            foreach ([
                '/admin',
                '/admin/ai/providers',
                '/admin/ai/usage',
                '/admin/updates',
                '/admin/editorial/automation',
            ] as $path) {
                $this->get('http://localhost'.$path)->assertForbidden();
            }
            $this->post('http://localhost/admin/ai/providers', [
                'display_name' => 'Must not be created',
                'secret' => 'must-not-be-read',
            ])->assertForbidden();
            $this->post('http://localhost/admin/updates/apply')->assertForbidden();
            $this->post('http://localhost/admin/editorial/automation/policy', [
                'enabled' => 1,
            ])->assertForbidden();
            $this->assertDatabaseCount('ai_providers', 0);

            // Public, unauthenticated routes do not inherit admin TLS policy.
            $this->get('http://localhost/')->assertOk();
            $this->getJson('http://localhost/api/v1/public/profile')->assertOk();
        } finally {
            $this->app->instance('env', $oldEnvironment);
        }
    }

    public function test_production_https_still_supports_real_owner_login_and_admin_pages(): void
    {
        $oldEnvironment = $this->app['env'];
        $owner = $this->owner();
        $this->app->instance('env', 'production');

        try {
            $this->get('https://localhost/admin/login')->assertOk()
                ->assertSee('name="_token"', false);
            $this->post('https://localhost/admin/login', [
                'email' => $owner->email,
                'password' => 'Synthetic-Strong-Password-123!',
            ])->assertRedirect('/admin');
            $this->assertAuthenticatedAs($owner, 'web');

            $this->get('https://localhost/admin')->assertOk()
                ->assertSee('Administration overview');
            $this->get('https://localhost/admin/ai/providers')->assertOk();
            $this->get('https://localhost/admin/updates')->assertOk();
            $this->post('https://localhost/admin/logout')
                ->assertRedirect('/admin/login');
            $this->assertGuest('web');
        } finally {
            $this->app->instance('env', $oldEnvironment);
        }
    }

    public function test_local_development_http_admin_login_remains_available(): void
    {
        $owner = $this->owner();
        $this->get('http://localhost/admin/login')->assertOk();
        $this->post('http://localhost/admin/login', [
            'email' => $owner->email,
            'password' => 'Synthetic-Strong-Password-123!',
        ])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($owner, 'web');
    }
}

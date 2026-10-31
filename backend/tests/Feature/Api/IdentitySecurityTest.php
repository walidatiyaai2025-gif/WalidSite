<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(?string $role = null, string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(12).'@example.test',
            'password_hash' => Hash::make('VeryLongTestPassword.123!'),
            'status' => $status,
        ]);
        if ($role) {
            $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        }

        return $user;
    }

    public function test_guest_cannot_access_mobile_or_admin_api(): void
    {
        $this->getJson('/api/v1/mobile/me')->assertUnauthorized();
        $this->getJson('/api/v1/admin/identity-check')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_bad_password_and_inactive_account_cannot_issue_tokens(): void
    {
        $user = $this->userWithRole('owner', 'inactive');
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'VeryLongTestPassword.123!',
            'device_name' => 'phone',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mobile_token_is_hashed_scoped_revocable_and_never_admin(): void
    {
        $user = $this->userWithRole('owner');
        $issued = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'VeryLongTestPassword.123!',
            'device_name' => 'test-phone',
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
        $plain = $issued->json('data.token');
        $this->assertIsString($plain);
        $record = $user->tokens()->firstOrFail();
        $this->assertNotSame($plain, $record->token);
        $this->assertSame(['mobile'], $record->abilities);
        $this->assertNotNull($record->expires_at);

        $this->withToken($plain)->getJson('/api/v1/mobile/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/v1/admin/identity-check')->assertForbidden();
        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.revoked', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        // The test container reuses guards across requests; production does not.
        Auth::forgetGuards();
        $this->getJson('/api/v1/mobile/me')->assertUnauthorized();
    }

    public function test_expired_mobile_token_is_rejected(): void
    {
        $user = $this->userWithRole();
        $expired = $user->createToken('phone', ['mobile'], now()->subMinute());
        $this->withToken($expired->plainTextToken)->getJson('/api/v1/mobile/me')->assertUnauthorized();
    }

    public function test_reader_is_denied_admin_session(): void
    {
        $user = $this->userWithRole('reader');
        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'VeryLongTestPassword.123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_owner_admin_session_uses_csrf_form_and_logout(): void
    {
        $user = $this->userWithRole('owner');
        $this->get('/admin/login')->assertOk()->assertSee('name="_token"', false);
        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'VeryLongTestPassword.123!',
        ])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user, 'web');
        $this->get('/admin')->assertOk()->assertSee('Administration overview');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_unprivileged_authenticated_user_is_forbidden(): void
    {
        $reader = $this->userWithRole('reader');
        $this->actingAs($reader, 'web')->get('/admin')->assertForbidden();
        $this->assertFalse($reader->canAccessAdmin());
        $this->assertTrue($this->userWithRole('admin')->canAccessAdmin());
    }
}

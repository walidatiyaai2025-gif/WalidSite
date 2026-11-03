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

    public function test_mobile_login_accepts_case_variants_and_remains_mobile_scoped(): void
    {
        $user = $this->userWithRole('owner');
        $user->update(['email' => 'Mixed.Case@example.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mIXED.cASE@EXAMPLE.TEST',
            'password' => 'VeryLongTestPassword.123!',
            'device_name' => 'case-test-phone',
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertSame(1, $user->tokens()->count());
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/mobile/me')
            ->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'Mixed.Case@example.test');
        $this->getJson('/api/v1/admin/identity-check')->assertForbidden();
    }

    public function test_mobile_login_denies_ambiguous_legacy_case_collision(): void
    {
        // SQLite's legacy unique index permits case-distinct records. The
        // login must never select whichever matched row appears first.
        $first = $this->userWithRole();
        $second = $this->userWithRole();
        $first->update(['email' => 'Legacy.Collision@example.test']);
        $second->update(['email' => 'legacy.collision@EXAMPLE.TEST']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'LEGACY.COLLISION@example.test',
            'password' => 'VeryLongTestPassword.123!',
            'device_name' => 'ambiguous-phone',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mixed_case_login_still_denies_bad_password_and_inactive_account(): void
    {
        $user = $this->userWithRole();
        $user->update(['email' => 'Denied.Login@example.test']);
        $input = [
            'email' => 'DENIED.LOGIN@EXAMPLE.TEST',
            'password' => 'incorrect-password',
            'device_name' => 'bad-phone',
        ];

        $this->postJson('/api/v1/auth/login', $input)
            ->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $user->update(['status' => 'inactive']);
        $input['password'] = 'VeryLongTestPassword.123!';
        $this->postJson('/api/v1/auth/login', $input)
            ->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
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
    public function test_admin_identity_check_requires_persisted_admin_bearer_not_web_session(): void
    {
        // Sanctum's first-party session TransientToken answers true to can()
        // for every scope. A normal owner/admin web session must not acquire
        // personal-access-token privileges at the JSON API boundary.
        foreach (['owner', 'admin'] as $role) {
            Auth::forgetGuards();
            $user = $this->userWithRole($role);
            $this->actingAs($user, 'web')
                ->getJson('/api/v1/admin/identity-check')
                ->assertForbidden();
        }
    }

    public function test_admin_identity_check_rejects_wrong_scope_role_and_account_status(): void
    {
        $endpoint = '/api/v1/admin/identity-check';
        $this->getJson($endpoint)->assertUnauthorized();

        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'inactive'],
        ] as [$role, $scope, $status]) {
            Auth::forgetGuards();
            $user = $this->userWithRole($role, $status);
            $token = $user->createToken('admin-boundary-test', [$scope], now()->addHour());
            $this->withToken($token->plainTextToken)
                ->getJson($endpoint)->assertForbidden();
        }

        foreach (['owner', 'admin'] as $role) {
            Auth::forgetGuards();
            $user = $this->userWithRole($role);
            $token = $user->createToken('authorized-admin', ['admin'], now()->addHour());
            $this->withToken($token->plainTextToken)
                ->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('data.authorized', true)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }

}

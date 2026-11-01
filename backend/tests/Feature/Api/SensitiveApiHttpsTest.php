<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SensitiveApiHttpsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::create([
            'email' => 'https-api-owner@example.test',
            'password_hash' => Hash::make('Long-Synthetic-Password-123!'),
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return $user;
    }

    public function test_production_http_denies_passwords_and_bearer_tokens_before_authentication(): void
    {
        $previous = $this->app['env'];
        $this->app->instance('env', 'production');
        $owner = $this->owner();

        try {
            // A missing-password request must hit transport protection BEFORE
            // validation; no sensitive credential is ever accepted via HTTP.
            $this->postJson('http://localhost/api/v1/auth/login', [
                'email' => $owner->email,
            ])->assertForbidden();

            $this->postJson('http://localhost/api/v1/auth/login', [
                'email' => $owner->email,
                'password' => 'Long-Synthetic-Password-123!',
                'device_name' => 'test-phone',
            ])->assertForbidden();
            $this->assertDatabaseCount('personal_access_tokens', 0);

            // A valid token cannot be inspected, used or revoked via HTTP.
            $mobile = $owner->createToken('phone', ['mobile'], now()->addHour());
            $this->withToken($mobile->plainTextToken)
                ->getJson('http://localhost/api/v1/mobile/me')->assertForbidden();
            $this->postJson('http://localhost/api/v1/auth/logout')->assertForbidden();
            $this->assertDatabaseCount('personal_access_tokens', 1);

            Auth::forgetGuards();
            $this->withToken($owner->createToken('admin', ['admin'], now()->addHour())->plainTextToken);
            $this->getJson('http://localhost/api/v1/admin/identity-check')->assertForbidden();
            $this->getJson('http://localhost/api/v1/admin/ai/providers')->assertForbidden();
            $this->postJson('http://localhost/api/v1/admin/ai/providers', [])
                ->assertForbidden();
        } finally {
            $this->app->instance('env', $previous);
        }
    }

    public function test_production_https_preserves_normal_api_and_public_http_reads(): void
    {
        $previous = $this->app['env'];
        $this->app->instance('env', 'production');
        $owner = $this->owner();

        try {
            $response = $this->postJson('https://localhost/api/v1/auth/login', [
                'email' => $owner->email,
                'password' => 'Long-Synthetic-Password-123!',
                'device_name' => 'test-phone',
            ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');

            $token = $response->json('data.token');
            $this->assertIsString($token);
            $this->withToken($token)
                ->getJson('https://localhost/api/v1/mobile/me')
                ->assertOk()->assertJsonPath('data.id', $owner->id);
            $this->postJson('https://localhost/api/v1/auth/logout')
                ->assertOk()->assertJsonPath('data.revoked', true);
            $this->assertDatabaseCount('personal_access_tokens', 0);

            Auth::forgetGuards();
            $this->withToken($owner->createToken('admin', ['admin'], now()->addHour())->plainTextToken);
            $this->getJson('https://localhost/api/v1/admin/identity-check')
                ->assertOk()->assertJsonPath('data.authorized', true);
            $this->getJson('https://localhost/api/v1/admin/ai/providers')->assertOk();

            // No bearer token is required on public, read-only endpoints.
            $this->getJson('http://localhost/api/v1/public/projects')->assertOk();
        } finally {
            $this->app->instance('env', $previous);
        }
    }

    public function test_nonproduction_http_login_remains_available_to_local_developers(): void
    {
        $this->owner();
        $this->postJson('http://localhost/api/v1/auth/login', [
            'email' => 'https-api-owner@example.test',
            'password' => 'Long-Synthetic-Password-123!',
            'device_name' => 'local-test',
        ])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
    }
}

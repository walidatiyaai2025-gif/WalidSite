<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T024 W09-F01: explicit route throttles are required in addition to
 * authentication and any unknown deployment-level reverse-proxy policy.
 */
final class AdminRateLimitSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_privileged_admin_routes_declare_bounded_throttle_middleware(): void
    {
        foreach ([
            ['GET', '/api/v1/admin/identity-check', 'throttle:60,1'],
            ['GET', '/api/v1/admin/ai/providers', 'throttle:60,1'],
            ['GET', '/api/v1/admin/ai/providers/00000000-0000-4000-8000-000000000001', 'throttle:60,1'],
            ['DELETE', '/api/v1/admin/ai/providers/00000000-0000-4000-8000-000000000001', 'throttle:6,1'],
        ] as [$method, $url, $throttle]) {
            $route = Route::getRoutes()->match(Request::create($url, $method));
            $middleware = $route->gatherMiddleware();
            $this->assertContains($throttle, $middleware, $method.' '.$url.' must declare a rate limit');
            $this->assertContains('auth:sanctum', $middleware, $method.' '.$url.' must remain authenticated');
        }
    }

    public function test_real_admin_bearer_identity_is_rate_limited_after_sixty_requests(): void
    {
        $owner = User::create([
            'email' => 'bounded-owner-'.Str::random(8).'@example.test',
            'password_hash' => Hash::make('VeryLongTestPassword.123!'),
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $bearer = $owner->createToken('rate-limit-regression', ['admin'], now()->addHour());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.246']);
        $this->withToken($bearer->plainTextToken);
        $endpoint = '/api/v1/admin/identity-check';

        for ($request = 0; $request < 60; $request++) {
            $this->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('data.authorized', true);
        }

        $this->getJson($endpoint)->assertStatus(429);
    }

    public function test_guest_remains_denied_before_admin_provider_mutation_or_inspection(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.247']);
        $this->getJson('/api/v1/admin/identity-check')->assertUnauthorized();
        $this->getJson('/api/v1/admin/ai/providers')->assertUnauthorized();
        $this->getJson('/api/v1/admin/ai/providers/00000000-0000-4000-8000-000000000001')
            ->assertUnauthorized();
        $this->deleteJson('/api/v1/admin/ai/providers/00000000-0000-4000-8000-000000000001')
            ->assertUnauthorized();
    }
}

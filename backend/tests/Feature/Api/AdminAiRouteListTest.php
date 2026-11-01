<?php

namespace Tests\Feature\Api;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiRouteListTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(15).'@example.test',
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function token(string $role = 'owner', string $ability = 'admin', string $status = 'active'): string
    {
        return $this->user($role, $status)
            ->createToken('routing-admin-test', [$ability], now()->addHour())
            ->plainTextToken;
    }

    private function provider(string $name, int $priority, bool $enabled = true): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => $priority % 2 ? 'ollama-compatible' : 'openai-compatible',
            'base_url' => 'https://internal-credentialed-model.example.test/v1',
            'secret_ciphertext' => 'private-provider-secret-credential',
            'enabled' => $enabled,
            'health_status' => $enabled ? 'healthy' : 'unverified',
            'routing_priority' => $priority,
            'concurrent_limit' => 2,
            'timeout_seconds' => 7,
            'daily_budget_minor' => 1500,
            'currency' => 'KWD',
        ]);
    }

    public function test_requires_real_active_admin_role_and_scoped_bearer_token(): void
    {
        $url = '/api/v1/admin/ai/routes';
        $this->getJson($url)->assertUnauthorized();

        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'inactive'],
            ['admin', 'mobile', 'active'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status))
                ->getJson($url)->assertForbidden();
        }

        Auth::forgetGuards();
        $this->withToken($this->token('admin'))
            ->getJson($url)->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_web_admin_session_alone_does_not_authorize_routing_api(): void
    {
        $this->actingAs($this->user(), 'web')
            ->getJson('/api/v1/admin/ai/routes')->assertForbidden();
    }

    public function test_real_configuration_is_paginated_in_priority_order_without_secrets(): void
    {
        Http::fake();
        $archived = $this->provider('Archived model', 0);
        $archived->delete();

        for ($index = 1; $index <= 21; $index++) {
            $this->provider(sprintf('Model %02d', $index), $index, $index % 3 !== 0);
        }

        $this->withToken($this->token('owner'));
        $first = $this->getJson('/api/v1/admin/ai/routes?lang=ar')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.display_name', 'Model 01')
            ->assertJsonPath('data.0.provider_type', 'ollama-compatible')
            ->assertJsonPath('data.0.routing_priority', 1)
            ->assertJsonPath('data.0.configured_concurrent_limit', 2)
            ->assertJsonPath('data.0.configured_timeout_seconds', 7)
            ->assertJsonPath('data.0.configured_daily_budget_minor', 1500)
            ->assertJsonPath('data.0.currency', 'KWD')
            ->assertJsonPath('data.2.enabled', false)
            ->assertJsonPath('data.2.last_recorded_health_status', 'unverified')
            ->assertJsonPath('meta.lang', 'ar')
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.configuration_only', true)
            ->assertJsonPath('meta.no_live_eligibility_or_remaining_budget_claim', true);

        $page = $this->getJson('/api/v1/admin/ai/routes?page=2&lang=en')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Model 21')
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.lang', 'en');

        foreach ([$first, $page] as $response) {
            foreach ([
                'private-provider-secret-credential',
                'internal-credentialed-model.example.test',
                'secret_ciphertext',
                'base_url',
                'in_flight',
                'daily_budget_remaining',
                'usage_json',
            ] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $response->getContent());
            }
            $this->assertStringContainsString('no-store',
                (string) $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
    }

    public function test_invalid_array_or_out_of_range_query_values_are_rejected(): void
    {
        $this->withToken($this->token());
        foreach ([
            'lang=fr', 'lang[]=ar', 'page=0', 'page=10001',
            'page=abc', 'page[]=2',
        ] as $invalid) {
            $this->getJson('/api/v1/admin/ai/routes?'.$invalid)->assertUnprocessable();
        }
    }

    public function test_production_plaintext_is_rejected_but_https_remains_usable(): void
    {
        $before = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->withToken($this->token());
            $this->getJson('http://localhost/api/v1/admin/ai/routes')->assertForbidden();
            Auth::forgetGuards();
            $this->getJson('https://localhost/api/v1/admin/ai/routes')
                ->assertOk()->assertJsonCount(0, 'data');
        } finally {
            $this->app->instance('env', $before);
        }
    }
}

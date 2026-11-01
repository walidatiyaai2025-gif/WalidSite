<?php

namespace Tests\Feature\Api;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiRouteUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function token(string $role = 'owner', string $ability = 'admin', string $status = 'active'): string
    {
        return $this->account($role, $status)
            ->createToken('routing-write-test', [$ability], now()->addHour())
            ->plainTextToken;
    }

    private function provider(string $name = 'Editable routing provider'): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://internal-paid-provider.example.test/v1',
            'secret_ciphertext' => 'confidential-never-exported-secret',
            'enabled' => true,
            'health_status' => 'healthy',
            'last_checked_at' => now()->subHour(),
            'routing_priority' => 100,
            'concurrent_limit' => 2,
            'timeout_seconds' => 8,
            'daily_budget_minor' => 500,
            'currency' => 'USD',
        ]);
    }

    public function test_real_admin_write_updates_existing_provider_and_audit_without_network_or_secrets(): void
    {
        Http::fake();
        $provider = $this->provider();
        $this->withToken($this->token('owner'));

        $response = $this->putJson('/api/v1/admin/ai/routes/'.$provider->id, [
            'enabled' => false,
            'routing_priority' => 7,
            'concurrent_limit' => 4,
            'timeout_seconds' => 15,
            'daily_budget_minor' => 0,
            'currency' => 'KWD',
        ])->assertOk()
            ->assertJsonPath('data.provider_id', $provider->id)
            ->assertJsonPath('data.routing_priority', 7)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.configured_concurrent_limit', 4)
            ->assertJsonPath('data.configured_timeout_seconds', 15)
            ->assertJsonPath('data.configured_daily_budget_minor', 0)
            ->assertJsonPath('data.currency', 'KWD')
            ->assertJsonPath('data.last_recorded_health_status', 'unverified')
            ->assertJsonPath('meta.configuration_only', true)
            ->assertJsonPath('meta.no_live_eligibility_or_remaining_budget_claim', true);

        $persisted = $provider->fresh();
        $this->assertSame(7, $persisted->routing_priority);
        $this->assertSame(4, $persisted->concurrent_limit);
        $this->assertSame(0, $persisted->daily_budget_minor);
        $this->assertSame('KWD', $persisted->currency);
        $this->assertFalse($persisted->enabled);
        $this->assertSame('unverified', $persisted->health_status);
        $this->assertNull($persisted->last_checked_at);
        $this->assertSame('confidential-never-exported-secret', $persisted->secret_ciphertext);
        $audit = DB::table('ai_provider_audit_events')
            ->where('provider_id', $provider->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('updated', $audit->action);
        $changed = json_decode($audit->changed_fields_json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('daily_budget_minor', $changed);
        $this->assertContains('routing_priority', $changed);
        $this->assertNotContains('secret_ciphertext', $changed);

        $this->getJson('/api/v1/admin/ai/routes')->assertOk()
            ->assertJsonPath('data.0.configured_daily_budget_minor', 0)
            ->assertJsonPath('data.0.routing_priority', 7);
        foreach ([
            'confidential-never-exported-secret',
            'internal-paid-provider.example.test',
            'secret_ciphertext',
            'base_url',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $response->getContent());
        }
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_rejects_guest_mobile_reader_inactive_and_session_only_requests(): void
    {
        $provider = $this->provider();
        $url = '/api/v1/admin/ai/routes/'.$provider->id;
        $this->putJson($url, ['routing_priority' => 3])->assertUnauthorized();
        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'inactive'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status))
                ->putJson($url, ['routing_priority' => 3])->assertForbidden();
        }
        Auth::forgetGuards();
        $this->actingAs($this->account(), 'web')
            ->putJson($url, ['routing_priority' => 3])->assertForbidden();
        $this->assertSame(100, $provider->fresh()->routing_priority);
    }

    public function test_unknown_secret_and_out_of_range_updates_fail_closed(): void
    {
        Http::fake();
        $provider = $this->provider();
        $this->withToken($this->token());
        $url = '/api/v1/admin/ai/routes/'.$provider->id;
        foreach ([
            [],
            ['secret' => 'credential-injection'],
            ['base_url' => 'https://attacker.example.test/v1'],
            ['display_name' => 'Tampered'],
            ['provider_type' => 'ollama-compatible'],
            ['health_status' => 'healthy'],
            ['routing_priority' => 0],
            ['routing_priority' => 1000],
            ['concurrent_limit' => 0],
            ['timeout_seconds' => 31],
            ['daily_budget_minor' => -1],
            ['currency' => 'BAD'],
        ] as $invalid) {
            $this->putJson($url, $invalid)->assertUnprocessable();
        }

        $stored = $provider->fresh();
        $this->assertSame(100, $stored->routing_priority);
        $this->assertSame(500, $stored->daily_budget_minor);
        $this->assertSame('healthy', $stored->health_status);
        $this->assertSame('confidential-never-exported-secret', $stored->secret_ciphertext);
        $this->assertDatabaseCount('ai_provider_audit_events', 0);

        $provider->delete();
        $this->putJson($url, ['routing_priority' => 4])->assertNotFound();
        $this->putJson('/api/v1/admin/ai/routes/not-a-uuid', [
            'routing_priority' => 4,
        ])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_production_plaintext_update_is_blocked_before_token_access(): void
    {
        $provider = $this->provider();
        $old = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->withToken($this->token());
            $url = '/api/v1/admin/ai/routes/'.$provider->id;
            $this->putJson('http://localhost'.$url, [
                'routing_priority' => 8,
            ])->assertForbidden();
            $this->assertSame(100, $provider->fresh()->routing_priority);
            Auth::forgetGuards();
            $this->putJson('https://localhost'.$url, [
                'routing_priority' => 8,
            ])->assertOk()->assertJsonPath('data.routing_priority', 8);
        } finally {
            $this->app->instance('env', $old);
        }
    }
}

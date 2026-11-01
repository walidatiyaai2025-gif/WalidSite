<?php

namespace Tests\Feature\Api;

use App\AI\MobileDiagnosisAdmission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiTaskRouteJourneyTest extends TestCase
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

    private function token(string $role = 'owner', string $ability = 'admin',
        string $status = 'active'): string
    {
        return $this->account($role, $status)
            ->createToken('model-routing-api-test', [$ability], now()->addHour())
            ->plainTextToken;
    }

    private function provider(string $name, string $type = 'ollama-compatible',
        string $currency = 'USD'): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://paid-provider.example.test/v1',
            'secret_ciphertext' => 'PRIVATE_INTERNAL_PROVIDER_SECRET',
            'enabled' => true,
            'health_status' => 'healthy',
            'routing_priority' => 10,
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 1000,
            'currency' => $currency,
        ]);
    }

    private function model(AiProvider $provider, string $name): string
    {
        $response = $this->postJson('/api/v1/admin/ai/models', [
            'provider_id' => $provider->id,
            'provider_model_id' => $name,
            'enabled' => true,
            'supports_text' => true,
        ])->assertCreated()
            ->assertJsonPath('data.provider_model_id', $name)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('meta.configuration_only', true);
        return $response->json('data.id');
    }

    private function routePayload(string $primary, array $fallbacks = [],
        string $currency = 'USD', int $reserve = 0): array
    {
        return [
            'primary_model_id' => $primary,
            'fallback_model_ids' => $fallbacks,
            'currency' => $currency,
            'reservation_minor' => $reserve,
            'per_minute' => 3,
            'per_day' => 30,
        ];
    }

    public function test_admin_can_register_route_switch_fallback_and_disable_real_models(): void
    {
        Http::fake();
        $provider = $this->provider('Local inference engine');
        $this->withToken($this->token());
        $this->getJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose')
            ->assertOk()->assertJsonPath('data.route', null)
            ->assertJsonPath('data.eligible_model', null);
        $this->getJson('/api/v1/admin/ai/models')->assertOk()
            ->assertJsonCount(0, 'data');

        $primary = $this->model($provider, 'local-main');
        $fallback = $this->model($provider, 'local-fallback');
        $listed = $this->getJson('/api/v1/admin/ai/models?provider_id='.$provider->id)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.provider_model_id', 'local-fallback');
        $this->assertStringNotContainsString('PRIVATE_INTERNAL_PROVIDER_SECRET', $listed->getContent());
        $this->assertStringNotContainsString('base_url', $listed->getContent());

        $save = $this->putJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose',
            $this->routePayload($primary, [$fallback]))
            ->assertOk()->assertJsonPath('data.route.primary_model_id', $primary)
            ->assertJsonPath('data.route.fallback_model_ids.0', $fallback)
            ->assertJsonPath('data.route.rate_limits.per_minute', 3)
            ->assertJsonPath('data.route.rate_limits.per_day', 30);
        $this->assertSame('no-store, private',
            $save->headers->get('Cache-Control'));

        $this->assertSame($primary, app(MobileDiagnosisAdmission::class)->select()['model']->id);
        $this->getJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose')
            ->assertOk()->assertJsonPath('data.eligible_model.model_id', $primary)
            ->assertJsonPath('data.eligible_model.reserved_budget_minor', 0);

        $this->patchJson('/api/v1/admin/ai/models/'.$primary, ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose')
            ->assertOk()->assertJsonPath('data.eligible_model.model_id', $fallback);

        $this->patchJson('/api/v1/admin/ai/models/'.$fallback, ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose')
            ->assertOk()->assertJsonPath('data.eligible_model', null);

        $this->patchJson('/api/v1/admin/ai/models/'.$primary, ['enabled' => true])
            ->assertOk();
        $this->assertSame($primary, app(MobileDiagnosisAdmission::class)->select()['model']->id);
        $this->assertDatabaseCount('ai_routes', 1);
        Http::assertNothingSent();
    }

    public function test_paid_provider_and_task_currency_are_compatible_and_do_not_change_on_failed_input(): void
    {
        Http::fake();
        $usd = $this->provider('Primary USD provider', 'openai-compatible');
        $kwd = $this->provider('Fallback KWD provider', 'openai-compatible', 'KWD');
        $this->withToken($this->token());
        $first = $this->model($usd, 'paid-usd');
        $second = $this->model($kwd, 'paid-kwd');
        $this->getJson('/api/v1/admin/ai/models')
            ->assertOk()->assertJsonPath('data.0.cost_currency', 'KWD');
        $this->putJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose',
            $this->routePayload($first, [], 'USD', 25))
            ->assertOk()->assertJsonPath('data.route.budget.reservation_minor', 25);
        $saved = AiRoute::query()->firstOrFail();

        foreach ([
            $this->routePayload($first, [$second], 'USD', 25),
            $this->routePayload($first, [], 'KWD', 25),
            $this->routePayload($first, [], 'USD', 0),
        ] as $invalid) {
            $this->putJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose', $invalid)
                ->assertUnprocessable()->assertJsonValidationErrors('currency');
            $this->assertSame($first, $saved->fresh()->primary_model_id);
            $this->assertSame(25, $saved->fresh()->budget_json['reservation_minor']);
        }

        foreach ([
            $this->routePayload($first, [$first], 'USD', 25),
            $this->routePayload($first, [$first, $first], 'USD', 25),
            array_merge($this->routePayload($first, [], 'USD', 25), ['secret' => 'not-allowed']),
            array_merge($this->routePayload($first, [], 'USD', 25), ['per_day' => 1]),
            array_merge($this->routePayload($first, [], 'USD', 25), ['primary_model_id' => 'bad-id']),
        ] as $invalid) {
            $this->putJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose', $invalid)
                ->assertUnprocessable();
            $this->assertSame($first, $saved->fresh()->primary_model_id);
        }
        $this->assertDatabaseCount('ai_routes', 1);
        Http::assertNothingSent();
    }

    public function test_token_role_lifecycle_and_secure_transport_rejects_unauthorized_operations(): void
    {
        Http::fake();
        $provider = $this->provider('Authorization provider');
        $urls = [
            ['GET', '/api/v1/admin/ai/models'],
            ['POST', '/api/v1/admin/ai/models'],
            ['GET', '/api/v1/admin/ai/task-routes/mobile-ai-diagnose'],
            ['PUT', '/api/v1/admin/ai/task-routes/mobile-ai-diagnose'],
            ['PATCH', '/api/v1/admin/ai/models/00000000-0000-4000-8000-000000000000'],
        ];
        foreach ($urls as [$verb, $url]) {
            $this->json($verb, $url)->assertUnauthorized();
        }
        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'inactive'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status));
            foreach ($urls as [$verb, $url]) {
                $this->json($verb, $url)->assertForbidden();
            }
        }
        Auth::forgetGuards();
        $this->actingAs($this->account(), 'web')
            ->getJson('/api/v1/admin/ai/models')->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->token());
        $env = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->getJson('http://localhost/api/v1/admin/ai/models')->assertForbidden();
            $this->getJson('https://localhost/api/v1/admin/ai/models')->assertOk();
        } finally {
            $this->app->instance('env', $env);
        }
        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseCount('ai_routes', 0);
        Http::assertNothingSent();
    }

    public function test_registration_archival_duplicates_field_injection_and_nontext_models_are_rejected(): void
    {
        Http::fake();
        $active = $this->provider('Model owner');
        $archived = $this->provider('Archived owner');
        $this->withToken($this->token());

        $first = $this->model($active, 'real-text-model');
        $data = [
            'provider_id' => $active->id,
            'provider_model_id' => 'real-text-model',
            'enabled' => true, 'supports_text' => true,
        ];
        $this->postJson('/api/v1/admin/ai/models', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('provider_model_id');
        $this->postJson('/api/v1/admin/ai/models', array_merge($data, [
            'provider_model_id' => '../bad',
        ]))->assertUnprocessable()->assertJsonValidationErrors('provider_model_id');
        $this->postJson('/api/v1/admin/ai/models', array_merge($data, [
            'provider_model_id' => 'new-name', 'secret' => 'malicious',
        ]))->assertUnprocessable()->assertJsonValidationErrors('configuration');
        $this->patchJson('/api/v1/admin/ai/models/'.$first, [
            'enabled' => false, 'cost_policy_json' => ['currency' => 'EUR'],
        ])->assertUnprocessable()->assertJsonValidationErrors('configuration');

        $nontext = $this->postJson('/api/v1/admin/ai/models', [
            'provider_id' => $active->id, 'provider_model_id' => 'vision-only',
            'enabled' => true, 'supports_text' => false,
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/admin/ai/task-routes/mobile-ai-diagnose',
            $this->routePayload($nontext))
            ->assertUnprocessable()->assertJsonValidationErrors('primary_model_id');

        $archived->delete();
        $this->postJson('/api/v1/admin/ai/models', array_merge($data, [
            'provider_id' => $archived->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('provider_id');
        $this->getJson('/api/v1/admin/ai/models?provider_id='.$archived->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/ai/models?page[]=2')
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/api/v1/admin/ai/models?provider_id[]=bad')
            ->assertUnprocessable()->assertJsonValidationErrors('provider_id');
        $this->patchJson('/api/v1/admin/ai/models/not-uuid', ['enabled' => true])
            ->assertNotFound();
        Http::assertNothingSent();
    }
}

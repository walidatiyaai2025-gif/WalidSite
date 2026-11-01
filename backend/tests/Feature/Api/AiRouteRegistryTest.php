<?php

namespace Tests\Feature\Api;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiRouteRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $name): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test/v1',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 2,
            'timeout_seconds' => 8,
            'daily_budget_minor' => 5000,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    private function model(AiProvider $provider, string $modelId): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $modelId,
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => [
                'currency' => 'USD',
                'reservation_minor' => 25,
            ],
        ]);
    }

    public function test_declared_route_policy_schema_persists_typed_arrays_without_network(): void
    {
        Http::fake();
        $primary = $this->model($this->provider('Route Registry Provider'), 'technical-model');

        $route = AiRoute::create([
            'task_type' => 'mobile-ai-diagnose',
            'primary_model_id' => $primary->id,
            'fallback_model_ids_json' => [],
            'budget_json' => [
                'reservation_minor' => 25,
                'currency' => 'USD',
            ],
            'rate_limits_json' => [
                'per_minute' => 4,
                'per_day' => 40,
            ],
        ])->fresh();

        $this->assertTrue(Schema::hasColumns('ai_routes', [
            'id',
            'task_type',
            'primary_model_id',
            'fallback_model_ids_json',
            'budget_json',
            'rate_limits_json',
        ]));
        $this->assertNotNull($route);
        $this->assertSame('mobile-ai-diagnose', $route->task_type);
        $this->assertSame([], $route->fallback_model_ids_json);
        $this->assertSame(25, $route->budget_json['reservation_minor']);
        $this->assertSame(4, $route->rate_limits_json['per_minute']);
        $this->assertSame($primary->id, $route->primaryModel->id);
        Http::assertNothingSent();
    }

    public function test_task_type_is_unique_and_missing_primary_model_fails_closed(): void
    {
        $primary = $this->model($this->provider('Unique Route Provider'), 'primary');

        AiRoute::create([
            'task_type' => 'mobile-ai-diagnose',
            'primary_model_id' => $primary->id,
            'fallback_model_ids_json' => [],
            'budget_json' => [],
            'rate_limits_json' => [],
        ]);

        try {
            AiRoute::create([
                'task_type' => 'mobile-ai-diagnose',
                'primary_model_id' => $primary->id,
                'fallback_model_ids_json' => [],
                'budget_json' => [],
                'rate_limits_json' => [],
            ]);
            $this->fail('Duplicate task routes must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('ai_routes', 1);
        }

        $this->expectException(QueryException::class);
        AiRoute::create([
            'task_type' => 'missing-model-route',
            'primary_model_id' => '00000000-0000-4000-8000-000000000000',
            'fallback_model_ids_json' => [],
            'budget_json' => [],
            'rate_limits_json' => [],
        ]);
    }

    public function test_primary_model_cannot_be_removed_until_route_is_removed(): void
    {
        $primary = $this->model($this->provider('Protected Route Provider'), 'protected-model');
        $route = AiRoute::create([
            'task_type' => 'protected-task',
            'primary_model_id' => $primary->id,
            'fallback_model_ids_json' => [],
            'budget_json' => [],
            'rate_limits_json' => [],
        ]);

        try {
            $primary->delete();
            $this->fail('Referenced primary model deletion must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas('ai_models', ['id' => $primary->id]);
        }

        $route->delete();
        $primary->delete();

        $this->assertDatabaseMissing('ai_routes', ['id' => $route->id]);
        $this->assertDatabaseMissing('ai_models', ['id' => $primary->id]);
    }
}

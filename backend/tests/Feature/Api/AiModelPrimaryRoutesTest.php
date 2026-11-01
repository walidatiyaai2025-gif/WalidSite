<?php

namespace Tests\Feature\Api;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiModelPrimaryRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_lists_only_its_primary_route_assignments_without_network(): void
    {
        Http::fake();
        $provider = AiProvider::create([
            'display_name' => 'Local test provider',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'https://models.example.test/v1',
            'enabled' => true,
            'capabilities_json' => [],
            'cost_policy_json' => [],
        ]);
        $first = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'local-first',
            'enabled' => true,
            'capabilities_json' => [],
            'cost_policy_json' => [],
        ]);
        $second = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'local-second',
            'enabled' => true,
            'capabilities_json' => [],
            'cost_policy_json' => [],
        ]);
        $route = AiRoute::create([
            'task_type' => 'verified-learning-summary',
            'primary_model_id' => $first->id,
            'fallback_model_ids_json' => [],
            'budget_json' => [],
            'rate_limits_json' => [],
        ]);

        $this->assertSame([$route->id], $first->primaryRoutes()->pluck('id')->all());
        $this->assertCount(0, $second->primaryRoutes);
        $this->assertSame($first->id, $route->primaryModel->id);
        Http::assertNothingSent();
    }
}

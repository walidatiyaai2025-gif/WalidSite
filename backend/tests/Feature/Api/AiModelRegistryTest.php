<?php

namespace Tests\Feature\Api;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AiModelRegistryTest extends TestCase
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
            'daily_budget_minor' => 1000,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    public function test_declared_ai_model_schema_persists_typed_registry_without_network(): void
    {
        Http::fake();
        $provider = $this->provider('Registry Provider');

        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'gpt-compatible-technical',
            'capabilities_json' => ['text', 'vision'],
            'enabled' => true,
            'cost_policy_json' => [
                'currency' => 'USD',
                'input_per_1k_minor' => 2,
                'output_per_1k_minor' => 4,
            ],
        ])->fresh();

        $this->assertTrue(Schema::hasColumns('ai_models', [
            'id',
            'provider_id',
            'provider_model_id',
            'capabilities_json',
            'enabled',
            'cost_policy_json',
        ]));
        $this->assertNotNull($model);
        $this->assertSame('gpt-compatible-technical', $model->provider_model_id);
        $this->assertSame(['text', 'vision'], $model->capabilities_json);
        $this->assertTrue($model->enabled);
        $this->assertSame('USD', $model->cost_policy_json['currency']);
        $this->assertSame($provider->id, $model->provider->id);
        $this->assertSame($model->id, $provider->fresh()->aiModels()->firstOrFail()->id);
        Http::assertNothingSent();
    }

    public function test_model_identifier_is_unique_only_within_one_provider(): void
    {
        $first = $this->provider('First Registry Provider');
        $second = $this->provider('Second Registry Provider');

        AiModel::create([
            'provider_id' => $first->id,
            'provider_model_id' => 'shared-upstream-id',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
        AiModel::create([
            'provider_id' => $second->id,
            'provider_model_id' => 'shared-upstream-id',
            'capabilities_json' => ['text'],
            'enabled' => false,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);

        $this->assertDatabaseCount('ai_models', 2);

        $this->expectException(QueryException::class);
        AiModel::create([
            'provider_id' => $first->id,
            'provider_model_id' => 'shared-upstream-id',
            'capabilities_json' => ['embeddings'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
    }

    public function test_hard_provider_removal_cascades_registered_models(): void
    {
        $provider = $this->provider('Disposable Registry Provider');
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'temporary-model',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);

        $provider->forceDelete();

        $this->assertDatabaseMissing('ai_models', ['id' => $model->id]);
    }
}

<?php

namespace Tests\Feature\AI;

use App\AI\MobileDiagnosisAdmission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MobileDiagnosisCurrencyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $name, string $currency = 'USD',
        string $type = 'openai-compatible'): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://provider.example.test/v1',
            'secret_ciphertext' => $type === 'ollama-compatible' ? null : 'private-test-credential',
            'enabled' => true,
            'health_status' => 'healthy',
            'routing_priority' => 10,
            'daily_budget_minor' => $type === 'ollama-compatible' ? 0 : 100,
            'currency' => $currency,
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
        ]);
    }

    private function model(AiProvider $provider, string $upstream,
        array $costPolicy): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $upstream,
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => $costPolicy,
        ]);
    }

    private function route(AiModel $primary, array $fallbacks = [],
        ?string $currency = null, int $reserve = 10): void
    {
        $budget = ['reservation_minor' => $reserve];
        if ($currency !== null) {
            $budget['currency'] = $currency;
        }
        AiRoute::create([
            'task_type' => MobileDiagnosisAdmission::TASK_TYPE,
            'primary_model_id' => $primary->id,
            'fallback_model_ids_json' => array_map(
                static fn (AiModel $model): string => $model->id,
                $fallbacks
            ),
            'budget_json' => $budget,
            'rate_limits_json' => ['per_minute' => 4, 'per_day' => 40],
        ]);
    }

    public function test_paid_model_with_foreign_pricing_currency_is_skipped_for_compatible_fallback(): void
    {
        Http::fake();
        $primary = $this->provider('Paid primary USD');
        $fallback = $this->provider('Paid fallback USD');
        $primaryModel = $this->model($primary, 'foreign-price', ['currency' => 'KWD']);
        $fallbackModel = $this->model($fallback, 'correct-price', ['currency' => 'USD']);
        $this->route($primaryModel, [$fallbackModel], 'USD');

        $selected = app(MobileDiagnosisAdmission::class)->select();
        $this->assertNotNull($selected);
        $this->assertSame($fallback->id, $selected['provider']->id);
        $this->assertSame($fallbackModel->id, $selected['model']->id);
        $this->assertSame(10, $selected['reserve_minor']);

        $primaryModel->forceFill(['cost_policy_json' => ['currency' => 'USD']])->save();
        $this->assertSame($primary->id, app(MobileDiagnosisAdmission::class)->select()['provider']->id);
        Http::assertNothingSent();
    }

    public function test_explicit_route_reservation_currency_must_match_paid_provider(): void
    {
        Http::fake();
        $usd = $this->provider('Primary model USD');
        $kwd = $this->provider('Fallback model KWD', 'KWD');
        $usdModel = $this->model($usd, 'usd-priced', ['currency' => 'USD']);
        $kwdModel = $this->model($kwd, 'kwd-priced', ['currency' => 'KWD']);
        $this->route($usdModel, [$kwdModel], 'KWD');

        $selected = app(MobileDiagnosisAdmission::class)->select();
        $this->assertNotNull($selected);
        $this->assertSame($kwd->id, $selected['provider']->id);
        $this->assertSame(10, $selected['reserve_minor']);

        AiRoute::query()->update(['budget_json' => ['reservation_minor' => 10, 'currency' => 'EUR']]);
        $this->assertNull(app(MobileDiagnosisAdmission::class)->select());
        $this->assertFalse(app(MobileDiagnosisAdmission::class)->available());
        Http::assertNothingSent();
    }

    public function test_missing_or_malformed_paid_model_currency_fails_closed(): void
    {
        Http::fake();
        $provider = $this->provider('Policyless paid USD');
        $model = $this->model($provider, 'price-missing', []);
        $this->route($model, currency: 'USD');

        foreach ([[], ['currency' => null], ['currency' => ['USD']],
                  ['currency' => 'EUR'], ['currency' => 'usd']] as $policy) {
            $model->forceFill(['cost_policy_json' => $policy])->save();
            $this->assertNull(app(MobileDiagnosisAdmission::class)->select());
        }

        $model->forceFill(['cost_policy_json' => ['currency' => 'USD']])->save();
        $this->assertSame($provider->id, app(MobileDiagnosisAdmission::class)->select()['provider']->id);
        Http::assertNothingSent();
    }

    public function test_unpaid_local_inference_does_not_require_paid_model_currency_policy(): void
    {
        Http::fake();
        $provider = $this->provider('Local free inference', 'KWD', 'ollama-compatible');
        $model = $this->model($provider, 'local-model', []);
        $this->route($model, currency: 'USD', reserve: 0);

        $selected = app(MobileDiagnosisAdmission::class)->select();
        $this->assertNotNull($selected);
        $this->assertSame($provider->id, $selected['provider']->id);
        $this->assertSame(0, $selected['reserve_minor']);
        Http::assertNothingSent();
    }
}

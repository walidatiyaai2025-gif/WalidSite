<?php

namespace Tests\Feature\AI;

use App\AI\MobileDiagnosisAdmission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileDiagnosisQueuedBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $name, int $dailyBudget = 100,
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
            'daily_budget_minor' => $dailyBudget,
            'currency' => 'USD',
            'concurrent_limit' => 5,
            'timeout_seconds' => 5,
        ]);
    }

    private function model(AiProvider $provider, string $name): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $name,
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
    }

    private function route(AiModel $primary, array $fallbacks = [], int $reserve = 30): void
    {
        AiRoute::create([
            'task_type' => MobileDiagnosisAdmission::TASK_TYPE,
            'primary_model_id' => $primary->id,
            'fallback_model_ids_json' => array_map(
                static fn (AiModel $model): string => $model->id, $fallbacks
            ),
            'budget_json' => ['currency' => 'USD', 'reservation_minor' => $reserve],
            'rate_limits_json' => ['per_minute' => 4, 'per_day' => 40],
        ]);
    }

    private function job(AiProvider $provider, string $status, int $reserve): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'mobile-diagnosis',
            'idempotency_key' => 'queued-budget:'.(string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'reserved_budget_minor' => $reserve,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $id;
    }

    private function receipt(AiProvider $provider, string $jobId, string $currency,
        int $cost, bool $yesterday = false): void
    {
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => 'queued-budget-receipt:'.(string) Str::uuid(),
            'job_id' => $jobId,
            'provider_id' => $provider->id,
            'cost_minor' => $cost,
            'cost_currency' => $currency,
            'created_at' => $yesterday
                ? now('UTC')->subDay()->toDateTimeString()
                : now('UTC')->toDateTimeString(),
        ]);
    }

    public function test_queued_and_retry_reservations_trigger_eligible_paid_fallback(): void
    {
        Http::fake();
        $primary = $this->provider('Primary');
        $fallback = $this->provider('Fallback');
        $this->route($this->model($primary, 'primary'),
            [$this->model($fallback, 'fallback')]);

        $selector = app(MobileDiagnosisAdmission::class);
        $this->assertSame($primary->id, $selector->select()['provider']->id);

        $queued = $this->job($primary, 'queued', 60);
        $this->assertSame($primary->id, $selector->select()['provider']->id,
            'A queued 60-unit reservation leaves 40 for a new 30-unit request.');

        $retry = $this->job($primary, 'retry', 20);
        $this->assertSame($fallback->id, $selector->select()['provider']->id,
            'Queued 60 + retry 20 + requested 30 must exceed the 100-unit cap.');

        DB::table('ai_jobs')->where('id', $retry)->update(['status' => 'failed']);
        $this->assertSame($primary->id, $selector->select()['provider']->id,
            'Terminal failure releases its unspent reservation.');

        DB::table('ai_jobs')->where('id', $queued)->update(['status' => 'cancelled']);
        $this->assertSame($primary->id, $selector->select()['provider']->id);
        Http::assertNothingSent();
    }

    public function test_partial_receipts_reduce_pending_liability_without_double_counting_today(): void
    {
        Http::fake();
        $provider = $this->provider('Partially settled');
        $this->route($this->model($provider, 'partial'), reserve: 30);
        $queued = $this->job($provider, 'queued', 90);

        $selector = app(MobileDiagnosisAdmission::class);
        $this->assertNull($selector->select(), '90 outstanding + 30 is over budget.');

        $this->receipt($provider, $queued, 'USD', 70, true);
        $this->assertSame($provider->id, $selector->select()['provider']->id,
            'Previous UTC-day usage reduces only this pending job liability.');

        // Today's receipt consumes both 70 spent and 20 outstanding.
        DB::table('ai_usage')->where('job_id', $queued)->delete();
        $this->receipt($provider, $queued, 'USD', 70);
        $this->assertNull($selector->select(),
            '70 spent today + 20 outstanding + 30 requested cannot be admitted.');

        $route = AiRoute::query()->firstOrFail();
        $route->forceFill([
            'budget_json' => ['currency' => 'USD', 'reservation_minor' => 10],
        ])->save();
        $this->assertSame($provider->id, $selector->select()['provider']->id,
            '70 + 20 + 10 must fit exactly, without double counting 90.');
        Http::assertNothingSent();
    }

    public function test_foreign_receipt_on_a_pending_job_from_yesterday_fails_closed(): void
    {
        Http::fake();
        $provider = $this->provider('Legacy mixed currency');
        $this->route($this->model($provider, 'legacy'), reserve: 10);
        $retry = $this->job($provider, 'retry', 50);
        $this->receipt($provider, $retry, 'KWD', 30, true);

        $selector = app(MobileDiagnosisAdmission::class);
        $this->assertNull($selector->select(),
            'A foreign-currency historical retry receipt cannot release USD capacity.');
        $this->assertFalse($selector->available());

        DB::table('ai_jobs')->where('id', $retry)->update(['status' => 'failed']);
        $this->assertSame($provider->id, $selector->select()['provider']->id,
            'Terminal historical receipts are outside current day and liability.');
        Http::assertNothingSent();
    }

    public function test_many_pending_jobs_use_one_aggregate_instead_of_per_job_queries(): void
    {
        Http::fake();
        $provider = $this->provider('Large bounded queue', 10000);
        $this->route($this->model($provider, 'aggregate'), reserve: 20);
        for ($i = 0; $i < 55; $i++) {
            $this->job($provider, $i % 2 ? 'queued' : 'retry', 20);
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'select')) {
                $queries[] = $query->sql;
            }
        });

        $this->assertSame($provider->id,
            app(MobileDiagnosisAdmission::class)->select()['provider']->id);
        $this->assertLessThan(16, count($queries),
            'Selection must not issue a receipt query for every pending job.');
        Http::assertNothingSent();
    }

    public function test_local_zero_cost_inference_remains_eligible_with_pending_jobs(): void
    {
        Http::fake();
        $provider = $this->provider('Local model', 0, 'ollama-compatible');
        $this->route($this->model($provider, 'local'), reserve: 0);
        $this->job($provider, 'queued', 0);
        $this->job($provider, 'retry', 0);

        $selection = app(MobileDiagnosisAdmission::class)->select();
        $this->assertNotNull($selection);
        $this->assertSame($provider->id, $selection['provider']->id);
        $this->assertSame(0, $selection['reserve_minor']);
        Http::assertNothingSent();
    }
}

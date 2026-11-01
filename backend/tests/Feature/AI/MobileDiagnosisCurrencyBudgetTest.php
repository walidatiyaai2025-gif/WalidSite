<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\AI\MobileDiagnosisAdmission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileDiagnosisCurrencyBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $name): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://provider.example.test/v1',
            'secret_ciphertext' => 'private-fixture-credential',
            'enabled' => true,
            'health_status' => 'healthy',
            'routing_priority' => 10,
            'daily_budget_minor' => 100,
            'currency' => 'USD',
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
        ]);
    }

    private function model(AiProvider $provider, string $id): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $id,
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
    }

    private function diagnosis(AiModel $primary, array $fallbacks = []): void
    {
        AiRoute::create([
            'task_type' => MobileDiagnosisAdmission::TASK_TYPE,
            'primary_model_id' => $primary->id,
            'fallback_model_ids_json' => array_map(
                static fn (AiModel $model): string => $model->id, $fallbacks
            ),
            'budget_json' => ['reservation_minor' => 10, 'currency' => 'USD'],
            'rate_limits_json' => ['per_minute' => 4, 'per_day' => 40],
        ]);
    }

    private function existingJob(AiProvider $provider, string $status = 'completed', int $reserved = 0): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'legacy-cost-fixture',
            'idempotency_key' => 'ledger:'.(string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'reserved_budget_minor' => $reserved,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        return $id;
    }

    private function receipt(string $jobId, AiProvider $provider, string $currency, int $cost, string $at): void
    {
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => 'receipt:'.(string) Str::uuid(),
            'job_id' => $jobId,
            'provider_id' => $provider->id,
            'cost_minor' => $cost,
            'cost_currency' => $currency,
            'created_at' => $at,
        ]);
    }

    public function test_diagnosis_skips_paid_provider_with_foreign_currency_receipt_and_selects_fallback(): void
    {
        Http::fake();
        $primary = $this->provider('Primary USD');
        $secondary = $this->provider('Fallback USD');
        $primaryModel = $this->model($primary, 'primary');
        $secondaryModel = $this->model($secondary, 'fallback');
        $this->diagnosis($primaryModel, [$secondaryModel]);

        $selector = app(MobileDiagnosisAdmission::class);
        $this->assertSame($primary->id, $selector->select()['provider']->id);
        $oldJob = $this->existingJob($primary);
        $this->receipt($oldJob, $primary, 'KWD', 1, now('UTC')->toDateTimeString());

        // Never convert or sum one KWD minor unit into a USD daily budget.
        $this->assertSame($secondary->id, $selector->select()['provider']->id);
        $this->assertTrue($selector->available());

        DB::table('ai_usage')->where('job_id', $oldJob)->delete();
        $this->receipt($oldJob, $primary, 'USD', 95, now('UTC')->toDateTimeString());
        $this->assertSame($secondary->id, $selector->select()['provider']->id);

        DB::table('ai_usage')->where('job_id', $oldJob)->delete();
        $this->receipt($oldJob, $primary, 'KWD', 99,
            now('UTC')->subDay()->toDateTimeString());
        // A legacy receipt outside the current UTC day does not affect the
        // new day's budget, unless it belongs to an outstanding running job.
        $this->assertSame($primary->id, $selector->select()['provider']->id);
        Http::assertNothingSent();
    }

    public function test_running_job_foreign_receipt_cannot_release_paid_reserved_capacity(): void
    {
        Http::fake();
        $provider = $this->provider('Running USD');
        $model = $this->model($provider, 'running');
        $this->diagnosis($model);
        $jobId = $this->existingJob($provider, 'running', 50);
        $this->receipt($jobId, $provider, 'EUR', 40,
            now('UTC')->subDay()->toDateTimeString());

        $this->assertNull(app(MobileDiagnosisAdmission::class)->select());

        DB::table('ai_usage')->where('job_id', $jobId)->delete();
        $this->receipt($jobId, $provider, 'USD', 40,
            now('UTC')->subDay()->toDateTimeString());
        $selection = app(MobileDiagnosisAdmission::class)->select();
        $this->assertNotNull($selection);
        $this->assertSame($provider->id, $selection['provider']->id);
        $this->assertSame(10, $selection['reserve_minor']);
        Http::assertNothingSent();
    }

    public function test_queue_claim_and_usage_receipts_fail_closed_on_mixed_currency_legacy_rows(): void
    {
        Http::fake();
        $provider = $this->provider('Queue USD');
        $queue = app(DurableAiQueue::class);
        $jobId = $queue->enqueue($provider, 'fixture', 'currency-claim:1', ['run'], 50);
        $oldJob = $this->existingJob($provider);
        $this->receipt($oldJob, $provider, 'KWD', 1, now('UTC')->toDateTimeString());

        $this->assertNull($queue->claimNext('currency-test-worker'));
        $this->assertSame('queued', DB::table('ai_jobs')->where('id', $jobId)->value('status'));

        DB::table('ai_usage')->where('job_id', $oldJob)->delete();
        $claimed = $queue->claimNext('currency-test-worker');
        $this->assertNotNull($claimed);
        $this->assertSame($jobId, $claimed->id);

        $this->receipt($oldJob, $provider, 'EUR', 3, now('UTC')->toDateTimeString());
        try {
            $queue->recordUsage($jobId, 'currency-test-worker', 'currency-new:1', 10, 'USD');
            $this->fail('A foreign-currency receipt must block accounting.');
        } catch (DomainException $exception) {
            $this->assertSame('Mixed-currency provider receipts cannot be budgeted.',
                $exception->getMessage());
        }
        $this->assertDatabaseMissing('ai_usage', ['usage_key' => 'currency-new:1']);

        DB::table('ai_usage')->where('job_id', $oldJob)->delete();
        $this->assertTrue($queue->recordUsage($jobId, 'currency-test-worker',
            'currency-new:1', 10, 'USD'));

        // A wrong-currency receipt on this job from a previous UTC day
        // cannot be deducted from its *lifetime* reservation.
        $this->receipt($jobId, $provider, 'KWD', 1,
            now('UTC')->subDay()->toDateTimeString());
        try {
            $queue->recordUsage($jobId, 'currency-test-worker', 'currency-new:2', 10, 'USD');
            $this->fail('A previous-day foreign job receipt must block accounting.');
        } catch (DomainException $exception) {
            $this->assertSame('Mixed-currency job receipts cannot be budgeted.',
                $exception->getMessage());
        }
        $this->assertDatabaseMissing('ai_usage', ['usage_key' => 'currency-new:2']);
        Http::assertNothingSent();
    }

    public function test_queue_mixed_currency_running_reservation_cannot_admit_another_paid_job(): void
    {
        $provider = $this->provider('Running queue USD');
        $queue = app(DurableAiQueue::class);
        $runningId = $this->existingJob($provider, 'running', 50);
        $this->receipt($runningId, $provider, 'KWD', 30,
            now('UTC')->subDay()->toDateTimeString());
        $pending = $queue->enqueue($provider, 'fixture', 'currency-claim:pending', ['run'], 20);

        $this->assertNull($queue->claimNext('currency-second-worker'));
        $this->assertSame('queued', DB::table('ai_jobs')->where('id', $pending)->value('status'));

        DB::table('ai_usage')->where('job_id', $runningId)->delete();
        $this->receipt($runningId, $provider, 'USD', 30,
            now('UTC')->subDay()->toDateTimeString());
        $this->assertSame($pending,
            $queue->claimNext('currency-second-worker')->id);
    }
}

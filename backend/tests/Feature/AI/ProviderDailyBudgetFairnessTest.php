<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderDailyBudgetFairnessTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $type = 'openai-compatible', int $capacity = 1): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Budget '.Str::random(16),
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://models.example.org/v1',
            'enabled' => true,
            'health_status' => 'healthy',
            'currency' => 'USD',
            'daily_budget_minor' => $type === 'ollama-compatible' ? 0 : 100,
            'concurrent_limit' => $capacity,
            'timeout_seconds' => 5,
        ]);
    }

    public function test_completed_paid_spend_excludes_old_backlog_before_limit_and_resets_next_utc_day(): void
    {
        Http::fake();
        $queue = app(DurableAiQueue::class);
        $paid = $this->provider();
        $consumed = $queue->enqueue($paid, 'draft', 'budget:consumed', ['write'], 90);
        $this->assertSame($consumed, $queue->claimNext('paid-worker')?->id);
        $queue->recordUsage($consumed, 'paid-worker', 'budget:spent', 90, 'USD');
        $queue->completeStep($consumed, 'paid-worker', 'write', 'drafts/paid');
        $queue->completeJob($consumed, 'paid-worker');

        for ($i = 0; $i < 25; ++$i) {
            $queue->enqueue($paid, 'draft', 'budget:waiting:'.$i, ['write'], 20);
        }

        // An unrelated zero-cost local model is runnable despite the first
        // 25 paid requests exceeding the remaining ten minor currency units.
        $local = $this->provider('ollama-compatible');
        $free = $queue->enqueue($local, 'draft', 'budget:free', ['write']);
        $this->assertSame($free, $queue->claimNext('free-worker')?->id);
        $this->assertSame(25, DB::table('ai_jobs')
            ->where('provider_id', $paid->id)->where('status', 'queued')->count());
        $this->assertSame(90, (int) DB::table('ai_usage')
            ->where('provider_id', $paid->id)->sum('cost_minor'));
        Http::assertNothingSent();

        // The old receipt still exists; only the new UTC day's configured
        // allowance can make the queued paid provider eligible again.
        $this->travel(1)->days();
        $this->assertSame($paid->id, $queue->claimNext('next-day-paid-worker')?->provider_id);
    }

    public function test_unreceipted_running_reservations_prevent_starvation_with_spare_capacity(): void
    {
        $queue = app(DurableAiQueue::class);
        $paid = $this->provider('openai-compatible', 2);
        $running = $queue->enqueue($paid, 'draft', 'budget:running', ['write'], 80);
        $this->assertSame($running, $queue->claimNext('first-paid-worker')?->id);

        // Paid job has 60 already recorded and 20 still reserved; only
        // 20 units remain available under a daily cap of 100.
        $queue->recordUsage($running, 'first-paid-worker', 'budget:partial', 60, 'USD');
        for ($i = 0; $i < 25; ++$i) {
            $queue->enqueue($paid, 'draft', 'budget:outstanding:'.$i, ['write'], 30);
        }
        $local = $this->provider('ollama-compatible');
        $ready = $queue->enqueue($local, 'draft', 'budget:independent', ['write']);
        $this->assertSame($ready, $queue->claimNext('local-worker')?->id);

        // Completing the first job releases its remaining reservation while
        // retaining the actual 60-minor-unit receipt for today's budget.
        $queue->completeStep($running, 'first-paid-worker', 'write', 'drafts/paid');
        $queue->completeJob($running, 'first-paid-worker');
        $this->assertSame($paid->id,
            $queue->claimNext('second-paid-worker')?->provider_id);
    }

    public function test_increasing_daily_budget_reenables_queued_paid_jobs_without_reenqueue(): void
    {
        $queue = app(DurableAiQueue::class);
        $paid = $this->provider();
        $first = $queue->enqueue($paid, 'draft', 'budget:lowered:first', ['write'], 90);
        $queue->claimNext('paid-worker');
        $queue->recordUsage($first, 'paid-worker', 'budget:lowered:usage', 90, 'USD');
        $queue->completeStep($first, 'paid-worker', 'write', 'drafts/paid');
        $queue->completeJob($first, 'paid-worker');

        $pending = $queue->enqueue($paid, 'draft', 'budget:lowered:pending', ['write'], 20);
        $this->assertNull($queue->claimNext('other-paid-worker'));
        $this->assertDatabaseHas('ai_jobs', ['id' => $pending, 'status' => 'queued']);

        $paid->update(['daily_budget_minor' => 200]);
        $this->assertSame($pending, $queue->claimNext('other-paid-worker')?->id);
        $this->assertSame(90, (int) DB::table('ai_usage')->sum('cost_minor'));
    }
}

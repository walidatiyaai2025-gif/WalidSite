<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderBacklogFairnessTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $type = 'ollama-compatible', int $slots = 1): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Fairness '.Str::random(16),
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://models.example.org/v1',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 100000,
            'concurrent_limit' => $slots,
            'timeout_seconds' => 5,
            'currency' => 'USD',
        ]);
    }

    public function test_a_saturated_provider_cannot_hide_another_providers_runnable_job(): void
    {
        Http::fake();
        foreach (['ollama-compatible', 'openai-compatible'] as $type) {
            $queue = app(DurableAiQueue::class);
            $busy = $this->provider($type);
            $reserve = $type === 'openai-compatible' ? 10 : 0;

            $running = $queue->enqueue($busy, 'draft', 'running:'.Str::uuid(),
                ['research'], $reserve);
            $this->assertSame($running, $queue->claimNext('running-'.Str::uuid())?->id);

            // A FIFO 20-row scan used to see only queued work behind this
            // already saturated provider, even though another was ready.
            $blocked = [];
            for ($i = 0; $i < 25; ++$i) {
                $blocked[] = $queue->enqueue($busy, 'draft',
                    'saturated:'.Str::uuid(), ['research'], $reserve);
            }

            $available = $this->provider();
            $ready = $queue->enqueue($available, 'draft', 'ready:'.Str::uuid(),
                ['research']);
            $claimed = $queue->claimNext('available-'.Str::uuid());
            $this->assertSame($ready, $claimed?->id);
            $this->assertSame(25, DB::table('ai_jobs')->where('provider_id', $busy->id)
                ->where('status', 'queued')->count());
            $this->assertDatabaseHas('ai_jobs', ['id' => $running, 'status' => 'running']);
            $this->assertSame(2, DB::table('job_leases')
                ->whereIn('job_id', DB::table('ai_jobs')
                    ->select('id')->whereIn('provider_id', [$busy->id, $available->id]))->count());
            Http::assertNothingSent();
        }
        $this->assertDatabaseCount('ai_usage', 0);
    }

    public function test_full_provider_becomes_eligible_again_after_the_leased_job_completes(): void
    {
        $queue = app(DurableAiQueue::class);
        $provider = $this->provider();
        $first = $queue->enqueue($provider, 'draft', 'fair:first', ['write']);
        $this->assertSame($first, $queue->claimNext('worker-one')?->id);

        for ($i = 0; $i < 25; ++$i) {
            $queue->enqueue($provider, 'draft', 'fair:waiting:'.$i, ['write']);
        }
        $this->assertNull($queue->claimNext('worker-two'));

        $queue->completeStep($first, 'worker-one', 'write', 'drafts/one');
        $queue->completeJob($first, 'worker-one', 'drafts/one');

        $resumed = $queue->claimNext('worker-two');
        $this->assertNotNull($resumed);
        $this->assertSame($provider->id, $resumed->provider_id);
        $this->assertSame('running', $resumed->status);
    }

    public function test_lowered_concurrency_does_not_oversubscribe_or_starve_other_providers(): void
    {
        $queue = app(DurableAiQueue::class);
        $limited = $this->provider('ollama-compatible', 2);
        $runningIds = [];
        for ($i = 0; $i < 2; ++$i) {
            $id = $queue->enqueue($limited, 'draft', 'limited:'.Str::uuid(), ['step']);
            $this->assertSame($id, $queue->claimNext('limited-worker-'.$i)?->id);
            $runningIds[] = $id;
        }
        for ($i = 0; $i < 25; ++$i) {
            $queue->enqueue($limited, 'draft', 'limited:waiting:'.$i, ['step']);
        }
        $limited->update(['concurrent_limit' => 1]);

        $other = $this->provider();
        $otherId = $queue->enqueue($other, 'draft', 'other:ready', ['step']);
        $this->assertSame($otherId, $queue->claimNext('other-worker')?->id);
        $this->assertSame(2, DB::table('ai_jobs')->where('provider_id', $limited->id)
            ->where('status', 'running')->count());

        $queue->completeStep($runningIds[0], 'limited-worker-0', 'step', 'drafts/0');
        $queue->completeJob($runningIds[0], 'limited-worker-0');
        $this->assertNull($queue->claimNext('new-limited-worker'));
        $queue->completeStep($runningIds[1], 'limited-worker-1', 'step', 'drafts/1');
        $queue->completeJob($runningIds[1], 'limited-worker-1');
        $this->assertSame($limited->id,
            $queue->claimNext('new-limited-worker')?->provider_id);
    }

    public function test_unknown_adapter_backlog_never_occupies_the_candidate_window(): void
    {
        Http::fake();
        $queue = app(DurableAiQueue::class);
        $unsupported = $this->provider('future-adapter');

        for ($i = 0; $i < 25; ++$i) {
            $queue->enqueue($unsupported, 'draft', 'unsupported:'.$i, ['research']);
        }
        $supported = $this->provider();
        $valid = $queue->enqueue($supported, 'draft', 'supported:ready', ['research']);

        $this->assertSame($valid, $queue->claimNext('supported-worker')?->id);
        $this->assertSame(25, DB::table('ai_jobs')
            ->where('provider_id', $unsupported->id)
            ->where('status', 'queued')->count());
        Http::assertNothingSent();
    }
}

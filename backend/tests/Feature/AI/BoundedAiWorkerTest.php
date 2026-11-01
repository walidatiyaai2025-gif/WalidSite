<?php

namespace Tests\Feature\AI;

use App\AI\AiStepHandlerRegistry;
use App\AI\BoundedAiWorker;
use App\AI\DurableAiQueue;
use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BoundedAiWorkerTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $type = 'ollama-compatible'): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Tick '.Str::random(10),
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://api.openai.com/v1',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 1,
            'daily_budget_minor' => 500,
            'currency' => 'USD',
        ]);
    }

    public function test_one_step_per_tick_preserves_requested_order_without_consuming_retry_attempts(): void
    {
        Http::fake();
        $queue = app(DurableAiQueue::class);
        $worker = app(BoundedAiWorker::class);
        $jobId = $queue->enqueue($this->provider(), 'runbook', 'tick:ordered',
            ['zebra', 'alpha', 'middle'], 0, 1);
        $seen = [];
        $handlers = ['runbook' => static function ($job, string $step) use (&$seen): string {
            $seen[] = $step;
            return 'checkpoints/'.$step;
        }];

        foreach (['checkpointed', 'checkpointed', 'completed'] as $expected) {
            $this->assertSame(['state' => $expected, 'job_id' => $jobId],
                $worker->tick('cron-worker-a', $handlers));
        }

        $this->assertSame(['zebra', 'alpha', 'middle'], $seen);
        $this->assertSame(['zebra', 'alpha', 'middle'],
            DB::table('ai_job_steps')->where('job_id', $jobId)
                ->orderBy('position')->pluck('step_key')->all());
        $this->assertSame('completed', DB::table('ai_jobs')
            ->where('id', $jobId)->value('status'));
        $this->assertSame(1, (int) DB::table('ai_jobs')
            ->where('id', $jobId)->value('attempt_count'));
        $this->assertSame(3, DB::table('ai_job_steps')
            ->where('job_id', $jobId)->where('status', 'completed')->count());
        $this->assertDatabaseCount('job_leases', 0);
        Http::assertNothingSent();
    }

    public function test_unregistered_job_types_are_not_claimed_or_failed(): void
    {
        $queue = app(DurableAiQueue::class);
        $worker = app(BoundedAiWorker::class);
        $provider = $this->provider();
        $unknown = $queue->enqueue($provider, 'unregistered', 'tick:unknown', ['research']);
        $known = $queue->enqueue($provider, 'registered', 'tick:known', ['research']);

        $this->assertSame(['state' => 'idle', 'job_id' => null],
            $worker->tick('cron-worker-a', []));
        $this->assertSame(['state' => 'completed', 'job_id' => $known],
            $worker->tick('cron-worker-a', [
                'registered' => static fn (): string => 'outputs/known',
            ]));
        $this->assertSame('queued', DB::table('ai_jobs')
            ->where('id', $unknown)->value('status'));
        $this->assertSame(0, (int) DB::table('ai_jobs')
            ->where('id', $unknown)->value('attempt_count'));
    }

    public function test_handler_error_is_redacted_and_bounded_retry_can_recover(): void
    {
        $queue = app(DurableAiQueue::class);
        $worker = app(BoundedAiWorker::class);
        $jobId = $queue->enqueue($this->provider(), 'draft', 'tick:retry', ['research'], 0, 2);

        $this->assertSame('retry', $worker->tick('cron-worker-a', [
            'draft' => static function (): never {
                throw new RuntimeException('SECRET_API_KEY_SHOULD_NOT_BE_SAVED');
            },
        ])['state']);

        $job = DB::table('ai_jobs')->where('id', $jobId)->first();
        $this->assertSame('retry', $job->status);
        $this->assertSame('step_failed', $job->last_error_code);
        $this->assertStringNotContainsString('SECRET_API_KEY', json_encode($job));
        $this->assertDatabaseCount('job_leases', 0);

        $this->travel(21)->seconds();
        $this->assertSame(['state' => 'completed', 'job_id' => $jobId],
            $worker->tick('cron-worker-b', [
                'draft' => static fn (): string => 'outputs/recovered',
            ]));
        $this->assertSame(2, (int) DB::table('ai_jobs')
            ->where('id', $jobId)->value('attempt_count'));
    }

    public function test_paid_step_records_usage_without_bypassing_budget_or_completion_gate(): void
    {
        $queue = app(DurableAiQueue::class);
        $worker = app(BoundedAiWorker::class);
        $jobId = $queue->enqueue($this->provider('openai-compatible'),
            'paid-step', 'tick:paid', ['write'], 50);

        $this->assertSame(['state' => 'completed', 'job_id' => $jobId],
            $worker->tick('cron-worker-paid', [
                'paid-step' => static function ($job, $step, $owner, $queue): string {
                    $queue->recordUsage($job->id, $owner, 'tick:usage', 50, 'USD');
                    return 'outputs/billed';
                },
            ]));
        $this->assertSame(50, (int) DB::table('ai_usage')->where('job_id', $jobId)
            ->sum('cost_minor'));
    }

    public function test_registry_rejects_duplicate_handlers_and_untrusted_job_names(): void
    {
        $registry = new AiStepHandlerRegistry();
        $registry->register('draft', static fn () => null);
        $this->assertTrue($registry->hasHandlers());
        $this->assertSame(['draft'], array_keys($registry->handlers()));

        try {
            $registry->register('draft', static fn () => null);
            $this->fail('Duplicate job handler registration accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $registry->register('../unsafe', static fn () => null);
    }

    public function test_artisan_tick_is_fail_closed_without_registered_handlers(): void
    {
        $queue = app(DurableAiQueue::class);
        $id = $queue->enqueue($this->provider(), 'draft', 'tick:unconfigured', ['research']);

        $this->assertSame(0, Artisan::call('ai:tick'));
        $this->assertStringContainsString('idle', Artisan::output());
        $this->assertSame('queued', DB::table('ai_jobs')->where('id', $id)->value('status'));
        $this->assertDatabaseCount('job_leases', 0);
    }
}

<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\Models\AiProvider;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class DurableAiQueueTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $type = 'ollama-compatible', int $budget = 1000): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Test '.Str::random(9),
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://api.openai.com/v1',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => $budget,
            'concurrent_limit' => 1,
            'currency' => 'USD',
        ]);
    }

    public function test_idempotent_enqueue_preserves_checkpoint_keys_without_ai_calls(): void
    {
        Http::fake();
        $provider = $this->provider();
        $queue = new DurableAiQueue();

        $one = $queue->enqueue($provider, 'draft', 'article:100', ['research', 'write'], 0);
        $again = $queue->enqueue($provider, 'draft', 'article:100', ['research', 'write'], 0);
        $this->assertSame($one, $again);
        $this->assertTrue(Str::isUuid($one));
        $this->assertDatabaseCount('ai_jobs', 1);
        $this->assertDatabaseCount('ai_job_steps', 2);
        $this->assertSame('queued', DB::table('ai_jobs')->where('id', $one)->value('status'));
        Http::assertNothingSent();
    }

    public function test_same_idempotency_key_cannot_change_work_definition(): void
    {
        $provider = $this->provider();
        $queue = new DurableAiQueue();
        $queue->enqueue($provider, 'draft', 'same:key', ['research']);
        $this->expectException(DomainException::class);
        $queue->enqueue($provider, 'draft', 'same:key', ['different']);
    }

    public function test_claim_is_one_per_provider_and_step_checkpoints_survive_resumption(): void
    {
        $provider = $this->provider();
        $queue = new DurableAiQueue();
        $first = $queue->enqueue($provider, 'draft', 'draft:first', ['research', 'write']);
        $second = $queue->enqueue($provider, 'draft', 'draft:second', ['research']);

        $claimed = $queue->claimNext('worker-a');
        $this->assertNotNull($claimed);
        $this->assertSame($first, $claimed->id);
        $this->assertSame(1, (int) $claimed->attempt_count);
        $this->assertNull($queue->claimNext('worker-b'));

        $queue->completeStep($first, 'worker-a', 'research', 'results/first');
        $queue->completeStep($first, 'worker-a', 'research', 'results/first');
        $this->assertSame(1, DB::table('ai_job_steps')->where('job_id', $first)
            ->where('status', 'completed')->count());
        $queue->completeStep($first, 'worker-a', 'write', 'drafts/first');
        $queue->completeJob($first, 'worker-a', 'drafts/first');
        $this->assertSame('completed', DB::table('ai_jobs')->where('id', $first)->value('status'));
        $this->assertSame(0, DB::table('job_leases')->count());
        $this->assertSame($second, $queue->claimNext('worker-b')?->id);
    }

    public function test_wrong_owner_cannot_write_other_workers_checkpoint(): void
    {
        $queue = new DurableAiQueue();
        $id = $queue->enqueue($this->provider(), 'draft', 'unauthorized:1', ['research']);
        $queue->claimNext('worker-a');

        $this->expectException(DomainException::class);
        $queue->completeStep($id, 'worker-b', 'research', 'stolen');
    }

    public function test_paid_jobs_reserve_budget_and_deduplicate_usage_receipts(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible', 250);
        $first = $queue->enqueue($provider, 'draft', 'paid:a', ['write'], 200);
        $queue->enqueue($provider, 'draft', 'paid:b', ['write'], 100);
        $this->assertSame($first, $queue->claimNext('worker-a')?->id);
        $this->assertNull($queue->claimNext('worker-b'));

        $this->assertTrue($queue->recordUsage($first, 'worker-a', 'usage:first', 180, 'USD',
            'test-model', ['input_tokens' => 20, 'output_tokens' => 5]));
        $this->assertFalse($queue->recordUsage($first, 'worker-a', 'usage:first', 180, 'USD',
            'test-model', ['input_tokens' => 20, 'output_tokens' => 5]));
        $this->assertDatabaseCount('ai_usage', 1);
        $this->assertSame(180, (int) DB::table('ai_usage')->sum('cost_minor'));
        $queue->completeStep($first, 'worker-a', 'write', 'drafts/paid');
        $queue->completeJob($first, 'worker-a');
        // Actual daily spend 180 plus next estimate 100 exceeds 250.
        $this->assertNull($queue->claimNext('worker-b'));
    }

    public function test_archived_provider_cannot_claim_queued_ai_work_even_when_enabled(): void
    {
        $queue = new DurableAiQueue();
        $archived = $this->provider();
        $active = $this->provider();
        $blockedJob = $queue->enqueue($archived, 'draft', 'archived:blocked', ['research']);
        $activeJob = $queue->enqueue($active, 'draft', 'archived:active', ['research']);

        // Deletion is an administrative revocation, not an invitation to
        // bypass Eloquent's soft-delete filter via raw DB provider queries.
        $archived->delete();
        $this->assertTrue($archived->trashed());
        $this->assertSame($activeJob, $queue->claimNext('worker-a')?->id);
        $this->assertSame('queued', DB::table('ai_jobs')->where('id', $blockedJob)->value('status'));

        $queue->completeStep($activeJob, 'worker-a', 'research', 'drafts/active');
        $queue->completeJob($activeJob, 'worker-a');
        $this->assertNull($queue->claimNext('worker-b'));
    }

    public function test_paid_job_without_reserved_budget_cannot_claim(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible', 100);
        $queue->enqueue($provider, 'draft', 'unreserved', ['write'], 0);
        $this->assertNull($queue->claimNext('worker-a'));
    }

    public function test_provider_spend_cap_rejects_excess_charges_without_partial_writes(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible', 100);
        $id = $queue->enqueue($provider, 'draft', 'budget:1', ['write'], 50);
        $queue->claimNext('worker-a');
        try {
            $queue->recordUsage($id, 'worker-a', 'too:expensive', 101, 'USD');
            $this->fail('Excess usage was accepted.');
        } catch (DomainException $e) {
            $this->assertSame('Daily provider spending cap reached.', $e->getMessage());
        }
        $this->assertDatabaseCount('ai_usage', 0);
    }

    public function test_three_failed_actions_open_a_temporary_provider_circuit(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider();
        for ($i = 0; $i < 4; ++$i) {
            $queue->enqueue($provider, 'draft', 'circuit:'.$i, ['research']);
        }
        for ($i = 0; $i < 3; ++$i) {
            $job = $queue->claimNext('worker-a');
            $this->assertNotNull($job);
            $queue->failJob($job->id, 'worker-a', 'provider_timeout');
        }
        $this->assertNull($queue->claimNext('worker-a'));
        $state = DB::table('ai_provider_circuits')->where('provider_id', $provider->id)->first();
        $this->assertSame(3, (int) $state->consecutive_failures);
        $this->assertNotNull($state->opened_until);

        $this->travel(6)->minutes();
        $this->assertNotNull($queue->claimNext('worker-a'));
    }

    public function test_expired_worker_lease_recovers_only_bounded_jobs(): void
    {
        $queue = new DurableAiQueue();
        $id = $queue->enqueue($this->provider(), 'draft', 'lease:1', ['step']);
        $queue->claimNext('worker-a');
        DB::table('job_leases')->where('job_id', $id)
            ->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $queue->recoverExpired());
        $this->assertSame('retry', DB::table('ai_jobs')->where('id', $id)->value('status'));
        $this->assertDatabaseCount('job_leases', 0);
        $this->travel(31)->seconds();
        $this->assertSame($id, $queue->claimNext('worker-b')?->id);
    }


    public function test_cumulative_usage_cannot_exceed_job_reservation_even_with_daily_headroom(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible', 1000);
        $id = $queue->enqueue($provider, 'draft', 'reservation:multi', ['write'], 120);
        $this->assertSame($id, $queue->claimNext('worker-a')?->id);

        $this->assertTrue($queue->recordUsage($id, 'worker-a', 'res:first', 80, 'USD'));
        $this->assertTrue($queue->recordUsage($id, 'worker-a', 'res:second', 40, 'USD'));
        // An identical replay must stay idempotent even when at the limit.
        $this->assertFalse($queue->recordUsage($id, 'worker-a', 'res:second', 40, 'USD'));

        try {
            $queue->recordUsage($id, 'worker-a', 'res:over', 1, 'USD');
            $this->fail('Cumulative receipts exceeded the reserved job cost.');
        } catch (DomainException $exception) {
            $this->assertSame('AI job usage exceeds reserved cost.', $exception->getMessage());
        }

        $this->assertSame(120, (int) DB::table('ai_usage')->where('job_id', $id)
            ->sum('cost_minor'));
        $this->assertSame(2, DB::table('ai_usage')->where('job_id', $id)->count());
        $this->assertSame('running', DB::table('ai_jobs')->where('id', $id)
            ->value('status'));
    }

    public function test_single_receipt_cannot_exceed_own_reservation(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible', 1000);
        $id = $queue->enqueue($provider, 'draft', 'reservation:single', ['write'], 50);
        $this->assertSame($id, $queue->claimNext('worker-a')?->id);

        try {
            $queue->recordUsage($id, 'worker-a', 'res:too-large', 51, 'USD');
            $this->fail('An over-reservation receipt was accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('AI job usage exceeds reserved cost.', $exception->getMessage());
        }
        $this->assertDatabaseCount('ai_usage', 0);
    }

    public function test_free_local_usage_allows_zero_cost_but_never_unreserved_charges(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('ollama-compatible', 1000);
        $id = $queue->enqueue($provider, 'draft', 'reservation:local', ['write'], 0);
        $this->assertSame($id, $queue->claimNext('worker-a')?->id);
        $this->assertTrue($queue->recordUsage($id, 'worker-a', 'res:local-zero', 0, 'USD'));

        try {
            $queue->recordUsage($id, 'worker-a', 'res:local-charged', 1, 'USD');
            $this->fail('Unreserved local-provider charges were accepted.');
        } catch (DomainException $exception) {
            $this->assertSame('AI job usage exceeds reserved cost.', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('ai_usage')->where('job_id', $id)->count());
        $this->assertSame(0, (int) DB::table('ai_usage')->where('job_id', $id)
            ->sum('cost_minor'));
    }

    public function test_unsafe_opaque_output_reference_is_rejected(): void
    {
        $queue = new DurableAiQueue();
        $id = $queue->enqueue($this->provider(), 'draft', 'safe:1', ['step']);
        $queue->claimNext('worker-a');
        $this->expectException(InvalidArgumentException::class);
        $queue->completeStep($id, 'worker-a', 'step', 'https://secret.example/token');
    }
}

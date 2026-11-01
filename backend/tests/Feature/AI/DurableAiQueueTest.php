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

    public function test_heartbeat_extends_active_lease_without_losing_checkpoints(): void
    {
        $queue = new DurableAiQueue();
        $jobId = $queue->enqueue($this->provider(), 'draft', 'heartbeat:active', ['research']);
        $this->assertSame($jobId, $queue->claimNext('worker-a', 60)?->id);
        $initialExpiry = DB::table('job_leases')->where('job_id', $jobId)->value('expires_at');

        $this->travel(45)->seconds();
        $queue->heartbeat($jobId, 'worker-a', 60);
        $updated = DB::table('job_leases')->where('job_id', $jobId)->first();
        $this->assertSame(2, (int) $updated->lease_version);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($updated->expires_at)
            ->greaterThan(\Illuminate\Support\Carbon::parse($initialExpiry)));

        // The original lease would have expired; the renewed owner may still
        // checkpoint and complete, and the lease disappears at completion.
        $this->travel(30)->seconds();
        $queue->completeStep($jobId, 'worker-a', 'research', 'drafts/heartbeat');
        $queue->completeJob($jobId, 'worker-a', 'drafts/heartbeat');
        $this->assertSame('completed', DB::table('ai_jobs')->where('id', $jobId)->value('status'));
        $this->assertDatabaseCount('job_leases', 0);
    }

    public function test_heartbeat_rejects_wrong_owner_and_does_not_change_the_lease(): void
    {
        $queue = new DurableAiQueue();
        $jobId = $queue->enqueue($this->provider(), 'draft', 'heartbeat:unauthorized', ['research']);
        $queue->claimNext('worker-a', 60);

        try {
            $queue->heartbeat($jobId, 'worker-b', 120);
            $this->fail('Other worker extended the lease.');
        } catch (DomainException $exception) {
            $this->assertSame('An active matching lease is required.', $exception->getMessage());
        }
        $this->assertSame(1, (int) DB::table('job_leases')
            ->where('job_id', $jobId)->value('lease_version'));
    }

    public function test_heartbeat_cannot_resurrect_expired_or_completed_work(): void
    {
        $queue = new DurableAiQueue();
        $expired = $queue->enqueue($this->provider(), 'draft', 'heartbeat:expired', ['research']);
        $queue->claimNext('worker-a', 60);
        $this->travel(61)->seconds();

        try {
            $queue->heartbeat($expired, 'worker-a');
            $this->fail('Expired worker lease was revived.');
        } catch (DomainException $exception) {
            $this->assertSame('An active matching lease is required.', $exception->getMessage());
        }
        $this->assertSame(1, $queue->recoverExpired());
        $this->assertSame('retry', DB::table('ai_jobs')->where('id', $expired)->value('status'));

        $completed = $queue->enqueue($this->provider(), 'draft', 'heartbeat:finished', ['research']);
        $queue->claimNext('worker-b');
        $queue->completeStep($completed, 'worker-b', 'research', 'drafts/finished');
        $queue->completeJob($completed, 'worker-b');
        $this->expectException(DomainException::class);
        $queue->heartbeat($completed, 'worker-b');
    }

    public function test_heartbeat_is_capped_at_five_minutes_per_claim(): void
    {
        $queue = new DurableAiQueue();
        $jobId = $queue->enqueue($this->provider(), 'draft', 'heartbeat:deadline', ['research']);
        $queue->claimNext('worker-a', 60);
        $claimedAt = \Illuminate\Support\Carbon::parse(DB::table('job_leases')
            ->where('job_id', $jobId)->value('created_at'));

        for ($i = 0; $i < 7; ++$i) {
            $this->travel(40)->seconds();
            $queue->heartbeat($jobId, 'worker-a', 120);
        }

        $lease = DB::table('job_leases')->where('job_id', $jobId)->first();
        $this->assertSame(8, (int) $lease->lease_version);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($lease->expires_at)
            ->lessThanOrEqualTo($claimedAt->copy()->addMinutes(5)));

        $this->travel(21)->seconds();
        try {
            $queue->heartbeat($jobId, 'worker-a', 120);
            $this->fail('Worker heartbeat escaped the maximum attempt duration.');
        } catch (DomainException $exception) {
            $this->assertSame('An active matching lease is required.', $exception->getMessage());
        }
        $this->assertSame(1, $queue->recoverExpired());
        $this->assertSame('retry', DB::table('ai_jobs')->where('id', $jobId)->value('status'));
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

    public function test_concurrent_claims_count_remaining_reservations_without_double_charging_receipts(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible', 250);
        $provider->update(['concurrent_limit' => 3]);

        $first = $queue->enqueue($provider, 'draft', 'remaining:first', ['write'], 100);
        $second = $queue->enqueue($provider, 'draft', 'remaining:second', ['write'], 100);
        $third = $queue->enqueue($provider, 'draft', 'remaining:third', ['write'], 100);

        $this->assertSame($first, $queue->claimNext('worker-a')?->id);
        $this->assertTrue($queue->recordUsage(
            $first, 'worker-a', 'remaining:receipt', 80, 'USD'
        ));

        // Spend 80 plus the first job's outstanding 20 plus a new
        // reservation of 100 is 200, below the provider's daily cap.
        // Double-counting the full original 100 would reject this claim.
        $this->assertSame($second, $queue->claimNext('worker-b')?->id);

        // A third 100-cost reservation would require 300 in total
        // (80 spent + 20 outstanding + 100 outstanding + 100 new).
        $this->assertNull($queue->claimNext('worker-c'));
        $this->assertSame('queued', DB::table('ai_jobs')->where('id', $third)->value('status'));
        $this->assertSame(80, (int) DB::table('ai_usage')->sum('cost_minor'));
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

    public function test_ineligible_provider_backlog_cannot_starve_later_healthy_jobs(): void
    {
        $queue = new DurableAiQueue();
        $disabled = $this->provider();
        $unhealthy = $this->provider();
        $archived = $this->provider();

        // More than the 20-job admission scan is blocked, across three
        // distinct administrative revocation states.
        foreach ([$disabled, $unhealthy, $archived] as $provider) {
            for ($i = 0; $i < 9; ++$i) {
                $queue->enqueue($provider, 'draft', 'blocked:'.$provider->id.':'.$i, ['research']);
            }
        }
        $disabled->update(['enabled' => false]);
        $unhealthy->update(['health_status' => 'failed']);
        $archived->delete();

        $healthy = $this->provider();
        $ready = $queue->enqueue($healthy, 'draft', 'eligible:after-backlog', ['research']);

        $this->assertSame($ready, $queue->claimNext('worker-a')?->id);
        $this->assertSame(27, DB::table('ai_jobs')
            ->whereIn('provider_id', [$disabled->id, $unhealthy->id, $archived->id])
            ->where('status', 'queued')->count());
        $this->assertDatabaseCount('job_leases', 1);
    }

    public function test_unfunded_paid_backlog_cannot_starve_runnable_work(): void
    {
        $queue = new DurableAiQueue();
        $paid = $this->provider('openai-compatible', 100);

        // Both kinds of impossible paid work must be excluded BEFORE the
        // 20-candidate scan: no reservation and reservation over daily cap.
        for ($i = 0; $i < 13; ++$i) {
            $queue->enqueue($paid, 'draft', 'unfunded:zero:'.$i, ['write'], 0);
        }
        $firstLarge = null;
        for ($i = 0; $i < 13; ++$i) {
            $id = $queue->enqueue($paid, 'draft', 'unfunded:large:'.$i, ['write'], 200);
            $firstLarge ??= $id;
        }

        $local = $this->provider('ollama-compatible', 0);
        $ready = $queue->enqueue($local, 'draft', 'unfunded:runnable', ['research'], 0);
        $this->assertSame($ready, $queue->claimNext('worker-a')?->id);
        $this->assertSame(26, DB::table('ai_jobs')->where('provider_id', $paid->id)
            ->where('status', 'queued')->count());
        $queue->completeStep($ready, 'worker-a', 'research', 'drafts/runnable');
        $queue->completeJob($ready, 'worker-a');

        // These jobs were not discarded. Raising the admin budget makes the
        // previously oversized reservations runnable without re-enqueueing.
        $paid->update(['daily_budget_minor' => 250]);
        $this->assertSame($firstLarge, $queue->claimNext('worker-b')?->id);
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
        // Simulate an administrator lowering the daily cap after admission.
        // The attempted usage fits the job reservation but exceeds the
        // newly enforced provider cap, so this tests the daily gate alone.
        DB::table('ai_providers')->where('id', $provider->id)
            ->update(['daily_budget_minor' => 40]);
        try {
            $queue->recordUsage($id, 'worker-a', 'too:expensive', 41, 'USD');
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

<?php

namespace Tests\Feature\AI;

use App\AI\DurableAiQueue;
use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderCapacityAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $type = 'ollama-compatible'): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Capacity '.Str::random(14),
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://models.example.org/v1',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 1000,
            'concurrent_limit' => 1,
            'currency' => 'USD',
        ]);
    }

    public function test_zero_capacity_backlog_does_not_starve_healthy_provider_or_contact_ai(): void
    {
        Http::fake();
        $queue = new DurableAiQueue();
        $paused = $this->provider();
        $paused->update(['concurrent_limit' => 0]);

        for ($i = 0; $i < 25; ++$i) {
            $queue->enqueue($paused, 'draft', 'paused:'.$i, ['research']);
        }

        $healthy = $this->provider();
        $ready = $queue->enqueue($healthy, 'draft', 'capacity:ready', ['research']);
        $this->assertSame($ready, $queue->claimNext('capacity-worker')?->id);
        $this->assertSame(25, DB::table('ai_jobs')
            ->where('provider_id', $paused->id)
            ->where('status', 'queued')->count());
        $this->assertSame(0, DB::table('job_leases')
            ->whereIn('job_id', DB::table('ai_jobs')
                ->select('id')->where('provider_id', $paused->id))->count());
        Http::assertNothingSent();
    }

    public function test_zero_capacity_blocks_previously_enqueued_work_until_admin_reenables_slot(): void
    {
        Http::fake();
        $queue = new DurableAiQueue();
        $provider = $this->provider('openai-compatible');
        $id = $queue->enqueue($provider, 'editorial-draft', 'capacity:paid-paused',
            ['generate'], 25);

        $provider->update(['concurrent_limit' => 0]);
        $this->assertNull($queue->claimNext('capacity-worker'));
        $this->assertDatabaseHas('ai_jobs', ['id' => $id, 'status' => 'queued']);
        $this->assertDatabaseCount('job_leases', 0);
        $this->assertDatabaseCount('ai_usage', 0);
        Http::assertNothingSent();

        $provider->update(['concurrent_limit' => 1]);
        $this->assertSame($id, $queue->claimNext('capacity-worker')?->id);
        $this->assertDatabaseHas('ai_jobs', ['id' => $id, 'status' => 'running']);
    }

    public function test_lowering_capacity_stops_new_claims_without_revoking_existing_lease(): void
    {
        $queue = new DurableAiQueue();
        $provider = $this->provider();
        $first = $queue->enqueue($provider, 'draft', 'capacity:first', ['research']);
        $second = $queue->enqueue($provider, 'draft', 'capacity:second', ['research']);
        $this->assertSame($first, $queue->claimNext('first-worker')?->id);

        $provider->update(['concurrent_limit' => 0]);
        $this->assertNull($queue->claimNext('second-worker'));

        // Work already leased may checkpoint and finish; it must not open
        // another admission slot while the administrator has paused work.
        $queue->completeStep($first, 'first-worker', 'research', 'drafts/completed');
        $queue->completeJob($first, 'first-worker');
        $this->assertNull($queue->claimNext('second-worker'));
        $this->assertDatabaseHas('ai_jobs', ['id' => $second, 'status' => 'queued']);

        $provider->update(['concurrent_limit' => 1]);
        $this->assertSame($second, $queue->claimNext('second-worker')?->id);
    }
}

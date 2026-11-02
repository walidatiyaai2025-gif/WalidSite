<?php

namespace Tests\Feature\AI;

use App\AI\AiStepHandlerRegistry;
use App\AI\BoundedAiWorker;
use App\AI\EditorialJobDispatcher;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiProviderOutageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $type): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Outage '.Str::random(12),
            'provider_type' => $type,
            'base_url' => $type === 'openai-compatible'
                ? 'https://models.example.org/v1' : 'http://127.0.0.1:11434',
            'secret_ciphertext' => $type === 'openai-compatible'
                ? 'fixture-private-test-token' : null,
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => $type === 'openai-compatible' ? 2000 : 0,
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'currency' => 'USD',
        ]);
    }

    private function draft(string $slug): ArticleRevision
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Safe technical backup exercise',
            'title_ar' => 'تدريب تقني آمن للنسخ الاحتياطي',
            'canonical_lang' => 'en',
        ]);

        return $article->revisions()->create([
            'revision_no' => 1,
            'status' => 'draft',
            'body_md' => 'Explain the recovery procedure and mark sources as unverified.',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/recovery',
                'verified' => false,
            ]],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => false,
            ],
        ]);
    }

    public function test_malformed_paid_provider_outputs_open_circuit_without_blocking_local_drafts(): void
    {
        Http::fake([
            'https://models.example.org/v1/chat/completions' =>
                Http::response(['choices' => [['message' => ['content' => null]]]], 200),
            'http://127.0.0.1:11434/api/generate' =>
                Http::response(['response' => '# Draft requiring independent source review'], 200),
        ]);

        $paid = $this->provider('openai-compatible');
        $local = $this->provider('ollama-compatible');
        $dispatcher = app(EditorialJobDispatcher::class);
        $worker = app(BoundedAiWorker::class);
        $handlers = app(AiStepHandlerRegistry::class)->handlers();

        // Three independently submitted paid jobs return malformed 200s.
        // An HTTP success status is NOT proof of a successful AI result.
        for ($i = 1; $i <= 3; ++$i) {
            $draft = $this->draft('paid-malformed-'.$i);
            $id = $dispatcher->enqueue($draft, $paid, 'paid-text-model', false, 50);
            $this->assertSame(
                ['state' => 'retry', 'job_id' => $id],
                $worker->tick('outage-worker', $handlers)
            );
            $this->assertSame(1, $draft->article->revisions()->count());
            $this->assertDatabaseHas('ai_jobs', [
                'id' => $id,
                'last_error_code' => 'step_failed',
            ]);
        }

        $circuit = DB::table('ai_provider_circuits')
            ->where('provider_id', $paid->id)->first();
        $this->assertNotNull($circuit);
        $this->assertSame(3, (int) $circuit->consecutive_failures);
        $this->assertNotNull($circuit->opened_until);

        // The open circuit must not consume even newly queued paid work.
        $blocked = $dispatcher->enqueue($this->draft('paid-blocked-later'),
            $paid, 'paid-text-model', false, 50);
        $good = $this->draft('local-continues');
        $localId = $dispatcher->enqueue($good, $local, 'local-text-model');

        $this->assertSame(
            ['state' => 'completed', 'job_id' => $localId],
            $worker->tick('local-worker', $handlers)
        );
        $this->assertDatabaseHas('ai_jobs', ['id' => $blocked, 'status' => 'queued']);
        $this->assertSame(2, $good->article->revisions()->count());
        $latest = $good->article->revisions()->orderByDesc('revision_no')->firstOrFail();
        $this->assertSame('draft', $latest->status);
        $this->assertFalse($latest->hasVerifiedEvidence());
        $this->assertSame('draft', $good->article->fresh()->status);
        $this->assertDatabaseCount('ai_usage', 1); // Local zero-cost receipt only.
        $this->assertSame(0, (int) DB::table('ai_usage')->sum('cost_minor'));
        Http::assertSentCount(4); // Three paid attempts, one local generation.
    }
}

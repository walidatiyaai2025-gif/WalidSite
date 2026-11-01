<?php

namespace Tests\Feature\AI;

use App\AI\EditorialJobDispatcher;
use App\AI\AiStepHandlerRegistry;
use App\AI\BoundedAiWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EditorialQueueAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function article(): Article
    {
        return Article::create([
            'slug' => 'editorial-admission-regression',
            'title_en' => 'A reviewed technical article',
            'title_ar' => 'مقال تقني خاضع للمراجعة',
            'canonical_lang' => 'en',
        ]);
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Admission local provider',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 0,
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'currency' => 'USD',
        ]);
    }

    private function revision(Article $article): ArticleRevision
    {
        return $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Research draft awaiting independent review.',
            'status' => 'draft',
            'citations_json' => [
                ['source_url' => 'https://docs.example.org/reference', 'verified' => false],
            ],
            'validation_json' => [
                'citations_passed' => false, 'technical_passed' => false,
            ],
        ]);
    }

    public function test_reviewed_revision_cannot_be_queued_from_stale_draft_instance(): void
    {
        $article = $this->article();
        $revision = $this->revision($article);
        $staleDraft = ArticleRevision::query()->findOrFail($revision->id);

        // Independent reviewer changes the DB after the caller loaded draft.
        $revision->update(['status' => 'validated']);
        $this->assertSame('draft', $staleDraft->status);
        $this->assertSame('validated', $revision->fresh()->status);

        try {
            app(EditorialJobDispatcher::class)->enqueue(
                $staleDraft, $this->provider(), 'local-model'
            );
            $this->fail('An already reviewed revision entered the AI queue.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'A current draft and safe model are required.',
                $exception->getMessage()
            );
        }

        $this->assertSame('validated', $staleDraft->status);
        $this->assertDatabaseCount('ai_jobs', 0);
        $this->assertDatabaseCount('ai_job_steps', 0);
    }

    public function test_current_latest_draft_still_enqueues_once(): void
    {
        $article = $this->article();
        $draft = $this->revision($article);
        $provider = $this->provider();
        $dispatcher = app(EditorialJobDispatcher::class);

        $first = $dispatcher->enqueue($draft, $provider, 'local-model');
        $this->assertSame($first, $dispatcher->enqueue($draft, $provider, 'local-model'));
        $this->assertDatabaseHas('ai_jobs', [
            'id' => $first, 'job_type' => 'editorial-draft',
            'status' => 'queued', 'reserved_budget_minor' => 0,
        ]);
        $this->assertDatabaseCount('ai_jobs', 1);
        $this->assertDatabaseCount('ai_job_steps', 1);
    }

    public function test_changed_queued_body_cannot_run_and_new_intent_is_independent(): void
    {
        $article = $this->article();
        $draft = $this->revision($article);
        $provider = $this->provider();
        $dispatcher = app(EditorialJobDispatcher::class);
        $worker = app(BoundedAiWorker::class);
        $handlers = app(AiStepHandlerRegistry::class)->handlers();

        $original = $dispatcher->enqueue($draft, $provider, 'local-model');
        $firstKey = DB::table('ai_jobs')->where('id', $original)->value('idempotency_key');
        $this->assertMatchesRegularExpression('/:[a-f0-9]{16}$/D', $firstKey);
        $this->assertLessThanOrEqual(160, strlen($firstKey));
        $this->assertStringNotContainsString('Research draft', $firstKey);
        $this->assertStringNotContainsString('docs.example.org', $firstKey);

        // An editor changes the body after this job was queued, not its UUID.
        $draft->update(['body_md' => 'New instructions, independently queued']);
        $current = $dispatcher->enqueue($draft, $provider, 'local-model');
        $this->assertNotSame($original, $current);
        $this->assertSame($current, $dispatcher->enqueue($draft, $provider, 'local-model'));

        Http::fake(['http://127.0.0.1:11434/api/generate' =>
            Http::response(['response' => '# Generated but unreviewed'], 200)]);

        $failed = $worker->tick('editorial-intent-worker', $handlers);
        $this->assertSame(['state' => 'retry', 'job_id' => $original], $failed);
        Http::assertNothingSent();
        $this->assertSame('step_failed',
            DB::table('ai_jobs')->where('id', $original)->value('last_error_code'));
        $this->assertSame(1, $article->revisions()->count());

        $completed = $worker->tick('editorial-intent-worker', $handlers);
        $this->assertSame(['state' => 'completed', 'job_id' => $current], $completed);
        $this->assertSame(2, $article->revisions()->count());
        $latest = $article->revisions()->orderByDesc('revision_no')->firstOrFail();
        $this->assertSame('draft', $latest->status);
        $this->assertFalse($latest->hasVerifiedEvidence());
        Http::assertSentCount(1);
    }

    public function test_queued_source_and_topic_edits_fail_closed_before_network_calls(): void
    {
        $article = $this->article();
        $draft = $this->revision($article);
        $dispatcher = app(EditorialJobDispatcher::class);
        $provider = $this->provider();
        $first = $dispatcher->enqueue($draft, $provider, 'local-model');

        // A source swap does not change the revision UUID or draft status.
        $draft->update(['citations_json' => [
            ['source_url' => 'https://docs.example.org/replacement', 'verified' => false],
        ]]);
        Http::fake();
        $result = app(BoundedAiWorker::class)->tick(
            'editorial-source-worker', app(AiStepHandlerRegistry::class)->handlers()
        );
        $this->assertSame(['state' => 'retry', 'job_id' => $first], $result);
        Http::assertNothingSent();

        $second = $dispatcher->enqueue($draft, $provider, 'local-model');
        $article->update(['title_en' => 'Changed unreviewed technical topic']);
        $result = app(BoundedAiWorker::class)->tick(
            'editorial-source-worker', app(AiStepHandlerRegistry::class)->handlers()
        );
        $this->assertSame(['state' => 'retry', 'job_id' => $second], $result);
        Http::assertNothingSent();
        $this->assertSame(1, $article->revisions()->count());

        $third = $dispatcher->enqueue($draft, $provider, 'local-model');
        $this->assertNotSame($second, $third);
        $this->assertDatabaseCount('ai_jobs', 3);
    }

}

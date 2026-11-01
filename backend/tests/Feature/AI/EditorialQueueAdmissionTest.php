<?php

namespace Tests\Feature\AI;

use App\AI\EditorialJobDispatcher;
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
}

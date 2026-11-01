<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicSearchTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, bool $revoke = false, string $title = 'Verified database guide'): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => $title,
            'title_ar' => 'دليل قواعد البيانات موثق',
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Documented disaster recovery steps',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/source',
                'verified' => true,
            ]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();
        if ($revoke) {
            // SQL publication flags remain valid; only the live revision
            // evidence check can reject the withdrawn proof.
            DB::table('article_revisions')->where('id', $revision->id)
                ->update(['validation_json' => json_encode([
                    'citations_passed' => false, 'technical_passed' => true,
                ], JSON_THROW_ON_ERROR)]);
        }
        return $article;
    }

    private function project(string $slug, bool $unsafe = false, string $title = 'Verified recovery project'): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => $title,
            'summary_en' => 'Documented recovery evidence',
            'summary_ar' => 'دليل التعافي موثق',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $unsafe
                ? 'https://docs.example.org/internal?token=private'
                : 'https://docs.example.org/safe-source',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
        return $project;
    }

    public function test_missing_query_returns_honest_empty_bilingual_response(): void
    {
        foreach (['en', 'ar'] as $lang) {
            $this->getJson('/api/v1/public/search?lang='.$lang)->assertOk()
                ->assertJsonCount(0, 'data.articles')
                ->assertJsonCount(0, 'data.projects')
                ->assertJsonPath('meta.lang', $lang)
                ->assertJsonPath('meta.per_type_limit', 10);
        }
    }

    public function test_search_finds_only_live_approved_articles_and_safe_projects_in_both_languages(): void
    {
        $goodArticle = $this->article('safe-recovery');
        $goodProject = $this->project('safe-recovery-project');
        $draft = Article::create([
            'slug' => 'unpublished-recovery',
            'title_en' => 'Private recovery instructions',
            'title_ar' => 'تعليمات داخلية',
            'canonical_lang' => 'en',
        ]);
        $withdrawn = $this->article('revoked-recovery', true);
        $unsafe = $this->project('unsafe-recovery', true);
        $private = $this->project('private-recovery');
        $private->update(['visibility' => 'private']);

        $english = $this->getJson('/api/v1/public/search?q=recovery&lang=en')
            ->assertOk()->assertJsonCount(1, 'data.articles')
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.articles.0.slug', $goodArticle->slug)
            ->assertJsonPath('data.articles.0.title', 'Verified database guide')
            ->assertJsonPath('data.projects.0.slug', $goodProject->slug)
            ->assertJsonPath('data.projects.0.summary', 'Documented recovery evidence')
            ->assertDontSee($draft->slug)
            ->assertDontSee($withdrawn->slug)
            ->assertDontSee($unsafe->slug)
            ->assertDontSee($private->slug)
            ->assertDontSee('token=private');
        $this->assertStringContainsString('/blog/safe-recovery', $english->json('data.articles.0.url'));
        $this->assertStringContainsString('/projects/safe-recovery-project', $english->json('data.projects.0.url'));

        $this->getJson('/api/v1/public/search?q='.urlencode('دليل').'&lang=ar')
            ->assertOk()->assertJsonCount(1, 'data.articles')
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.articles.0.title', 'دليل قواعد البيانات موثق')
            ->assertJsonPath('data.projects.0.summary', 'دليل التعافي موثق')
            ->assertDontSee('unpublished-recovery');
    }

    public function test_verified_results_after_revoked_prefix_fill_ten_slots_per_type(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $bad = $this->article('revoked-db-'.$i, true);
            $bad->forceFill(['published_at' => now()->subMinute()])->save();
            $badProject = $this->project('unsafe-db-'.$i, true);
            $badProject->update(['published_at' => now()->subMinute()]);
        }
        for ($i = 1; $i <= 11; $i++) {
            $safe = $this->article('approved-db-'.$i);
            $safe->forceFill(['published_at' => now()->subDays(3)])->save();
            $safeProject = $this->project('approved-db-project-'.$i);
            $safeProject->update(['published_at' => now()->subDays(3)]);
        }

        $response = $this->getJson('/api/v1/public/search?q=recovery&lang=en')
            ->assertOk()->assertJsonCount(10, 'data.articles')
            ->assertJsonCount(10, 'data.projects')
            ->assertDontSee('revoked-db-')
            ->assertDontSee('unsafe-db-')
            ->assertDontSee('token=private');
        $this->assertCount(10, array_unique(array_column($response->json('data.articles'), 'slug')));
        $this->assertCount(10, array_unique(array_column($response->json('data.projects'), 'slug')));
    }

    public function test_article_search_uses_pinned_reviewed_body_not_unpublished_drafts(): void
    {
        $article = $this->article('body-only-search', false, 'Independent technical report');
        $this->getJson('/api/v1/public/search?q=disaster')->assertOk()
            ->assertJsonCount(1, 'data.articles')
            ->assertJsonPath('data.articles.0.slug', $article->slug);

        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => 'PRIVATE_UNPUBLISHED_SENTINEL',
            'status' => 'draft',
            'citations_json' => [],
            'validation_json' => ['citations_passed' => false, 'technical_passed' => false],
        ]);
        $this->getJson('/api/v1/public/search?q=PRIVATE_UNPUBLISHED_SENTINEL')
            ->assertOk()->assertJsonCount(0, 'data.articles');
        $article->revisions()->where('revision_no', 1)->firstOrFail()
            ->update(['validation_json' => [
                'citations_passed' => false, 'technical_passed' => true,
            ]]);
        $this->getJson('/api/v1/public/search?q=disaster')->assertOk()
            ->assertJsonCount(0, 'data.articles');
    }

    public function test_sql_like_wildcards_are_literal_and_untrusted_input_is_rejected(): void
    {
        $this->article('literal-specials', false, 'SQL!_% verification');
        $this->article('ordinary-title', false, 'SQL ordinary verification');
        $this->project('literal-project', false, 'SQL!_% recovery project');
        $this->project('ordinary-project', false, 'SQL ordinary recovery project');

        foreach (['%', '_', '!', '!_%'] as $literal) {
            $response = $this->getJson('/api/v1/public/search?q='.urlencode($literal))
                ->assertOk()->assertJsonCount(1, 'data.articles')
                ->assertJsonCount(1, 'data.projects')
                ->assertJsonPath('data.articles.0.slug', 'literal-specials')
                ->assertJsonPath('data.projects.0.slug', 'literal-project');
            $response->assertDontSee('ordinary-title')
                ->assertDontSee('ordinary-project');
        }

        foreach ([
            '/api/v1/public/search?lang=fr',
            '/api/v1/public/search?q[]=%25',
            '/api/v1/public/search?q='.str_repeat('x', 81),
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
    }
}

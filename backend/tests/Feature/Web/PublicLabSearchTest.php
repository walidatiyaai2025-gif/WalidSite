<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLabSearchTest extends TestCase
{
    use RefreshDatabase;

    private function publishedArticle(): Article
    {
        $article = Article::create([
            'slug' => 'database-runbook',
            'title_en' => 'Verified database runbook',
            'title_ar' => 'دليل قواعد البيانات الموثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'status' => 'validated',
            'body_md' => '# Documented database operation',
            'citations_json' => [['source_url' => 'https://example.org/guide', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();
        return $article;
    }

    public function test_lab_and_search_have_honest_empty_states_and_language_links(): void
    {
        $this->get('/lab')->assertOk()->assertSee('Technology lab')
            ->assertSee('No reviewed guides or live tool demos');
        $this->get('/search')->assertOk()->assertSee('Enter a query')
            ->assertSee('role="search"', false);
        $this->get('/lab?lang=ar')->assertOk()->assertSee('dir="rtl"', false)
            ->assertSee('المختبر التقني');
        $this->get('/search?lang=ar')->assertOk()->assertSee('dir="rtl"', false)
            ->assertSee('البحث في المعلومات الموثقة');
        $this->get('/')->assertOk()->assertSee('Tech lab')->assertSee('Search');
    }

    public function test_source_verified_article_is_found_but_draft_and_revoked_revision_are_not(): void
    {
        $article = $this->publishedArticle();
        $draft = Article::create([
            'slug' => 'hidden-secret-draft',
            'title_en' => 'Hidden draft technical notes',
            'title_ar' => 'مسودة غير عامة',
            'canonical_lang' => 'en',
        ]);

        $this->get('/lab')->assertOk()->assertSee($article->title_en)
            ->assertDontSee($draft->title_en);
        $this->get('/search?q=database')->assertOk()->assertSee($article->title_en)
            ->assertDontSee($draft->title_en);

        $article->revisions()->where('revision_no', 1)->update(['status' => 'draft']);
        $this->get('/lab')->assertOk()->assertDontSee($article->title_en);
        $this->get('/search?q=database')->assertOk()->assertDontSee($article->title_en);
    }

    public function test_private_project_is_excluded_even_with_evidence_row(): void
    {
        $project = Project::create([
            'slug' => 'secret-infrastructure',
            'title' => 'Secret infrastructure project',
            'summary_en' => 'Confidential project summary',
            'summary_ar' => 'ملخص خاص',
            'role_en' => 'Engineer',
            'status' => 'completed',
            'visibility' => 'private',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://example.org/public-link',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        $this->get('/search?q=Secret')->assertOk()
            ->assertSee('No verified public matches.')
            ->assertDontSee('Secret infrastructure project');
    }

    public function test_search_filters_are_bounded_and_html_escaped(): void
    {
        $this->get('/search?q='.urlencode('<img src=x onerror=alert(1)>'))
            ->assertOk()->assertDontSee('<img src=x onerror=', false);
        $this->get('/search?q='.str_repeat('x', 200))
            ->assertOk()->assertSee('No verified public matches.');
    }

    public function test_site_search_finds_verified_article_body_but_not_newer_private_revisions(): void
    {
        $article = $this->publishedArticle();
        // "operation" is in the published Markdown, not in either title.
        $this->get('/search?q=operation')->assertOk()
            ->assertSee('Verified database runbook');
        $this->get('/search?q=operation&lang=ar')->assertOk()
            ->assertSee('دليل قواعد البيانات الموثق');

        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => 'PRIVATE-UNPUBLISHED-SEARCH-SENTINEL',
            'status' => 'draft',
            'citations_json' => [['source_url' => 'https://example.org/unverified', 'verified' => false]],
            'validation_json' => ['citations_passed' => false, 'technical_passed' => false],
        ]);
        $this->get('/search?q=PRIVATE-UNPUBLISHED-SEARCH-SENTINEL')
            ->assertOk()->assertDontSee('Verified database runbook');
        $this->get('/search?q=operation')->assertOk()->assertSee('Verified database runbook');

        $article->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);
        $this->get('/search?q=operation')->assertOk()
            ->assertDontSee('Verified database runbook');

        // SQL LIKE metacharacters stay escaped instead of returning every
        // article or exposing a nonmatching verified article by accident.
        $this->get('/search?q='.urlencode('%_'))->assertOk()
            ->assertDontSee('Verified database runbook');
    }

}

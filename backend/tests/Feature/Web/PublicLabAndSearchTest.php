<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLabAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, bool $published): Article
    {
        $article = Article::create([
            'slug' => $slug, 'title_en' => 'Database Reliability Guide',
            'title_ar' => 'دليل اعتمادية قواعد البيانات',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'A source-reviewed guide',
            'status' => 'validated',
            'citations_json' => [['source_url' => 'https://example.org/source', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        if ($published) {
            $article->publishVerified();
        }
        return $article;
    }

    public function test_bilingual_empty_states_and_navigation(): void
    {
        $this->get('/lab')->assertOk()->assertSee('Technology lab')
            ->assertSee('No reviewed guides or live tool demos');
        $this->get('/lab?lang=ar')->assertOk()->assertSee('dir="rtl"', false)
            ->assertSee('href="'.route('public.lab', ['lang' => 'en']).'"', false);
        $this->get('/search')->assertOk()->assertSee('Enter a query');
        $this->get('/search?lang=ar')->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_search_and_lab_see_only_pinned_published_articles(): void
    {
        $public = $this->article('verified-database-guide', true);
        $this->article('private-draft', false);
        $this->get('/lab')->assertSee('Database Reliability Guide')
            ->assertDontSee('private-draft');
        $this->get('/search?q=Reliability')->assertOk()
            ->assertSee('Database Reliability Guide')
            ->assertSee('href="'.route('public.article', ['slug' => $public->slug, 'lang' => 'en']).'"', false);
        $public->revisions()->where('revision_no', 1)->update(['status' => 'draft']);
        $this->get('/lab')->assertDontSee('Database Reliability Guide');
        $this->get('/search?q=Reliability')->assertSee('No verified public matches.');
    }

    public function test_private_projects_remain_hidden_from_search(): void
    {
        $private = Project::create([
            'slug' => 'restricted-project',
            'title' => 'Confidential Internal Project',
            'summary_en' => 'Private work',
            'summary_ar' => 'تفاصيل خاصة',
            'role_en' => 'Engineer',
            'status' => 'completed',
            'visibility' => 'private',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $private->id,
            'evidence_type' => 'public_document',
            'url' => 'https://example.org/source',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
        $this->get('/search?q=Confidential')->assertOk()
            ->assertSee('No verified public matches.')
            ->assertDontSee('Confidential Internal Project');
    }
}

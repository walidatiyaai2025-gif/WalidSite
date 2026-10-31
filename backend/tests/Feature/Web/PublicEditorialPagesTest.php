<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Category;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicEditorialPagesTest extends TestCase
{
    use RefreshDatabase;

    private function guide(string $slug = 'verified-guide'): Article
    {
        $article = Article::create([
            'slug' => $slug, 'title_en' => 'Verified Query Guide',
            'title_ar' => 'دليل الاستعلامات الموثق', 'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1, 'status' => 'validated',
            'body_md' => "## Guide notes\n\nDocumented **guidance** [unsafe link](javascript:alert(2)).\n\n<script>alert(1)</script>",
            'citations_json' => [
                ['source_url'=>'https://example.org/source','verified'=>true],
                ['source_url'=>'https://localhost/private','verified'=>true],
            ],
            'validation_json' => ['citations_passed'=>true, 'technical_passed'=>true],
        ]);
        return $article;
    }

    public function test_empty_pages_and_arabic_layout_are_real(): void
    {
        foreach (['/blog','/lab','/search'] as $url) {
            $this->get($url)->assertOk()->assertSee('dir="ltr"', false);
            $this->get($url.'?lang=ar')->assertOk()->assertSee('dir="rtl"', false);
        }
        $this->get('/blog')->assertSee('No verified public articles');
        $this->get('/search')->assertSee('Enter a query');
    }

    public function test_only_pinned_verified_articles_can_be_displayed(): void
    {
        $published = $this->guide();
        $draft = $this->guide('hidden-draft');
        $published->publishVerified();
        $this->get('/blog')->assertOk()->assertSee('Verified Query Guide')->assertDontSee('hidden-draft');
        $this->get('/blog/hidden-draft')->assertNotFound();
        $this->get('/blog/verified-guide?lang=ar')->assertOk()
            ->assertSee('دليل الاستعلامات الموثق')
            ->assertSee('https://example.org/source')
            ->assertSee('<h2>Guide notes</h2>', false)
            ->assertSee('<strong>guidance</strong>', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('href="javascript:alert(2)"', false)
            ->assertDontSee('javascript:alert(2)')
            ->assertDontSee('https://localhost/private')
            ->assertDontSee('<script>alert(1)</script>', false);
        $published->revisions()->where('revision_no', 1)->update(['status'=>'draft']);
        $this->get('/blog/verified-guide')->assertNotFound();
        $this->get('/blog')->assertDontSee('Verified Query Guide');
        $this->assertSame('draft', $draft->status);
    }

    public function test_category_filter_and_search_are_actual_queries(): void
    {
        $guide = $this->guide();
        $guide->publishVerified();
        $category = Category::create(['slug'=>'database', 'name_en'=>'Database','name_ar'=>'قواعد البيانات']);
        $guide->categories()->attach($category->id);
        $this->get('/blog?q=Query&category=database')->assertOk()->assertSee('Verified Query Guide');
        $this->get('/blog?q=not-found')->assertOk()->assertDontSee('Verified Query Guide');
        $this->get('/search?q=Query')->assertOk()->assertSee('Verified Query Guide');
        $this->get('/lab')->assertOk()->assertSee('Verified Query Guide');
    }

    public function test_search_does_not_expose_private_projects(): void
    {
        $p = Project::create([
            'slug'=>'private-case','title'=>'Private Sensitive Project',
            'summary_en'=>'Private details','summary_ar'=>'تفاصيل خاصة',
            'role_en'=>'Engineer','status'=>'completed','visibility'=>'private',
            'published_at'=>now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id'=>$p->id,'evidence_type'=>'document','url'=>'https://example.org/proof',
            'visibility'=>'public','verified_at'=>now()->subDay(),
        ]);
        $this->get('/search?q=Private')->assertOk()->assertSee('No verified public matches.')
            ->assertDontSee('Private Sensitive Project');
    }
}

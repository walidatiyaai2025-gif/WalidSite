<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifiedBlogPagesTest extends TestCase
{
    use RefreshDatabase;

    private function article(bool $publish = true): Article
    {
        $article = Article::create([
            'slug' => 'verified-database-guide',
            'title_en' => 'Verified database guide',
            'title_ar' => 'دليل قواعد البيانات الموثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => "# SQL source\n\nThis procedure cites evidence.\n\n    SELECT 1;\n\n<script>alert('unsafe')</script>",
            'status' => 'validated',
            'citations_json' => [['source_url' => 'https://example.org/verified', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        if ($publish) {
            $article->publishVerified();
        }
        return $article;
    }

    public function test_empty_blog_is_visible_with_honest_arabic_and_english_states(): void
    {
        $this->get('/blog')->assertOk()
            ->assertSee('Technical blog')
            ->assertSee('No source-verified published articles')
            ->assertSee('role="search"', false)
            ->assertSee('href="#main-content"', false);
        $this->get('/blog?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('المدونة التقنية')
            ->assertSee('لا توجد مقالات منشورة وموثقة');
    }

    public function test_drafts_are_hidden_from_listing_and_details(): void
    {
        $article = $this->article(false);
        $this->get('/blog')->assertOk()->assertDontSee($article->title_en);
        $this->get('/blog/'.$article->slug)->assertNotFound();
    }

    public function test_approved_article_has_real_detail_sources_filters_and_safe_markdown(): void
    {
        $article = $this->article();
        $category = Category::create(['slug' => 'databases', 'name_en' => 'Databases', 'name_ar' => 'قواعد البيانات']);
        $article->categories()->attach($category->id);

        $this->get('/blog?category=databases&lang=ar')->assertOk()
            ->assertSee('دليل قواعد البيانات الموثق')
            ->assertSee('value="databases" selected', false)
            ->assertSee('href="'.route('public.article', ['slug'=>$article->slug, 'lang'=>'ar']).'"', false);
        $this->get('/blog?q=nonexistent')->assertOk()->assertDontSee('Verified database guide');
        $this->get('/blog?q=database')->assertOk()->assertSee('Verified database guide');

        $detail = $this->get('/blog/'.$article->slug.'?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('دليل قواعد البيانات الموثق')
            ->assertSee('SELECT 1;')
            ->assertSee('https://example.org/verified')
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('نسخ الكود')
            ->assertSee('حفظ المقال للحسابات سيُفعّل');
        $detail->assertDontSee('<script>alert(', false);
        $detail->assertDontSee('private/original');
    }

    public function test_revoked_citation_hides_previously_published_article(): void
    {
        $article = $this->article();
        $article->revisions()->firstOrFail()->update(['validation_json' => ['citations_passed'=>false, 'technical_passed'=>true]]);
        $this->get('/blog')->assertOk()->assertDontSee('Verified database guide');
        $this->get('/blog/'.$article->slug)->assertNotFound();
    }

    public function test_revoked_image_rights_cannot_leak_storage_key(): void
    {
        $article = $this->article();
        $asset = Asset::create([
            'asset_key' => 'private-article-image', 'mime_type' => 'image/png',
            'storage_key' => 'private/hidden-image.png',
            'sha256' => str_repeat('a', 64), 'origin' => 'owner-supplied',
            'rights_status' => 'cleared', 'publishable' => true,
        ]);
        $article->update(['hero_asset_id'=>$asset->id]);
        $this->get('/blog/'.$article->slug)->assertOk()->assertDontSee('private/hidden-image.png');
        $asset->update(['rights_status'=>'revoked', 'publishable'=>false]);
        $this->get('/blog/'.$article->slug)->assertNotFound();
        $this->get('/blog')->assertDontSee('Verified database guide');
    }

    public function test_invalid_filters_return_validation_errors_without_disclosing_drafts(): void
    {
        $this->getJson('/blog?category=../../hidden')->assertUnprocessable();
        $this->getJson('/blog?lang=invalid')->assertUnprocessable();
    }
}

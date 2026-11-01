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

    public function test_blog_search_indexes_only_citation_verified_pinned_body_not_newer_drafts(): void
    {
        $article = $this->article();
        // This phrase exists ONLY in a reviewed article body, never its title.
        $this->get('/blog?q=cites')->assertOk()
            ->assertSee('Verified database guide');
        $this->get('/blog?q=cites&lang=ar')->assertOk()
            ->assertSee('دليل قواعد البيانات الموثق');

        // A subsequently added draft may be stored without replacing the
        // published revision. Its unique phrase must not enter public search.
        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => 'PRIVATE-SUBSEQUENT-DRAFT-ONLY-FLAG',
            'status' => 'draft',
            'citations_json' => [['source_url' => 'https://example.org/unreviewed', 'verified' => false]],
            'validation_json' => ['citations_passed' => false, 'technical_passed' => false],
        ]);
        $this->get('/blog?q=PRIVATE-SUBSEQUENT-DRAFT-ONLY-FLAG')
            ->assertOk()->assertDontSee($article->title_en);
        $this->get('/blog?q=cites')->assertOk()
            ->assertSee($article->title_en);

        // Publication evidence revocation must remove body matches too.
        $article->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);
        $this->get('/blog?q=cites')->assertOk()
            ->assertDontSee($article->title_en);
    }

    /** Construct a genuinely published article, then optionally revoke its proof. */
    private function publishForPagination(string $slug, bool $revoke = false): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Verified guide '.$slug,
            'title_ar' => 'دليل موثق '.$slug,
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Verified technical guidance with sources.',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/verified-guide',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();
        if ($revoke) {
            // Persist a withdrawal without changing the cheap SQL scope:
            // only publicRevision() recognizes this previously valid row.
            \Illuminate\Support\Facades\DB::table('article_revisions')
                ->where('id', $revision->id)
                ->update(['validation_json' => json_encode([
                    'citations_passed' => false, 'technical_passed' => true,
                ], JSON_THROW_ON_ERROR)]);
        }
        return $article;
    }

    public function test_blog_pagination_counts_only_live_verified_articles_and_preserves_filters(): void
    {
        $category = Category::create([
            'slug' => 'database-guides',
            'name_en' => 'Database guides',
            'name_ar' => 'أدلة قواعد البيانات',
        ]);
        for ($i = 1; $i <= 11; $i++) {
            $revoked = $this->publishForPagination('revoked-blog-'.$i, true);
            $revoked->categories()->attach($category->id);
            $revoked->forceFill(['published_at' => now()->subMinute()])->save();
        }
        for ($i = 1; $i <= 10; $i++) {
            $safe = $this->publishForPagination('verified-blog-'.$i);
            $safe->categories()->attach($category->id);
            $safe->forceFill(['published_at' => now()->subDays(3)->subMinutes($i)])->save();
        }

        $base = '/blog?category=database-guides&q=Verified&lang=en';
        $first = $this->get($base.'&page=1')->assertOk()
            ->assertSee('Page 1 / 2')
            ->assertSee('verified-blog-1')
            ->assertSee('verified-blog-9')
            ->assertDontSee('verified-blog-10')
            ->assertDontSee('revoked-blog-')
            ->assertSee('page=2')
            ->assertSee('database-guides')
            ->assertSee('q=Verified');
        $this->get('/blog?category=database-guides&q=Verified&lang=ar&page=2')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الصفحة 2 / 2')
            ->assertSee('verified-blog-10')
            ->assertDontSee('verified-blog-1"')
            ->assertDontSee('revoked-blog-')
            ->assertDontSee('page=3');
        $this->get($base.'&page=3')->assertOk()
            ->assertSee('No source-verified published articles')
            ->assertDontSee('revoked-blog-')
            ->assertDontSee('verified-blog-10');
    }

    public function test_blog_revocation_removes_navigation_counts_on_next_request(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->publishForPagination('revoke-after-list-'.$i);
        }
        $this->get('/blog')->assertOk()->assertSee('Page 1 / 2');
        for ($i = 1; $i <= 10; $i++) {
            \Illuminate\Support\Facades\DB::table('article_revisions')
                ->where('article_id', Article::where('slug', 'revoke-after-list-'.$i)->value('id'))
                ->update(['validation_json' => json_encode([
                    'citations_passed' => false, 'technical_passed' => true,
                ], JSON_THROW_ON_ERROR)]);
        }
        $this->get('/blog')->assertOk()
            ->assertSee('No source-verified published articles')
            ->assertDontSee('Page 1 / 2')
            ->assertDontSee('revoke-after-list-');
    }


    public function test_blog_category_options_require_live_verified_articles_in_both_languages(): void
    {
        $validCategory = Category::create([
            'slug' => 'verified-category',
            'name_en' => 'Verified category choice',
            'name_ar' => 'تصنيف موثق ومتاح',
        ]);
        $withdrawnCategory = Category::create([
            'slug' => 'withdrawn-category',
            'name_en' => 'Withdrawn-only category',
            'name_ar' => 'تصنيف محتواه مسحوب',
        ]);
        $draftCategory = Category::create([
            'slug' => 'draft-only-category',
            'name_en' => 'Draft-only category',
            'name_ar' => 'تصنيف مسودة غير منشورة',
        ]);

        $valid = $this->publishForPagination('valid-category-article');
        $valid->categories()->attach($validCategory->id);
        $revoked = $this->publishForPagination('withdrawn-category-article', true);
        $revoked->categories()->attach($withdrawnCategory->id);
        // A category attached exclusively to a never-published draft is hidden.
        $draft = Article::create([
            'slug' => 'draft-category-article',
            'title_en' => 'Draft article',
            'title_ar' => 'مسودة',
            'canonical_lang' => 'en',
        ]);
        $draft->categories()->attach($draftCategory->id);

        foreach (['en', 'ar'] as $lang) {
            $page = $this->get('/blog?lang='.$lang)->assertOk()
                ->assertSee('<option value="verified-category"', false)
                ->assertDontSee('<option value="withdrawn-category"', false)
                ->assertDontSee('<option value="draft-only-category"', false)
                ->assertDontSee('Withdrawn-only category')
                ->assertDontSee('تصنيف محتواه مسحوب')
                ->assertDontSee('Draft-only category');
            $page->assertSee($lang === 'ar'
                ? 'تصنيف موثق ومتاح' : 'Verified category choice');
        }

        // An independent approved article must make a category available.
        $recovered = $this->publishForPagination('newly-approved-category-article');
        $recovered->categories()->attach($withdrawnCategory->id);
        $this->get('/blog?lang=en')->assertOk()
            ->assertSee('<option value="withdrawn-category"', false)
            ->assertSee('Withdrawn-only category');
        $this->get('/blog?lang=ar')->assertOk()
            ->assertSee('تصنيف محتواه مسحوب');

        // Removing the last remaining live proof immediately hides its choice
        // without editing the stored taxonomy or disclosing revoked content.
        $recovered->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->get('/blog?lang=en')->assertOk()
            ->assertDontSee('<option value="withdrawn-category"', false)
            ->assertSee('<option value="verified-category"', false);
        $this->get('/blog?category=withdrawn-category&lang=en')->assertOk()
            ->assertSee('No source-verified published articles')
            ->assertDontSee('Withdrawn-only category');
    }

}

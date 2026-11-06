<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-07 user journey: honest reference viewport, verified reading,
 * locale-safe filters and reachable empty-state recovery.
 *
 * These HTTP assertions are functional checks, NOT pixel-match evidence.
 */
final class Web07BlogScreenParityTest extends TestCase
{
    use RefreshDatabase;

    private function publishedGuide(string $slug = 'web07-reviewed-guide'): Article
    {
        $article = Article::query()->create([
            'slug' => $slug,
            'title_en' => 'WEB07 Reviewed live guide',
            'title_ar' => 'دليل موثق ومتاح',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Source-reviewed instructions for safe browsing.',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/web07-verified',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();

        return $article;
    }

    public function test_bilingual_blog_heading_navigation_uses_one_reference_h1_and_nested_content_headings(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            // The reference viewport contains the sole page H1. The live
            // functional section and its cards are subordinate, not H1 peers.
            $empty = $this->get(route('public.blog', ['lang' => $locale]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('<h2 id="blog-title">', false)
                ->assertSee('<h3 id="blog-saved-heading">', false)
                ->assertSee('aria-labelledby="blog-title"', false)
                ->assertSee('aria-labelledby="blog-saved-heading"', false);
            preg_match_all('/<h1(?:\\s|>)/i', $empty->getContent(), $h1);
            $this->assertCount(1, $h1[0], 'Exactly one H1 for '.$locale);
            $this->assertStringNotContainsString('<h1 id="blog-title">', $empty->getContent());
        }

        $published = $this->publishedGuide('web07-heading-navigation');
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get(route('public.blog', ['lang' => $locale]))->assertOk()
                ->assertSee('<h2 id="blog-title">', false)
                ->assertSee('<h3>', false)
                ->assertSee('<h3 id="blog-saved-heading">', false)
                ->assertSee('web07-heading-navigation');
            $html = $page->getContent();
            preg_match_all('/<h1(?:\\s|>)/i', $html, $h1);
            $this->assertCount(1, $h1[0]);
            $this->assertSame(1, substr_count($html, '<h3>'.$published->title_en.'</h3>') +
                substr_count($html, '<h3>'.$published->title_ar.'</h3>'));
            $this->assertStringNotContainsString('<h1 id="blog-title">', $html);
        }
    }

    public function test_filtered_second_page_first_viewport_article_returns_to_same_blog_results(): void
    {
        $category = Category::query()->create([
            'slug' => 'verified-web07-guides',
            'name_en' => 'Verified WEB07 guides',
            'name_ar' => 'أدلة موثقة للمدونة',
        ]);
        for ($i = 1; $i <= 11; $i++) {
            $article = $this->publishedGuide('web07-paged-guide-'.$i);
            $article->categories()->attach($category->id);
        }
        $draft = Article::query()->create([
            'slug' => 'web07-private-paged-draft',
            'title_en' => 'WEB07 PRIVATE PAGED DRAFT',
            'title_ar' => 'مسودة خاصة غير منشورة',
            'canonical_lang' => 'en',
        ]);
        $draft->categories()->attach($category->id);

        foreach (['en', 'ar'] as $locale) {
            $filters = [
                'lang' => $locale,
                'q' => 'WEB07',
                'category' => $category->slug,
                'page' => 2,
            ];
            $listing = $this->get(route('public.blog', $filters))->assertOk()
                ->assertDontSee('WEB07 PRIVATE PAGED DRAFT')
                ->assertDontSee('web07-private-paged-draft');

            // Page two has two verified cards in BOTH the reference viewport
            // and the lower functional grid. The records are UUID keyed;
            // derive the actual page-two slugs instead of assuming SQL UUID
            // ordering corresponds to their insertion sequence.
            preg_match_all('/class="t012-card-link" href="([^"]+)"/',
                $listing->getContent(), $viewportLinks);
            self::assertCount(2, $viewportLinks[1]);
            $slugs = [];
            foreach ($viewportLinks[1] as $encodedUrl) {
                $href = html_entity_decode($encodedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $slug = basename((string) parse_url($href, PHP_URL_PATH));
                self::assertMatchesRegularExpression('/\Aweb07-paged-guide-\d+\z/D', $slug);
                $slugs[] = $slug;
                $articleUrl = route('public.article', [
                    'slug' => $slug,
                    'lang' => $locale,
                    'source' => 'blog',
                    'q' => 'WEB07',
                    'category' => $category->slug,
                    'page' => 2,
                ]);
                self::assertSame($articleUrl, $href);
                self::assertSame(2, substr_count(
                    $listing->getContent(), 'href="'.e($articleUrl).'"',
                ), 'Both article links must preserve validated blog context');

                $detail = $this->get($articleUrl)->assertOk()
                    ->assertSee(
                        'href="'.e(route('public.blog', $filters)).'"',
                        false,
                    );
                $detail->assertDontSee('WEB07 PRIVATE PAGED DRAFT');
                $this->get(route('public.blog', $filters))->assertOk();
            }
            self::assertCount(2, array_unique($slugs));
        }

        // No filter => no source, q, category or page on otherwise public
        // article links. Canonical SEO URLs remain clean and direct.
        $plain = $this->get(route('public.blog', ['lang' => 'en']))->assertOk();
        preg_match('/class="t012-card-link" href="([^"]+)"/',
            $plain->getContent(), $plainLink);
        self::assertNotEmpty($plainLink[1] ?? null);
        $plainUrl = html_entity_decode($plainLink[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainQuery = [];
        parse_str((string) parse_url($plainUrl, PHP_URL_QUERY), $plainQuery);
        self::assertSame(['lang' => 'en'], $plainQuery);
        self::assertStringNotContainsString('source=', $plainUrl);
        self::assertStringNotContainsString('category=', $plainUrl);
        self::assertStringNotContainsString('page=', $plainUrl);
        self::assertStringNotContainsString('q=', $plainUrl);
    }

    public function test_empty_reference_viewport_is_explained_and_recoverable_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get(route('public.blog', ['lang' => $locale]))->assertOk()
                ->assertSee('class="t012-reference-stage t012-screen-blog"', false)
                ->assertSee('class="t012-blog-empty-card" role="status" aria-live="polite"', false)
                ->assertSee('href="'.e(route('public.lab', ['lang' => $locale])).'"', false)
                ->assertSee('class="t012-reference-cards"', false)
                ->assertDontSee('SQL AlwaysOn');

            if ($locale === 'ar') {
                $page->assertSee('<html lang="ar" dir="rtl">', false)
                    ->assertSee('لا توجد مقالات منشورة وموثقة حاليًا')
                    ->assertSee('تصفح المختبر التقني');
            } else {
                $page->assertSee('<html lang="en" dir="ltr">', false)
                    ->assertSee('No source-verified published articles yet')
                    ->assertSee('Browse technical lab');
            }
        }
    }

    public function test_reviewed_articles_replace_empty_stage_while_drafts_remain_private(): void
    {
        $reviewed = $this->publishedGuide();

        $draft = Article::query()->create([
            'slug' => 'web07-private-draft',
            'title_en' => 'WEB07 PRIVATE DRAFT',
            'title_ar' => 'مسودة غير منشورة',
            'canonical_lang' => 'en',
        ]);

        foreach (['en', 'ar'] as $locale) {
            $page = $this->get(route('public.blog', ['lang' => $locale]))->assertOk()
                ->assertDontSee('class="t012-blog-empty-card"', false)
                ->assertDontSee('WEB07 PRIVATE DRAFT')
                ->assertDontSee('مسودة غير منشورة')
                ->assertSee('href="'.e(route('public.article', [
                    'slug' => $reviewed->slug,
                    'lang' => $locale,
                ])).'"', false);
            $page->assertSee($locale === 'ar' ? 'دليل موثق ومتاح' : 'WEB07 Reviewed live guide');
            $this->get(route('public.article', ['slug' => $draft->slug, 'lang' => $locale]))
                ->assertNotFound();
        }
    }

    public function test_empty_feed_and_filtered_no_match_have_distinct_localized_recovery(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $empty = $this->get(route('public.blog', ['lang' => $locale]))->assertOk()
                ->assertSee('class="blog-empty" role="status" aria-live="polite"', false)
                ->assertSee('href="'.e(route('public.lab', ['lang' => $locale])).'"', false)
                ->assertDontSee('Return to the first results page')
                ->assertDontSee('No source-verified published articles match the current filters.');

            $empty->assertSee($locale === 'ar'
                ? 'لا توجد مقالات منشورة وموثقة حتى الآن.'
                : 'No source-reviewed articles have been published yet.');

            $filtered = $this->get(route('public.blog', [
                'lang' => $locale, 'q' => 'not-a-published-article',
            ]))->assertOk()
                ->assertSee('href="'.e(route('public.blog', ['lang' => $locale])).'"', false)
                ->assertDontSee('No source-reviewed articles have been published yet.');

            $filtered->assertSee($locale === 'ar'
                ? 'إزالة التصفيات وعرض كل المقالات'
                : 'Clear filters and view all articles');
        }
    }

    public function test_out_of_range_page_preserves_valid_search_and_returns_to_real_first_page(): void
    {
        $reviewed = $this->publishedGuide();
        Article::query()->create([
            'slug' => 'unpublished-web07-guide',
            'title_en' => 'WEB07 unpublished private material',
            'title_ar' => 'مواد خاصة غير منشورة',
            'canonical_lang' => 'en',
        ]);

        foreach (['en', 'ar'] as $locale) {
            $search = ['lang' => $locale, 'q' => 'WEB07'];
            $page = $this->get(route('public.blog', [...$search, 'page' => 9]))
                ->assertOk()
                ->assertSee('class="blog-empty" role="status" aria-live="polite"', false)
                ->assertSee('href="'.e(route('public.blog', $search)).'"', false)
                ->assertDontSee('WEB07 unpublished private material')
                ->assertDontSee('Page 9 / 1')
                ->assertDontSee('الصفحة 9 / 1');

            $page->assertSee($locale === 'ar'
                ? 'العودة إلى أول صفحة من النتائج'
                : 'Return to the first results page');

            $this->get(route('public.blog', $search))
                ->assertOk()
                ->assertSee($locale === 'ar' ? $reviewed->title_ar : $reviewed->title_en)
                ->assertDontSee('WEB07 unpublished private material');

            // Reject untrusted pagination rather than reflecting it in a link.
            $this->get('/blog?lang='.$locale.'&page=10001')->assertStatus(302);
        }
    }

    public function test_language_switch_preserves_valid_blog_query_category_and_page(): void
    {
        foreach (['en' => 'ar', 'ar' => 'en'] as $locale => $other) {
            $args = [
                'lang' => $locale,
                'q' => 'SQL & AI',
                'category' => 'database-guides',
                'page' => 2,
            ];
            $page = $this->get(route('public.blog', $args))->assertOk()
                ->assertSee('href="'.e(route('public.blog', [
                    'lang' => $other, 'category' => 'database-guides', 'page' => 2,
                ])).'"', false)
                ->assertSee('data-web07-locale-switch', false)
                ->assertSee("url.searchParams.set('q', term)", false)
                ->assertSee('value="SQL &amp; AI"', false)
                ->assertSee('name="category"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('class="t012-blog-empty-card" role="status"', false);
            $page->assertDontSee('SQL AlwaysOn');
        }
        // Validation, not the language link, rejects unexpected array filters.
        $this->get('/blog?lang=en&q[]=malformed')->assertStatus(302);
    }

    public function test_unfiltered_language_switch_has_no_stale_query_or_pagination(): void
    {
        $page = $this->get('/blog?lang=en')->assertOk();
        $page->assertSee('href="'.e(route('public.blog', ['lang' => 'ar'])).'"', false)
            ->assertDontSee('href="'.e(route('public.blog', [
                'lang' => 'ar', 'page' => 2,
            ])).'"', false);
    }
}

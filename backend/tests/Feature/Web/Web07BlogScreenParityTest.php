<?php

namespace Tests\Feature\Web;

use App\Models\Article;
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
        $reviewed = Article::query()->create([
            'slug' => 'web07-reviewed-guide',
            'title_en' => 'WEB07 Reviewed live guide',
            'title_ar' => 'دليل موثق ومتاح',
            'canonical_lang' => 'en',
        ]);
        $reviewed->revisions()->create([
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
        $reviewed->publishVerified();

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

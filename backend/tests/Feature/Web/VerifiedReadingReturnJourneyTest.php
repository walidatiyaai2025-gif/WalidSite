<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VerifiedReadingReturnJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, string $keyword = 'Recovery & restore', bool $published = true): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => $keyword.' '.$slug,
            'title_ar' => 'دليل موثق '.$keyword.' '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Verified instructions '.$slug,
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/recovery',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        if ($published) {
            $article->publishVerified();
        }

        return $article;
    }

    public function test_bilingual_filtered_blog_page_to_article_and_related_reading_returns_to_exact_results(): void
    {
        Http::fake();
        $category = Category::create([
            'slug' => 'deep-guides',
            'name_en' => 'Deep guides',
            'name_ar' => 'أدلة متعمقة',
        ]);
        $articles = [];
        for ($i = 1; $i <= 11; $i++) {
            $article = $this->article(sprintf('reading-guide-%02d', $i));
            $article->categories()->attach($category->id);
            $article->forceFill(['published_at' => now()->subMinutes($i)])->save();
            $articles[] = $article;
        }
        $draft = $this->article('secret-private-draft', 'Recovery & restore', false);
        $draft->categories()->attach($category->id);
        $revoked = $this->article('revoked-unapproved-guide');
        $revoked->categories()->attach($category->id);
        $revoked->revisions()->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);

        foreach (['en', 'ar'] as $lang) {
            $filters = [
                'lang' => $lang, 'q' => 'Recovery & restore',
                'category' => 'deep-guides', 'page' => 2,
            ];
            $indexUrl = route('public.blog', $filters);
            $context = ['source' => 'blog', 'q' => $filters['q'],
                'category' => 'deep-guides', 'page' => 2];
            $articleUrl = route('public.article', array_merge([
                'slug' => $articles[10]->slug, 'lang' => $lang,
            ], $context));
            $blog = $this->get($indexUrl)->assertOk()
                ->assertSee('href="'.e($articleUrl).'"', false)
                ->assertSee('reading-guide-11')
                ->assertSee('reading-guide-10')
                ->assertDontSee('reading-guide-09')
                ->assertDontSee('secret-private-draft')
                ->assertDontSee('revoked-unapproved-guide')
                ->assertSee('href="'.e(route('public.blog', [
                    'lang' => $lang,
                ])).'"', false)
                ->assertSee($lang === 'ar'
                    ? 'إجمالي المقالات الموثقة المطابقة: 11'
                    : 'Total verified matching articles: 11')
                ->assertSee($lang === 'ar' ? 'إزالة جميع التصفيات' : 'Clear all filters');

            // The language control must not reset the selected page, search
            // phrase or verified category while switching AR/EN.
            $otherLocale = $lang === 'ar' ? 'en' : 'ar';
            $blog->assertSee('href="'.e(route('public.blog',
                array_merge($filters, ['lang' => $otherLocale]))).'"', false);
            $detail = $this->get($articleUrl)->assertOk()
                ->assertSee('href="'.e($indexUrl).'"', false)
                ->assertSee('href="'.e(route('public.article', array_merge([
                    'slug' => $articles[10]->slug, 'lang' => $otherLocale,
                ], $context))).'"', false)
                ->assertSee($lang === 'ar'
                    ? 'العودة إلى نتائج المدونة' : 'Back to technical blog results')
                ->assertDontSee('secret-private-draft')
                ->assertDontSee('revoked-unapproved-guide');
            // Same-category verified related articles preserve the original
            // search context, but never surface drafts or revoked evidence.
            $detail->assertSee('href="'.e(route('public.article', array_merge([
                'slug' => $articles[0]->slug, 'lang' => $lang,
            ], $context))).'"', false);
            if ($lang === 'ar') {
                $blog->assertSee('dir="rtl"', false);
            } else {
                $blog->assertSee('dir="ltr"', false);
            }
        }

        // A direct article visit has a safe default back destination and
        // does not silently acquire a previous visitor's query.
        $this->get('/blog/'.$articles[10]->slug.'?lang=en')->assertOk()
            ->assertSee('href="'.e(route('public.blog', ['lang' => 'en'])).'"', false);
        // Revocation and publication still control the public detail page.
        $this->get('/blog/'.$draft->slug.'?source=blog')->assertNotFound();
        $this->get('/blog/'.$revoked->slug.'?source=blog')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_search_type_article_reading_and_language_switch_keep_safe_search_context(): void
    {
        Http::fake();
        $article = $this->article('search-return-guide');
        foreach (['en', 'ar'] as $lang) {
            foreach (['all', 'articles'] as $type) {
                $q = 'Recovery & restore';
                $index = route('public.search', [
                    'lang' => $lang, 'q' => $q, 'type' => $type,
                ]);
                $link = route('public.article', [
                    'slug' => $article->slug, 'lang' => $lang,
                    'source' => 'search', 'q' => $q, 'type' => $type,
                ]);
                $this->get($index)->assertOk()
                    ->assertSee('href="'.e($link).'"', false)
                    ->assertSee($lang === 'ar' ? 'نتائج موثقة:' : 'Verified results:')
                    ->assertDontSee('not-for-publication');
                $other = $lang === 'ar' ? 'en' : 'ar';
                $this->get($link)->assertOk()
                    ->assertSee('href="'.e($index).'"', false)
                    ->assertSee($lang === 'ar' ? 'العودة إلى نتائج البحث' : 'Back to search results')
                    ->assertSee('href="'.e(route('public.article', [
                        'slug' => $article->slug, 'lang' => $other,
                        'source' => 'search', 'q' => $q, 'type' => $type,
                    ])).'"', false);
                $this->get($index)->assertSee('href="'.e(route('public.search', [
                    'lang' => $other, 'q' => $q, 'type' => $type,
                ])).'"', false);
            }
        }
        // Source-search is meaningful only with a valid bounded search
        // phrase; a forged type cannot produce a third-party destination.
        $this->get('/blog/search-return-guide?source=search&q[]=unsafe&type=projects')
            ->assertOk()
            ->assertSee('href="'.e(route('public.blog', ['lang' => 'en'])).'"', false)
            ->assertDontSee('unsafe');
        Http::assertNothingSent();
    }

    public function test_untrusted_article_return_queries_never_echo_or_redirect_off_site(): void
    {
        $article = $this->article('safe-return-guard');
        foreach ([
            '?source=https://evil.example/steal&return=https://evil.example/secret',
            '?source[]=blog&q[]=PRIVATE_ARRAY&category[]=private&page[]=2',
            '?source=blog&q='.str_repeat('x', 81).'&category=INVALID%2FPRIVATE&page=10001',
            '?source=search&q[]=PRIVATE_ARRAY&type=projects',
        ] as $params) {
            $response = $this->get('/blog/'.$article->slug.$params)->assertOk()
                ->assertSee('href="'.e(route('public.blog', ['lang' => 'en'])).'"', false)
                ->assertDontSee('evil.example')
                ->assertDontSee('PRIVATE_ARRAY')
                ->assertDontSee('INVALID/PRIVATE')
                ->assertDontSee('page=10001');
            $this->assertStringNotContainsString('evil.example',
                $response->headers->get('Location', ''));
        }

        $this->get('/blog/'.$article->slug.'?source=blog&category=deep-guides&page=2')
            ->assertOk()->assertSee('href="'.e(route('public.blog', [
                'lang' => 'en', 'category' => 'deep-guides', 'page' => 2,
            ])).'"', false);
    }

    public function test_reviewed_lab_library_locale_switch_keeps_page_when_reading_a_guide(): void
    {
        $id = (string) Str::uuid();
        $text = '# Still reviewed commands';
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => 'bilingual-library-page',
            'title' => 'Verified library guide',
            'body_md' => $text,
            'version' => 1,
            'validated_at' => now()->subMinute(),
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'reviewed_title' => 'Verified library guide',
            'body_md' => $text,
            'checksum' => hash('sha256', $text),
            'created_at' => now()->subMinutes(2),
        ]);

        $this->get('/lab/runbooks?lang=en&page=2')->assertOk()
            ->assertSee('href="'.e(route('public.lab.runbooks', [
                'lang' => 'ar', 'page' => 2,
            ])).'"', false);
        $this->get('/lab/runbooks/'.$id.'?lang=en&page=2')->assertOk()
            ->assertSee('href="'.e(route('public.lab.runbook', [
                'id' => $id, 'lang' => 'ar', 'page' => 2,
            ])).'"', false);
        $this->get('/lab/runbooks/'.$id.'?lang=ar&page=2')->assertOk()
            ->assertSee('href="'.e(route('public.lab.runbooks', [
                'lang' => 'ar', 'page' => 2,
            ])).'"', false);
    }
}

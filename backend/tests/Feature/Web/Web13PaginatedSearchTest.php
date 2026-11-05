<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Web13PaginatedSearchTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, bool $approved = true): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'PagerEvidence2026 '.$slug,
            'title_ar' => 'مقال موثق PagerEvidence2026 '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Evidence-backed public article',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/search-pagination',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => $approved,
                'technical_passed' => true,
            ],
        ]);
        if ($approved) {
            $article->publishVerified();
        }

        return $article;
    }

    private function project(string $slug, bool $evidence = true): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'PagerEvidence2026 '.$slug,
            'summary_en' => 'Verified work with approved public evidence.',
            'summary_ar' => 'عمل مثبت مع أدلة عامة معتمدة.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        if ($evidence) {
            ProjectEvidence::create([
                'project_id' => $project->id,
                'evidence_type' => 'document',
                'url' => 'https://docs.example.org/search-pagination',
                'visibility' => 'public',
                'verified_at' => now()->subDay(),
            ]);
        }
        return $project;
    }

    /**
     * The page offset must be applied AFTER both independent publication and
     * evidence gates, not to raw SQL records that may later be rejected.
     */
    public function test_page_two_contains_all_remaining_public_results_without_duplicates_or_unreviewed_data(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->article('pagination-article-'.$i);
            if ($i <= 12) {
                $this->project('pagination-project-'.$i);
            }
        }
        $this->article('pagination-draft', false);
        $revoked = $this->article('pagination-revoked');
        $revoked->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->project('pagination-unverified', false);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
            $base = ['lang' => $lang, 'q' => 'PagerEvidence2026', 'type' => 'all'];
            $first = $this->get(route('public.search', $base))
                ->assertOk()
                ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                ->assertSee('data-testid="verified-search-pagination"', false)
                ->assertSee('rel="next"', false)
                ->assertSee('href="'.e(route('public.search', $base + ['page' => 2])).'"', false)
                ->assertDontSee('pagination-draft')
                ->assertDontSee('pagination-revoked')
                ->assertDontSee('pagination-unverified');
            $second = $this->get(route('public.search', $base + ['page' => 2]))
                ->assertOk()
                ->assertSee('rel="prev"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee($lang === 'ar' ? 'صفحة 2 من 2' : 'Page 2 of 2')
                ->assertSee('href="'.e(route('public.search', $base + ['page' => 1])).'"', false)
                ->assertDontSee('rel="next"', false)
                ->assertDontSee('pagination-draft')
                ->assertDontSee('pagination-revoked')
                ->assertDontSee('pagination-unverified');

            foreach ([
                ['name' => 'articles', 'pattern' => '~/blog/(pagination-article-\d+)\?~', 'count' => 13],
                ['name' => 'projects', 'pattern' => '~/projects/(pagination-project-\d+)\?~', 'count' => 12],
            ] as $type) {
                preg_match_all($type['pattern'], $first->getContent(), $matchesFirst);
                preg_match_all($type['pattern'], $second->getContent(), $matchesSecond);
                $this->assertCount(10, $matchesFirst[1], $lang.' '.$type['name'].' page 1');
                $this->assertCount($type['count'] - 10, $matchesSecond[1], $lang.' '.$type['name'].' page 2');
                $this->assertCount($type['count'], array_unique([
                    ...$matchesFirst[1], ...$matchesSecond[1],
                ]), $lang.' '.$type['name'].' unique links across pages');
            }

            // Server-enforced type filters and bilingual history are retained.
            $projectsOnly = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'PagerEvidence2026', 'type' => 'projects', 'page' => 2,
            ]))->assertOk()
                ->assertSee('pagination-project-')
                ->assertDontSee('pagination-article-');
            $this->assertSame(2, substr_count($projectsOnly->getContent(), '/projects/pagination-project-'));
            $articlesOnly = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'PagerEvidence2026', 'type' => 'articles', 'page' => 2,
            ]))->assertOk()
                ->assertSee('pagination-article-')
                ->assertDontSee('pagination-project-');
            $this->assertSame(3, substr_count($articlesOnly->getContent(), '/blog/pagination-article-'));

            $this->get(route('public.search', $base + ['page' => 3]))->assertNotFound();
        }
    }


    public function test_search_page_two_article_open_back_and_locale_keep_exact_verified_page(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->article('pagination-return-'.$i);
        }

        foreach (['en' => 'ar', 'ar' => 'en'] as $lang => $otherLang) {
            $query = ['lang' => $lang, 'q' => 'PagerEvidence2026', 'type' => 'articles', 'page' => 2];
            $listing = $this->get(route('public.search', $query))->assertOk();
            $html = $listing->getContent();

            // Three first-viewport results and two lower results must all
            // carry the same validated search page to their article detail.
            preg_match_all('/href="([^"]*\\/blog\\/pagination-return-[^"]*)"/', $html, $links);
            $this->assertCount(5, $links[1], $lang.' five public article detail links');
            foreach ($links[1] as $link) {
                $this->assertStringContainsString('page=2', html_entity_decode($link, ENT_QUOTES), $lang.' lost page');
            }

            $listing->assertSee('href="'.e(route('public.search', [
                'lang' => $otherLang, 'q' => 'PagerEvidence2026',
                'type' => 'articles', 'page' => 2,
            ])).'"', false);

            $detailBase = [
                'slug' => 'pagination-return-11', 'lang' => $lang,
                'source' => 'search', 'q' => 'PagerEvidence2026', 'type' => 'articles',
            ];
            $detailUrl = route('public.article', $detailBase + ['page' => 2]);
            $listing->assertSee('href="'.e($detailUrl).'"', false);
            $this->get($detailUrl)->assertOk()
                ->assertSee('href="'.e(route('public.search', $query)).'"', false)
                ->assertSee($lang === 'ar' ? 'العودة إلى نتائج البحث' : 'Back to search results');

            // A fresh first-page article must not acquire a synthetic page=1.
            $this->get(route('public.article', $detailBase))->assertOk()
                ->assertSee('href="'.e(route('public.search', [
                    'lang' => $lang, 'q' => 'PagerEvidence2026', 'type' => 'articles',
                ])).'"', false);
            foreach (['page=10001', 'page=-1', 'page=2.5', 'page%5B%5D=2'] as $untrusted) {
                $this->get(route('public.article', $detailBase).'&'.$untrusted)->assertOk()
                    ->assertSee('href="'.e(route('public.search', [
                        'lang' => $lang, 'q' => 'PagerEvidence2026', 'type' => 'articles',
                    ])).'"', false);
            }
        }
    }

    public function test_empty_results_and_invalid_pages_cannot_invent_pagination_or_leak_data(): void
    {
        $this->article('pagination-unpublished', false);
        $this->project('pagination-private', false);

        $this->get('/search?lang=en&q=PagerEvidence2026')->assertOk()
            ->assertSee('No verified public matches.')
            ->assertDontSee('data-testid="verified-search-pagination"', false)
            ->assertDontSee('pagination-private')
            ->assertDontSee('pagination-unpublished');
        $this->get('/search?lang=ar')->assertOk()
            ->assertDontSee('data-testid="verified-search-pagination"', false);
        $this->get('/search?lang=en&page=2')->assertNotFound();
        $this->get('/search?lang=en&q=PagerEvidence2026&page=2')->assertNotFound();
        foreach (['page=0', 'page=10001', 'page=2.5', 'page%5B%5D=1'] as $bad) {
            $this->getJson('/search?lang=en&q=PagerEvidence2026&'.$bad)
                ->assertUnprocessable();
        }
    }
}

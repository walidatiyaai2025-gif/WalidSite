<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-13: production search stage shows only real approved links and keeps
 * every result discoverable exactly once across its preview and detail list.
 * Static discovery cards are navigation, never fictional search hits.
 */
final class Web13SearchStageJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function publishedArticle(string $slug, bool $publish = true): Article
    {
        $article = Article::create([
            'slug' => 'web13-article-'.$slug,
            'title_en' => 'screenneedle Article '.$slug,
            'title_ar' => 'مقال موثق screenneedle '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'screenneedle verified public source content',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/technical-evidence',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        if ($publish) {
            $article->publishVerified();
        }

        return $article;
    }

    private function publicProject(string $slug, bool $verified = true): Project
    {
        $project = Project::create([
            'slug' => 'web13-project-'.$slug,
            'title' => 'screenneedle project '.$slug,
            'summary_en' => 'Source-backed public work',
            'summary_ar' => 'عمل عام موثق',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        if ($verified) {
            ProjectEvidence::create([
                'project_id' => $project->id,
                'evidence_type' => 'document',
                'url' => 'https://docs.example.org/technical-evidence',
                'visibility' => 'public',
                'verified_at' => now()->subDay(),
            ]);
        }

        return $project;
    }

    public function test_zero_query_displays_three_real_discovery_routes_not_fabricated_hits_in_both_locales(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
            $page = $this->get(route('public.search', ['lang' => $lang]))->assertOk()
                ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                ->assertSee('id="t012-top-search"', false)
                ->assertSee('role="search"', false)
                ->assertSee('href="'.e(route('public.blog', ['lang' => $lang])).'"', false)
                ->assertSee('href="'.e(route('public.projects', ['lang' => $lang])).'"', false)
                ->assertSee('href="'.e(route('public.lab.runbooks', ['lang' => $lang])).'"', false)
                ->assertDontSee('SQL Server / HA')
                ->assertDontSee('class="t012-search-card"', false);
            $this->assertSame(3, substr_count($page->getContent(), 'class="t012-discovery-card"'));
        }
    }

    public function test_all_filter_shows_project_only_verified_hits_in_the_visible_stage(): void
    {
        $approved = $this->publicProject('project-only');
        $this->publicProject('no-proof', false);

        foreach (['en', 'ar'] as $lang) {
            $page = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'screenneedle', 'type' => 'all',
            ]))->assertOk()
                ->assertSee('screenneedle project project-only')
                ->assertDontSee('screenneedle project no-proof')
                ->assertSee($lang === 'ar' ? 'مشروع موثق' : 'Verified project')
                ->assertDontSee('class="t012-discovery-card"', false)
                ->assertSee('name="type" value="all"', false);
            $this->assertSame(1, substr_count($page->getContent(), 'class="t012-search-card"'));
            $url = route('public.project', ['slug' => $approved->slug, 'lang' => $lang, 'source' => 'search', 'q' => 'screenneedle', 'type' => 'all']);
            $this->assertSame(1, substr_count($page->getContent(), 'href="'.e($url).'"'));
        }
    }

    public function test_mixed_results_are_bounded_to_three_stage_cards_with_no_lost_or_duplicated_links(): void
    {
        $articles = [];
        foreach (['one', 'two', 'three'] as $id) {
            $articles[] = $this->publishedArticle($id);
        }
        $projects = [$this->publicProject('first'), $this->publicProject('second')];

        foreach (['en', 'ar'] as $lang) {
            $page = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'screenneedle', 'type' => 'all',
            ]))->assertOk()
                ->assertSee('name="type" value="all"', false)
                ->assertSee($lang === 'ar' ? 'نتائج موثقة: 3 مقالات، مشروعان.' : 'Verified results: 3 articles, 2 projects.');
            $html = $page->getContent();
            $this->assertSame(3, substr_count($html, 'class="t012-search-card"'));
            $this->assertSame(3, substr_count($html, 'class="t012-search-badge"'));
            foreach ($articles as $article) {
                $path = '/blog/'.$article->slug.'?';
                $this->assertSame(1, substr_count($html, $path), $lang.' duplicate/missing '.$path);
            }
            foreach ($projects as $project) {
                $url = route('public.project', ['slug' => $project->slug, 'lang' => $lang, 'source' => 'search', 'q' => 'screenneedle', 'type' => 'all']);
                $this->assertSame(1, substr_count($html, 'href="'.e($url).'"'), $lang.' duplicate/missing '.$url);
            }

            $filtered = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'screenneedle', 'type' => 'projects',
            ]))->assertOk()->assertDontSee('web13-article-one');
            $this->assertSame(2, substr_count($filtered->getContent(), 'class="t012-search-card"'));
        }
    }

    public function test_no_matches_and_revoked_content_have_truthful_accessible_recovery_states(): void
    {
        $revoked = $this->publishedArticle('revoked');
        $this->publishedArticle('draft', false);
        $revoked->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->publicProject('unverified', false);

        foreach (['en', 'ar'] as $lang) {
            $page = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'screenneedle', 'type' => 'all',
            ]))->assertOk()
                ->assertSee('class="t012-search-no-results" role="status" aria-live="polite"', false)
                ->assertDontSee('class="t012-search-card"', false)
                ->assertDontSee('web13-article-revoked')
                ->assertDontSee('web13-article-draft')
                ->assertDontSee('web13-project-unverified')
                ->assertSee('href="'.e(route('public.blog', ['lang' => $lang])).'"', false);
            $this->assertSame(0, substr_count($page->getContent(), 'class="t012-search-card"'));
        }
    }

    public function test_search_headings_and_scope_are_accessible_truthful_and_bilingual(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
            foreach (['', 'screenneedle'] as $term) {
                // Laravel normalizes a supplied empty q to null, which fails
                // the existing "sometimes|string" validator. A real blank
                // search omits q rather than transmitting q=.
                $params = ['lang' => $lang, 'type' => 'all'];
                if ($term !== '') {
                    $params['q'] = $term;
                }
                $response = $this->get(route('public.search', $params))->assertOk()
                    ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                    ->assertSee('aria-labelledby="web13-functional-heading"', false)
                    ->assertSee('<h2 class="ed-page-title" id="web13-functional-heading">', false)
                    ->assertSee('id="t012-top-search"', false)
                    ->assertSee('type="search"', false)
                    ->assertDontSee('Search runbooks, projects, code and articles...');

                $html = $response->getContent();
                preg_match_all('/<h1(?:\\s|>)/i', $html, $headings);
                $this->assertCount(1, $headings[0], 'Only the reference-stage title is H1 ('.$lang.')');

                // Browsers must not submit q= from the two forms: the GET
                // endpoint correctly rejects a normalized null q value.
                // Native required validation works even with JavaScript off.
                $this->assertMatchesRegularExpression(
                    '/<input[^>]+id="t012-top-search"[^>]+required>/',
                    $html
                );
                $this->assertMatchesRegularExpression(
                    '/<input[^>]+id="all-q"[^>]+required>/',
                    $html
                );
                $this->assertStringContainsString(
                    $lang === 'ar'
                        ? 'ابحث في المقالات والمشاريع العامة الموثقة...'
                        : 'Search verified public articles and projects...',
                    $html
                );
            }
        }
    }

    public function test_no_results_has_keyboard_accessible_recovery_to_both_search_inputs(): void
    {
        $this->publishedArticle('draft-only', false);
        $this->publicProject('no-public-evidence', false);

        foreach (['en', 'ar'] as $lang) {
            $response = $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'screenneedle', 'type' => 'projects',
            ]))->assertOk()
                ->assertSee('class="t012-search-no-results" role="status" aria-live="polite"', false)
                ->assertSee('href="#t012-top-search"', false)
                ->assertSee('href="#all-q"', false)
                ->assertSee($lang === 'ar' ? 'تعديل كلمات البحث' : 'Refine search terms')
                ->assertDontSee('web13-project-no-public-evidence')
                ->assertDontSee('class="t012-search-card"', false);

            $this->assertSame(1, substr_count($response->getContent(), '<h1'));
            $this->assertSame(1, substr_count($response->getContent(), 'id="all-q"'));
        }
    }
}

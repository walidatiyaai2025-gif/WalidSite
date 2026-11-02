<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class T012ReferenceStageTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, string $title, string $body, bool $publish = true): Article
    {
        $article = Article::query()->create([
            'slug' => $slug,
            'title_en' => $title,
            'title_ar' => $title,
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => $body,
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://example.org/reference-stage-test',
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

        return $article->fresh();
    }

    private function runbook(string $slug, string $title, bool $approved = true): string
    {
        $id = (string) Str::uuid();
        $body = '# '.$title;
        $createdAt = now()->subMinutes(5);
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'version' => 1,
            'validated_at' => $approved ? now()->subMinutes(2) : null,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => $createdAt,
        ]);

        return $id;
    }

    public function test_blog_reference_stage_uses_only_live_verified_articles(): void
    {
        $live = $this->article(
            'safe-reference-card',
            'Safe real article card',
            'Actual reviewed summary'
        );
        $draft = $this->article(
            'private-reference-card',
            'Private draft must not render',
            'PRIVATE BODY',
            false
        );

        $page = $this->get('/blog?lang=en')->assertOk()
            ->assertSee('class="t012-reference-stage t012-screen-blog"', false)
            ->assertSee($live->title_en)
            ->assertSee('Actual reviewed summary')
            ->assertDontSee($draft->title_en)
            ->assertDontSee('SQL AlwaysOn');

        $live->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->get('/blog?lang=en')->assertOk()
            ->assertDontSee($live->title_en)
            ->assertDontSee($draft->title_en);
    }

    public function test_article_reference_stage_derives_heading_and_code_from_published_revision(): void
    {
        $article = $this->article(
            'derived-article-stage',
            'Derived article title',
            "## Custom environment\n\n## Custom validation\n\n"
            ."~~~sql\nSELECT 'derived-only';\n~~~\n"
        );

        $this->get('/blog/'.$article->slug.'?lang=en')->assertOk()
            ->assertSee('Derived article title')
            ->assertSee('Custom environment')
            ->assertSee('Custom validation')
            ->assertSee("SELECT 'derived-only';")
            ->assertDontSee('SELECT replica_server_name')
            ->assertSee('href="#sources-title"', false);
    }

    public function test_lab_reference_cards_are_version_reviewed_runbooks_not_static_mock_data(): void
    {
        $approved = $this->runbook('approved-reference-runbook', 'Approved runtime runbook');
        $draft = $this->runbook('draft-reference-runbook', 'Draft runbook must stay hidden', false);

        $page = $this->get('/lab?lang=en')->assertOk()
            ->assertSee('Approved runtime runbook')
            ->assertDontSee('Draft runbook must stay hidden')
            ->assertDontSee('SQL Diagnostics');

        DB::table('runbook_versions')->where('runbook_id', $approved)
            ->update(['checksum' => str_repeat('0', 64)]);
        $this->get('/lab?lang=en')->assertOk()
            ->assertDontSee('Approved runtime runbook')
            ->assertDontSee('Draft runbook must stay hidden');

        $this->get('/lab/runbooks/'.$draft)->assertNotFound();
    }

    public function test_search_reference_cards_follow_real_query_results_only(): void
    {
        $matching = $this->article(
            'matching-reference-search',
            'Actual search result',
            'needle-reference-stage'
        );
        $other = $this->article(
            'other-reference-search',
            'Unrelated approved article',
            'different phrase'
        );

        $this->get('/search?lang=en&q=needle-reference-stage&type=articles')->assertOk()
            ->assertSee('Actual search result')
            ->assertDontSee('Unrelated approved article')
            ->assertDontSee('SQL Server / HA');

        $matching->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->get('/search?lang=en&q=needle-reference-stage&type=articles')->assertOk()
            ->assertDontSee('Actual search result')
            ->assertDontSee('Unrelated approved article');
    }

    public function test_search_stage_preserves_query_filter_and_language_without_duplicate_results(): void
    {
        $live = $this->article(
            'preview-stage-bilingual',
            'Bilingual reviewed search hit',
            'This guide contains needle-stage-search for a real filtered lookup.'
        );
        $this->article(
            'preview-stage-hidden',
            'Private needle-stage-search',
            'needle-stage-search',
            false
        );

        foreach (['en', 'ar'] as $lang) {
            $other = $lang === 'en' ? 'ar' : 'en';
            foreach (['all', 'articles'] as $type) {
                $query = [
                    'lang' => $lang,
                    'q' => 'needle-stage-search',
                    'type' => $type,
                ];
                $page = $this->get(route('public.search', $query))->assertOk()
                    ->assertSee('id="t012-top-search"', false)
                    ->assertSee('value="needle-stage-search"', false)
                    ->assertSee('name="type" value="'.$type.'"', false)
                    ->assertSee('href="'.e(route('public.search', [
                        'lang' => $other,
                        'q' => 'needle-stage-search',
                        'type' => $type,
                    ])).'"', false)
                    ->assertSee('Bilingual reviewed search hit')
                    ->assertDontSee('Private needle-stage-search');

                // The three-card preview and accessible remainder together
                // represent each result exactly once, even with 1–3 hits.
                $this->assertSame(
                    1,
                    substr_count($page->getContent(), '/blog/'.$live->slug.'?')
                );
            }
        }
    }

    public function test_filtered_projects_use_real_approved_preview_and_keep_type_on_submission(): void
    {
        $approved = Project::create([
            'slug' => 'verified-stage-project',
            'title' => 'needle-stage-project approved work',
            'summary_en' => 'Reviewed public evidence',
            'summary_ar' => 'دليل عام تمت مراجعته',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $approved->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/verified-project',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
        Project::create([
            'slug' => 'unreviewed-stage-project',
            'title' => 'needle-stage-project unreviewed work',
            'summary_en' => 'No source evidence',
            'summary_ar' => 'بدون دليل',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        foreach (['en', 'ar'] as $lang) {
            $other = $lang === 'en' ? 'ar' : 'en';
            $page = $this->get(route('public.search', [
                'lang' => $lang,
                'q' => 'needle-stage-project',
                'type' => 'projects',
            ]))->assertOk()
                ->assertSee('name="type" value="projects"', false)
                ->assertSee($lang === 'ar' ? 'مشروع موثق' : 'Verified project')
                ->assertSee('needle-stage-project approved work')
                ->assertDontSee('needle-stage-project unreviewed work')
                ->assertSee('href="'.e(route('public.search', [
                    'lang' => $other,
                    'q' => 'needle-stage-project',
                    'type' => 'projects',
                ])).'"', false);
            $this->assertSame(
                1,
                substr_count($page->getContent(), '/projects/'.$approved->slug.'?')
            );
        }
    }

    public function test_search_stage_empty_and_user_query_are_not_invented_or_raw_html(): void
    {
        $this->get('/search?lang=en&q=unlikely-unmatched-73952&type=all')
            ->assertOk()
            ->assertSee('value="unlikely-unmatched-73952"', false)
            ->assertSee('No verified public matches.')
            ->assertDontSee('class="t012-search-card"', false);

        $query = '<script>alert(9)</script>';
        $page = $this->get(route('public.search', [
            'lang' => 'ar',
            'q' => $query,
            'type' => 'articles',
        ]))->assertOk();
        $page->assertSee('value="'.e($query).'"', false)
            ->assertDontSee('value="'.$query.'"', false);
    }

    public function test_reference_stage_locale_switch_is_defined_on_each_screen(): void
    {
        $article = $this->article(
            'locale-stage-verified-article',
            'Locale switch verified article',
            "## Validated section\n\nReviewed technical steps."
        );

        foreach (['en' => 'ar', 'ar' => 'en'] as $locale => $other) {
            $scenarios = [
                [route('public.blog', ['lang' => $locale]), route('public.blog', ['lang' => $other])],
                [route('public.article', ['slug' => $article->slug, 'lang' => $locale]),
                    route('public.article', ['slug' => $article->slug, 'lang' => $other])],
                [route('public.lab', ['lang' => $locale]), route('public.lab', ['lang' => $other])],
                [route('public.search', ['lang' => $locale, 'q' => 'locale-stage', 'type' => 'articles']),
                    route('public.search', ['lang' => $other, 'q' => 'locale-stage', 'type' => 'articles'])],
            ];

            foreach ($scenarios as [$url, $switchUrl]) {
                $this->get($url)
                    ->assertOk()
                    ->assertSee('href="'.e($switchUrl).'"', false);
            }
        }
    }


    public function test_all_four_reference_navbars_link_to_real_tech_lab_and_offer_a_language_switch(): void
    {
        $article = $this->article(
            'accessible-reference-nav',
            'Accessible reviewed content',
            "## Reviewed check\\n\\nPublished engineering steps."
        );

        foreach (['en' => 'ar', 'ar' => 'en'] as $lang => $other) {
            $pages = [
                route('public.blog', ['lang' => $lang]),
                route('public.article', ['slug' => $article->slug, 'lang' => $lang]),
                route('public.lab', ['lang' => $lang]),
                route('public.search', ['lang' => $lang, 'q' => 'reviewed', 'type' => 'articles']),
            ];
            $labLink = 'href="'.e(route('public.lab', ['lang' => $lang])).'">'
                .($lang === 'ar' ? 'مختبر AI' : 'AI Lab').'</a>';

            foreach ($pages as $url) {
                $response = $this->get($url)->assertOk()
                    ->assertSee($labLink, false)
                    ->assertSee(
                        'class="t012-sr-only t012-locale-switch" lang="'.$other.'" hreflang="'.$other.'"',
                        false
                    )
                    ->assertSee('.t012-nav-shell>.t012-locale-switch:focus-visible', false)
                    ->assertSee('min-width:88px!important', false)
                    ->assertSee('id="main-content"', false);

                $this->assertStringNotContainsString(
                    'href="'.e(route('public.expertise', ['lang' => $lang])).'">AI Lab</a>',
                    $response->getContent()
                );
                $this->assertStringNotContainsString(
                    'href="'.e(route('public.expertise', ['lang' => $lang])).'">مختبر AI</a>',
                    $response->getContent()
                );
            }
        }
    }

    public function test_search_reference_keeps_keyboard_submit_and_visible_focus_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $lang) {
            $this->get(route('public.search', [
                'lang' => $lang, 'q' => 'reviewed', 'type' => 'articles',
            ]))->assertOk()
                ->assertSee('name="q" type="search"', false)
                ->assertSee('value="reviewed"', false)
                ->assertSee('name="type" value="articles"', false)
                ->assertSee('.t012-search-box input:focus-visible', false)
                ->assertSee('<button class="t012-sr-only" type="submit">', false)
                ->assertSee('role="search"', false)
                ->assertSee('<html lang="'.$lang.'" dir="'.($lang === 'ar' ? 'rtl' : 'ltr').'">', false);
        }
    }


    public function test_reference_blog_language_switch_preserves_only_validated_search_filters_and_page(): void
    {
        foreach (['en' => 'ar', 'ar' => 'en'] as $lang => $other) {
            $response = $this->get(route('public.blog', [
                'lang' => $lang,
                'q' => 'verified knowledge',
                'category' => 'database',
                'page' => 2,
                'redirect' => 'https://untrusted.example.test/secret',
            ]))->assertOk();
            $expected = route('public.blog', [
                'lang' => $other,
                'q' => 'verified knowledge',
                'category' => 'database',
                'page' => 2,
            ]);

            $response->assertSee(
                'class="t012-sr-only t012-locale-switch" lang="'.$other.'" hreflang="'.$other.'" href="'.e($expected).'"',
                false
            )->assertDontSee('untrusted.example.test');
        }
    }

    public function test_reference_blog_language_switch_omits_empty_filters_and_page_one(): void
    {
        foreach (['en' => 'ar', 'ar' => 'en'] as $lang => $other) {
            $this->get(route('public.blog', ['lang' => $lang]))->assertOk()
                ->assertSee(
                    'class="t012-sr-only t012-locale-switch" lang="'.$other.'" hreflang="'.$other.'" href="'.e(route('public.blog', ['lang' => $other])).'"',
                    false
                );
        }
    }
}

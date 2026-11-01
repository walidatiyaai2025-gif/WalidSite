<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicArticlePublicationTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug = 'source-backed-guide'): Article
    {
        return Article::create([
            'slug' => $slug,
            'title_en' => 'Verified technical guide',
            'title_ar' => 'دليل تقني موثق',
            'canonical_lang' => 'en',
        ]);
    }

    private function revision(Article $article, int $number = 1, bool $valid = true, ?string $body = null): ArticleRevision
    {
        return $article->revisions()->create([
            'revision_no' => $number,
            'body_md' => $body ?? '# Verified step '.$number,
            'status' => $valid ? 'validated' : 'draft',
            'citations_json' => $valid
                ? [['source_url' => 'https://example.org/verified', 'verified' => true]]
                : [],
            'validation_json' => [
                'citations_passed' => $valid,
                'technical_passed' => $valid,
            ],
        ]);
    }

    public function test_drafts_and_unvalidated_entries_never_appear_publicly(): void
    {
        Http::fake();
        $article = $this->article();
        $this->revision($article, 1, true);

        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();

        // A database record marked published without a reviewed snapshot is
        // still hidden. No bypass through status or published_at alone.
        $article->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->getJson('/api/v1/public/articles')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_published_revision_is_frozen_when_new_unapproved_draft_is_saved(): void
    {
        $article = $this->article();
        $this->revision($article);
        $article->publishVerified();

        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->getJson('/api/v1/public/articles?lang=ar')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'دليل تقني موثق')
            ->assertJsonPath('data.0.body_markdown', '# Verified step 1')
            ->assertJsonPath('data.0.tested_status', 'documented')
            ->assertJsonPath('data.0.hero_image_url', null)
            ->assertJsonPath('data.0.citations.0', 'https://example.org/verified');

        $this->revision($article, 2, false);
        $this->getJson('/api/v1/public/articles/source-backed-guide?lang=en')->assertOk()
            ->assertJsonPath('data.title', 'Verified technical guide')
            ->assertJsonPath('data.body_markdown', '# Verified step 1');
        $this->assertSame(1, Article::publiclyVisible()->count());
        $this->getJson('/api/v1/public/articles?q=nonexistent')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles?q=technical')->assertJsonCount(1, 'data');
    }

    public function test_revoked_citations_hide_both_list_and_detail_after_publication(): void
    {
        $article = $this->article();
        $revision = $this->revision($article);
        $article->publishVerified();
        $revision->update(['citations_json' => [
            ['source_url' => 'https://example.org/verified', 'verified' => false],
        ]]);

        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    }

    public function test_revoked_image_rights_hide_published_article_and_storage_paths(): void
    {
        $asset = Asset::create([
            'asset_key' => 'documented-hero',
            'mime_type' => 'image/png',
            'storage_key' => 'private/original/should-not-leak.png',
            'sha256' => str_repeat('a', 64),
            'origin' => 'owner-supplied',
            'rights_status' => 'cleared',
            'publishable' => true,
        ]);
        $article = $this->article();
        $article->hero_asset_id = $asset->id;
        $article->save();
        $this->revision($article);
        $article->publishVerified();

        $response = $this->getJson('/api/v1/public/articles/source-backed-guide')->assertOk()
            ->assertJsonPath('data.hero_image_url', null);
        $this->assertStringNotContainsString('private/original', $response->getContent());

        $asset->update(['rights_status' => 'revoked', 'publishable' => false]);
        $this->getJson('/api/v1/public/articles')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles/source-backed-guide')->assertNotFound();
    }

    public function test_query_validation_and_draft_cannot_disclose_content(): void
    {
        $this->getJson('/api/v1/public/articles?lang=xx')->assertUnprocessable();
        $this->getJson('/api/v1/public/articles?page=-5')->assertUnprocessable();
        $this->getJson('/api/v1/public/articles?q='.str_repeat('x', 100))->assertUnprocessable();
        $this->getJson('/api/v1/public/articles/source-backed-guide')->assertNotFound();
    }

    /**
     * A future-revision or revoked citation can leave a still-"validated"
     * database row in the first-pass SQL scope. It must never consume a
     * public page slot or advertise hidden results through has_more_pages.
     */
    public function test_public_article_pages_only_count_live_verified_revisions(): void
    {
        $unsafeSlugs = [];
        $safeSlugs = [];
        foreach (range(1, 13) as $i) {
            $unsafe = $this->article('unsafe-index-'.$i);
            $rev = $this->revision($unsafe);
            $unsafe->publishVerified();
            $unsafe->forceFill(['published_at' => now()->subMinute()])->save();
            // Simulate already-persisted citation withdrawal, independent of
            // application-level model invalidation hooks.
            \Illuminate\Support\Facades\DB::table('article_revisions')
                ->where('id', $rev->id)
                ->update(['citations_json' => json_encode([
                    ['source_url' => 'https://docs.example.org/path?access_token=private',
                        'verified' => true],
                ], JSON_THROW_ON_ERROR)]);
            $unsafeSlugs[] = $unsafe->slug;
        }
        foreach (range(1, 13) as $i) {
            $safe = $this->article('safe-index-'.$i);
            $this->revision($safe);
            $safe->publishVerified();
            $safe->forceFill(['published_at' => now()->subDays(2)])->save();
            $safeSlugs[] = $safe->slug;
        }

        $first = $this->getJson('/api/v1/public/articles?page=1')->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more_pages', true);
        $second = $this->getJson('/api/v1/public/articles?page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.has_more_pages', false);
        $third = $this->getJson('/api/v1/public/articles?page=3')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);

        $returned = array_merge(
            array_column($first->json('data'), 'slug'),
            array_column($second->json('data'), 'slug'),
        );
        $this->assertEqualsCanonicalizing($safeSlugs, $returned);
        foreach ($unsafeSlugs as $slug) {
            $first->assertDontSee($slug);
            $second->assertDontSee($slug);
        }
        $first->assertDontSee('access_token');
    }

    /**
     * WEB-07/MOBILE-07 cards must always resolve to WEB-08/API details.
     * Legacy reviewed publications with underscores are still SQL-visible
     * but cannot match the canonical [a-zA-Z0-9-]+ detail routes.
     */
    public function test_public_and_mobile_feeds_exclude_non_routable_slugs_before_page_slots(): void
    {
        Http::fake();

        for ($i = 1; $i <= 17; $i++) {
            $legacy = $this->article(sprintf('legacy_unroutable_%02d', $i));
            $this->revision($legacy, 1, true, '# technical slug migration');
            $legacy->publishVerified();
            $legacy->forceFill(['published_at' => now()->subMinute()])->save();
        }

        $expected = [];
        for ($i = 1; $i <= 13; $i++) {
            $safe = $this->article(sprintf('approved-routable-%02d', $i));
            $this->revision($safe, 1, true, '# technical approved details');
            $safe->publishVerified();
            // Explicit, distinct timestamps avoid random UUID tie breakers.
            $safe->forceFill(['published_at' => now()->subDays(2)->subMinutes($i)])->save();
            $expected[] = $safe->slug;
        }

        foreach (['/api/v1/public/articles', '/api/v1/mobile/articles'] as $route) {
            foreach (['en', 'ar'] as $lang) {
                $first = $this->getJson($route.'?lang='.$lang.'&q=technical&page=1')
                    ->assertOk()->assertJsonCount(12, 'data')
                    ->assertJsonPath('meta.has_more_pages', true)
                    ->assertJsonPath('meta.current_page', 1)
                    ->assertDontSee('legacy_unroutable_');
                $second = $this->getJson($route.'?lang='.$lang.'&q=technical&page=2')
                    ->assertOk()->assertJsonCount(1, 'data')
                    ->assertJsonPath('meta.has_more_pages', false)
                    ->assertJsonPath('meta.current_page', 2)
                    ->assertDontSee('legacy_unroutable_');
                $this->getJson($route.'?lang='.$lang.'&q=technical&page=3')
                    ->assertOk()->assertJsonCount(0, 'data')
                    ->assertJsonPath('meta.has_more_pages', false);

                $this->assertSame($expected, array_merge(
                    array_column($first->json('data'), 'slug'),
                    array_column($second->json('data'), 'slug')
                ));
                $first->assertJsonPath('data.0.title', $lang === 'ar'
                    ? 'دليل تقني موثق' : 'Verified technical guide');
            }
        }

        $this->getJson('/api/v1/public/articles/legacy_unroutable_01')
            ->assertNotFound();
        $this->getJson('/api/v1/public/articles/'.$expected[0])
            ->assertOk()->assertJsonPath('data.slug', $expected[0]);
        Http::assertNothingSent();
    }

    public function test_unsafe_only_publications_never_advertise_a_next_page(): void
    {
        foreach (range(1, 14) as $i) {
            $article = $this->article('unusable-sources-'.$i);
            $revision = $this->revision($article);
            $article->publishVerified();
            \Illuminate\Support\Facades\DB::table('article_revisions')
                ->where('id', $revision->id)
                ->update(['citations_json' => json_encode([
                    ['source_url' => 'https://private.example.org/file?api_key=hidden',
                        'verified' => true],
                ], JSON_THROW_ON_ERROR)]);
        }

        $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/public/articles?page=2')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
    }

    public function test_public_article_revocation_updates_page_navigation_immediately(): void
    {
        $first = $this->article('revoked-front');
        $firstRevision = $this->revision($first);
        $first->publishVerified();
        $second = $this->article('safe-after-revocation');
        $this->revision($second);
        $second->publishVerified();

        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(2, 'data');
        \Illuminate\Support\Facades\DB::table('article_revisions')
            ->where('id', $firstRevision->id)
            ->update(['validation_json' => json_encode([
                'citations_passed' => false,
                'technical_passed' => true,
            ], JSON_THROW_ON_ERROR)]);

        $result = $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertJsonPath('data.0.slug', 'safe-after-revocation');
        $result->assertDontSee('revoked-front');
    }

    public function test_search_treats_sql_like_percent_and_underscore_as_literal_text(): void
    {
        $article = $this->article('literal-search-guide');
        $this->revision($article);
        $article->publishVerified();

        $this->getJson('/api/v1/public/articles?q=%25')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/public/articles?q=_')->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles?q=technical&lang=ar')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'دليل تقني موثق');
    }


    public function test_api_body_search_uses_only_current_pinned_verified_revision(): void
    {
        $article = $this->article('body-search-guide');
        $this->revision($article, 1, true, '# Published database-recovery-window instructions');
        $article->publishVerified();

        $this->getJson('/api/v1/public/articles?q=database-recovery-window&lang=en')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'body-search-guide')
            ->assertJsonPath('data.0.body_markdown', '# Published database-recovery-window instructions')
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/public/articles?q=database-recovery-window&lang=ar')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'دليل تقني موثق');

        // Creating later draft or merely validated unpinned material must
        // never change the published search index.
        $this->revision($article, 2, false, 'PRIVATE_DRAFT_SEARCH_SENTINEL');
        $this->revision($article, 3, true, 'UNPINNED_VALIDATED_SEARCH_SENTINEL');
        foreach (['PRIVATE_DRAFT_SEARCH_SENTINEL', 'UNPINNED_VALIDATED_SEARCH_SENTINEL'] as $term) {
            $this->getJson('/api/v1/public/articles?q='.$term)
                ->assertOk()->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.has_more_pages', false);
        }
        $this->getJson('/api/v1/public/articles?q=database-recovery-window')
            ->assertOk()->assertJsonCount(1, 'data');

        $article->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);
        $this->getJson('/api/v1/public/articles?q=database-recovery-window')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
    }

    public function test_published_body_search_escapes_like_metacharacters(): void
    {
        $specific = $this->article('body-literal-markers');
        $this->revision($specific, 1, true, 'SQL_query verification achieved 50% coverage.');
        $specific->publishVerified();

        $unrelated = $this->article('body-no-metacharacters');
        $this->revision($unrelated, 1, true, 'Plain documented operation.');
        $unrelated->publishVerified();

        foreach (['%25', '_', 'SQL_query', '50%25'] as $term) {
            $this->getJson('/api/v1/public/articles?q='.$term)
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.slug', 'body-literal-markers')
                ->assertJsonPath('meta.has_more_pages', false);
        }
        $this->getJson('/api/v1/public/articles?q=%25_')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/articles?q=Plain')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'body-no-metacharacters');
    }

    public function test_article_body_search_paginates_only_live_approved_results(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $invalid = $this->article('revoked-body-hit-'.$i);
            $review = $this->revision($invalid, 1, true, 'Reference-only articlebodytoken.');
            $invalid->publishVerified();
            $invalid->forceFill(['published_at' => now()->subMinute()])->save();
            $review->update([
                'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
            ]);
        }

        $safeSlugs = [];
        for ($i = 1; $i <= 13; $i++) {
            $safe = $this->article('approved-body-hit-'.$i);
            $this->revision($safe, 1, true, 'Approved articlebodytoken procedure.');
            $safe->publishVerified();
            $safe->forceFill(['published_at' => now()->subDays(2)])->save();
            $safeSlugs[] = $safe->slug;
        }

        $first = $this->getJson('/api/v1/public/articles?q=articlebodytoken&page=1')
            ->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more_pages', true);
        $second = $this->getJson('/api/v1/public/articles?q=articlebodytoken&page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/public/articles?q=articlebodytoken&page=3')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);

        $returned = array_merge(
            array_column($first->json('data'), 'slug'),
            array_column($second->json('data'), 'slug'),
        );
        $this->assertEqualsCanonicalizing($safeSlugs, $returned);
        $first->assertDontSee('revoked-body-hit-');
        $second->assertDontSee('revoked-body-hit-');
    }


    public function test_contract_mobile_articles_returns_identical_verified_pages_and_locales(): void
    {
        Http::fake();
        for ($i = 1; $i <= 13; $i++) {
            $article = $this->article('mobile-verified-'.$i);
            $this->revision($article, 1, true, '# Reviewed mobile article '.$i);
            $article->publishVerified();
        }

        foreach (['en', 'ar'] as $lang) {
            foreach ([1 => 12, 2 => 1, 3 => 0] as $page => $count) {
                $path = '?lang='.$lang.'&page='.$page;
                $public = $this->getJson('/api/v1/public/articles'.$path)
                    ->assertOk()->assertJsonCount($count, 'data');
                $mobile = $this->getJson('/api/v1/mobile/articles'.$path)
                    ->assertOk()->assertJsonCount($count, 'data');
                $this->assertSame($public->json(), $mobile->json());
                $mobile->assertJsonPath('meta.current_page', $page)
                    ->assertJsonPath('meta.has_more_pages', $page === 1);
                if ($page === 1) {
                    $mobile->assertJsonPath('data.0.title',
                        $lang === 'ar' ? 'دليل تقني موثق' : 'Verified technical guide');
                }
            }
        }

        Http::assertNothingSent();
    }

    public function test_contract_mobile_articles_enforces_live_revocation_search_and_validation(): void
    {
        Http::fake();
        $safe = $this->article('mobile-approved-body');
        $review = $this->revision($safe, 1, true, '# Mobile-approved search phrase');
        $safe->publishVerified();
        // Newer, unapproved draft may not leak via search or serialization.
        $this->revision($safe, 2, false, '# Mobile-secret-private-draft');

        $draft = $this->article('mobile-unpublished');
        $this->revision($draft, 1, true, '# Do not publish');
        foreach (['/api/v1/mobile/articles', '/api/v1/public/articles'] as $path) {
            $this->getJson($path.'?q=Mobile-approved&lang=ar')->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.slug', 'mobile-approved-body')
                ->assertJsonPath('data.0.body_markdown', '# Mobile-approved search phrase')
                ->assertDontSee('Mobile-secret-private-draft')
                ->assertDontSee('mobile-unpublished');
            $this->getJson($path.'?q=Mobile-secret-private-draft')->assertOk()
                ->assertJsonCount(0, 'data');
        }

        foreach (['?page=0', '?page=10001', '?lang=de', '?q='.str_repeat('x', 81)] as $query) {
            $this->getJson('/api/v1/mobile/articles'.$query)->assertUnprocessable();
        }

        $review->update(['validation_json' => [
            'citations_passed' => false, 'technical_passed' => true,
        ]]);
        $this->getJson('/api/v1/mobile/articles')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.has_more_pages', false);
        Http::assertNothingSent();
    }

}

<?php

namespace Tests\Feature\Integration;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T024 cross-surface acceptance: one approved publication must remain the
 * same verified revision in Web, public API and the mobile article feed.
 *
 * These are real Laravel kernel/database requests, not screenshot evidence,
 * external-provider credentials or a claim of complete G-01..G-20 coverage.
 */
final class PublicPublicationRevocationJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function reviewedArticle(string $slug = 'evidencematrix-guide'): array
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'EvidenceMatrix verified engineering guide',
            'title_ar' => 'دليل EvidenceMatrix الهندسي الموثق',
            'canonical_lang' => 'en',
        ]);

        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# EvidenceMatrix reviewed body',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/evidencematrix-approved',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();

        return [$article, $revision];
    }

    private function assertArticleAbsent(string $slug): void
    {
        foreach (['en', 'ar'] as $lang) {
            $this->get('/blog?lang='.$lang)->assertOk()->assertDontSee($slug);
            $this->get('/search?lang='.$lang.'&q=EvidenceMatrix')
                ->assertOk()->assertDontSee($slug);
            foreach (['/api/v1/public/articles', '/api/v1/mobile/articles'] as $endpoint) {
                $result = $this->getJson($endpoint.'?lang='.$lang)
                    ->assertOk()->assertDontSee($slug);
                $this->assertStringContainsString('no-store', (string) $result->headers->get('Cache-Control'));
            }
            $this->getJson('/api/v1/public/search?lang='.$lang.'&q=EvidenceMatrix')
                ->assertOk()->assertDontSee($slug);
            $this->get('/blog/'.$slug.'?lang='.$lang)->assertNotFound();
            $this->getJson('/api/v1/public/articles/'.$slug.'?lang='.$lang)->assertNotFound();
        }
    }

    public function test_verified_publication_is_one_consistent_bilingual_web_api_and_mobile_journey(): void
    {
        Http::preventStrayRequests();
        [$article] = $this->reviewedArticle();

        // A draft created after publication must never supersede the approved
        // revision through the browser, search, mobile feed or direct API URL.
        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => '# PRIVATE_UNAPPROVED_REVISION_SENTINEL',
            'status' => 'draft',
            'citations_json' => [],
            'validation_json' => ['citations_passed' => false, 'technical_passed' => false],
        ]);

        foreach (['en', 'ar'] as $lang) {
            $title = $lang === 'ar'
                ? 'دليل EvidenceMatrix الهندسي الموثق'
                : 'EvidenceMatrix verified engineering guide';

            $this->get('/blog?lang='.$lang)->assertOk()
                ->assertSee($title)
                ->assertDontSee('PRIVATE_UNAPPROVED_REVISION_SENTINEL');
            $this->get('/blog/'.$article->slug.'?lang='.$lang)->assertOk()
                ->assertSee('EvidenceMatrix reviewed body')
                ->assertDontSee('PRIVATE_UNAPPROVED_REVISION_SENTINEL');

            $this->get('/search?lang='.$lang.'&q=EvidenceMatrix')->assertOk()
                ->assertSee($article->slug)
                ->assertDontSee('PRIVATE_UNAPPROVED_REVISION_SENTINEL');
            $this->getJson('/api/v1/public/search?lang='.$lang.'&q=EvidenceMatrix')
                ->assertOk()->assertJsonPath('data.articles.0.slug', $article->slug)
                ->assertDontSee('PRIVATE_UNAPPROVED_REVISION_SENTINEL');

            foreach (['/api/v1/public/articles', '/api/v1/mobile/articles'] as $endpoint) {
                $list = $this->getJson($endpoint.'?lang='.$lang)
                    ->assertOk()
                    ->assertJsonPath('data.0.slug', $article->slug)
                    ->assertJsonPath('data.0.title', $title)
                    ->assertJsonPath('data.0.body_markdown', '# EvidenceMatrix reviewed body')
                    ->assertDontSee('PRIVATE_UNAPPROVED_REVISION_SENTINEL');
                $this->assertStringContainsString('no-store', (string) $list->headers->get('Cache-Control'));
            }
            $detail = $this->getJson('/api/v1/public/articles/'.$article->slug.'?lang='.$lang)
                ->assertOk()->assertJsonPath('data.body_markdown', '# EvidenceMatrix reviewed body')
                ->assertJsonPath('data.title', $title)
                ->assertDontSee('PRIVATE_UNAPPROVED_REVISION_SENTINEL');
            $this->assertStringContainsString('no-store', (string) $detail->headers->get('Cache-Control'));
        }

        Http::assertNothingSent();
    }

    public function test_withdrawn_citation_removes_publication_from_every_surface_without_stale_cache(): void
    {
        Http::preventStrayRequests();
        [$article, $revision] = $this->reviewedArticle('evidencematrix-withdrawn');

        // Prove the publication was reachable before the reviewer revokes
        // evidence; revocation must affect already-published content at read time.
        $this->get('/blog/'.$article->slug)->assertOk();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();
        $revision->update(['citations_json' => [[
            'source_url' => 'https://docs.example.org/evidencematrix-approved',
            'verified' => false,
        ]]]);

        $this->assertArticleAbsent($article->slug);
        Http::assertNothingSent();
    }

    public function test_unpublished_revisions_are_not_directly_discoverable_or_searchable(): void
    {
        Http::preventStrayRequests();
        $article = Article::create([
            'slug' => 'evidencematrix-unpublished',
            'title_en' => 'EvidenceMatrix private planning guide',
            'title_ar' => 'مخطط EvidenceMatrix غير منشور',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# PRIVATE_UNAPPROVED_REVISION_SENTINEL',
            'status' => 'draft',
            'citations_json' => [],
            'validation_json' => ['citations_passed' => false, 'technical_passed' => false],
        ]);

        $this->assertArticleAbsent($article->slug);
        Http::assertNothingSent();
    }
}

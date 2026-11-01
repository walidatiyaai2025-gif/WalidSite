<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticlePrivateDnsEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, string $source): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Reviewed guide '.$slug,
            'title_ar' => 'دليل موثق '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Source-reviewed technical documentation.',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => $source,
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);

        return $article;
    }

    public function test_internal_dns_citations_never_authorize_an_article_publication(): void
    {
        Http::fake();
        foreach ([
            'https://metadata.kubernetes.default.svc/secrets',
            'https://printer.lan/configuration',
            'https://router.home/admin',
            'https://gateway.home.arpa/status',
            'https://host.localdomain/diagnostic',
            'https://service.internal/private',
            'https://localhost.example.local/diagnostic',
        ] as $index => $source) {
            $article = $this->article('private-dns-evidence-'.$index, $source);
            $this->assertFalse($article->isReadyToPublish(), $source);
            $this->assertFalse($article->revisions()->firstOrFail()->hasVerifiedEvidence(), $source);
            try {
                $article->publishVerified();
                $this->fail('An internal DNS citation must not authorize publication: '.$source);
            } catch (DomainException $exception) {
                $this->assertSame(
                    'Article evidence, editorial validation, or media rights are incomplete.',
                    $exception->getMessage()
                );
            }
        }

        $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertDontSee('private-dns-evidence-');
        $this->get('/blog?lang=ar')->assertOk()
            ->assertDontSee('private-dns-evidence-');
        Http::assertNothingSent();
    }

    public function test_withdrawing_a_published_citation_to_private_dns_hides_it_immediately(): void
    {
        Http::fake();
        $article = $this->article('formerly-public-guide', 'https://docs.example.org/public-guide');
        $article->publishVerified();

        $this->getJson('/api/v1/public/articles?lang=en')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $article->slug)
            ->assertJsonPath('data.0.citations.0', 'https://docs.example.org/public-guide');
        $this->get('/blog?lang=ar')->assertOk()
            ->assertSee('دليل موثق formerly-public-guide');

        // An external revocation/import can edit persisted citation JSON
        // without triggering Eloquent's draft reset. Read-time proof checks
        // must still reject the old publication pin on every surface.
        DB::table('article_revisions')
            ->where('article_id', $article->id)
            ->update(['citations_json' => json_encode([[
                'source_url' => 'https://metadata.cluster.svc/admin',
                'verified' => true,
            ]], JSON_THROW_ON_ERROR)]);

        $this->assertNull($article->fresh()->publicRevision());
        $this->getJson('/api/v1/public/articles?lang=ar')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertDontSee('metadata.cluster.svc')
            ->assertDontSee('formerly-public-guide');
        $this->getJson('/api/v1/public/articles/formerly-public-guide')->assertNotFound();
        $this->get('/blog?lang=en')->assertOk()->assertDontSee('formerly-public-guide');
        $this->get('/blog?lang=ar')->assertOk()->assertDontSee('formerly-public-guide');
        $this->get('/blog/formerly-public-guide')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('formerly-public-guide');
        Http::assertNothingSent();
    }

    public function test_public_https_citations_stay_available_in_english_and_arabic(): void
    {
        Http::fake();
        $article = $this->article('approved-external-proof', 'https://docs.example.org/approved-evidence');
        $this->assertTrue($article->isReadyToPublish());
        $article->publishVerified();

        $this->getJson('/api/v1/public/articles?lang=en')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Reviewed guide approved-external-proof')
            ->assertJsonPath('data.0.citations.0', 'https://docs.example.org/approved-evidence');
        $this->getJson('/api/v1/public/articles?lang=ar')->assertOk()
            ->assertJsonPath('data.0.title', 'دليل موثق approved-external-proof');
        $this->getJson('/api/v1/public/articles/approved-external-proof')->assertOk()
            ->assertJsonPath('data.citations.0', 'https://docs.example.org/approved-evidence');
        Http::assertNothingSent();
    }
}

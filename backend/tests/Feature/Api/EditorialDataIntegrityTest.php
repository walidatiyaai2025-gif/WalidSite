<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Asset;
use App\Models\Category;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EditorialDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function article(): Article
    {
        return Article::create([
            'slug' => 'verified-runbook',
            'title_en' => 'Verified runbook',
            'title_ar' => 'دليل موثق',
            'canonical_lang' => 'en',
        ]);
    }

    private function revision(Article $article, bool $verified = true): ArticleRevision
    {
        return $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# A source-backed procedure',
            'citations_json' => $verified
                ? [['source_url' => 'https://example.org/evidence', 'verified' => true]]
                : [['source_url' => 'http://invalid.example/source', 'verified' => false]],
            'validation_json' => ['citations_passed' => $verified, 'technical_passed' => true],
            'status' => 'validated',
        ]);
    }

    public function test_canonical_schema_and_empty_public_state(): void
    {
        foreach (['articles', 'article_revisions', 'categories', 'article_categories', 'assets', 'asset_variants'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $article = $this->article();
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertSame(0, Article::publiclyVisible()->count());
        $this->expectException(DomainException::class);
        $article->publishVerified();
    }

    public function test_source_validation_and_latest_revision_are_mandatory(): void
    {
        $article = $this->article();
        $this->revision($article, false);
        $this->assertFalse($article->isReadyToPublish());
        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => 'Later draft',
            'citations_json' => [],
            'validation_json' => [],
            'status' => 'draft',
        ]);
        $this->assertFalse($article->isReadyToPublish());
        $this->assertSame(0, Article::publiclyVisible()->count());
    }

    public function test_approved_source_and_image_rights_gate_real_publication(): void
    {
        $article = $this->article();
        $asset = Asset::create([
            'asset_key' => 'editorial-cover',
            'mime_type' => 'image/png',
            'storage_key' => 'media/sha256/editorial-cover.png',
            'sha256' => str_repeat('a', 64),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);
        $article->hero_asset_id = $asset->id;
        $article->save();
        $this->revision($article);
        $this->assertFalse($article->isReadyToPublish());
        $asset->update(['rights_status' => 'cleared', 'publishable' => true]);
        $this->assertTrue($article->isReadyToPublish());
        $article->publishVerified();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, Article::publiclyVisible()->count());
    }

    public function test_revision_number_and_category_uniqueness_and_cascade(): void
    {
        $article = $this->article();
        $revision = $this->revision($article);
        $category = Category::create(['slug' => 'database', 'name_en' => 'Database', 'name_ar' => 'قواعد بيانات']);
        $article->categories()->attach($category);
        $this->assertSame(1, $article->categories()->count());
        $this->assertSame(1, $category->articles()->count());
        $this->assertSame(1, $article->revisions()->count());
        $this->assertTrue($revision->hasVerifiedEvidence());
        $article->delete();
        $this->assertDatabaseCount('article_revisions', 0);
        $this->assertDatabaseCount('article_categories', 0);
    }

    public function test_unsafe_source_urls_never_authorize_publication_even_when_claimed_verified(): void
    {
        $unsafe = [
            'http://docs.example.org/evidence',
            'https://localhost/internal',
            'https://db.internal/runbook',
            'https://127.0.0.1/private',
            'https://10.0.0.4/private',
            'https://user:pass@docs.example.org/source',
            'https://docs.example.org:8443/source',
            'https://docs.example.org/source?api_key=private',
            'https://service.local/resource',
            'https://docs.example.org/source?token=capability-secret',
            'https://docs.example.org/source?sig=signed-link',
            'https://docs.example.org/source?key=api-secret',
            'https://docs.example.org/source?jwt=bearer-secret',
            'https://docs.example.org/source?code=oauth-authorization-code',
            'https://docs.example.org/source?X-Amz-Signature=private-signature',
            'https://docs.example.org/source?X-Goog-Credential=private-credential',
            'https://docs.example.org/source?lang=en&%74oken=encoded-secret',
            'https://docs.example.org/source#access_token=fragment-secret',
            'https://docs.example.org/source#chapter?token=fragment-secret',
        ];

        foreach ($unsafe as $index => $url) {
            $article = Article::create([
                'slug' => 'rejected-source-'.$index,
                'title_en' => 'Unreviewed evidence',
                'title_ar' => 'مصدر غير موثق',
                'canonical_lang' => 'en',
            ]);
            $revision = $article->revisions()->create([
                'revision_no' => 1,
                'body_md' => 'Evidence is required.',
                'citations_json' => [['source_url' => $url, 'verified' => true]],
                'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
                'status' => 'validated',
            ]);
            $this->assertFalse($revision->hasVerifiedEvidence(), 'Unsafe source accepted: '.$url);
            $this->assertFalse($article->isReadyToPublish());
            $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        }
    }

    public function test_public_documentation_query_and_anchor_without_secrets_are_allowed(): void
    {
        $article = $this->article();
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Referenced public documentation',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/guide?lang=en&version=3#installation',
                'verified' => true,
            ]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            'status' => 'validated',
        ]);

        $this->assertTrue($revision->hasVerifiedEvidence());
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.citations.0', 'https://docs.example.org/guide?lang=en&version=3#installation');
    }

    public function test_multi_encoded_credential_parameters_never_qualify_as_public_citations(): void
    {
        $unsafe = [
            'https://docs.example.org/guide?%2574oken=synthetic-secret',
            'https://docs.example.org/guide?next=https%253A%252F%252Fdocs.example.org%252Fcallback%253Fclient_secret%253Dsynthetic-secret',
            'https://docs.example.org/guide#chapter%253Frefresh_token%253Dsynthetic-secret',
            'https://docs.example.org/guide?X%252DAmz%252DSignature=synthetic-secret',
            'https://docs.example.org/guide?%25252574oken=synthetic-secret',
            'https://docs.example.org/guide?sessionid=synthetic-secret',
            'https://docs.example.org/guide?bearer=synthetic-secret',
        ];

        foreach ($unsafe as $index => $url) {
            $article = Article::create([
                'slug' => 'multi-encoded-source-'.$index,
                'title_en' => 'Unsafe public reference',
                'title_ar' => 'مرجع غير آمن',
                'canonical_lang' => 'en',
            ]);
            $revision = $article->revisions()->create([
                'revision_no' => 1,
                'body_md' => '# A claimed reviewed source',
                'citations_json' => [['source_url' => $url, 'verified' => true]],
                'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
                'status' => 'validated',
            ]);

            $this->assertFalse($revision->hasVerifiedEvidence(), 'Unsafe encoded source was accepted.');
            $this->assertFalse($article->isReadyToPublish());
            $response = $this->getJson('/api/v1/public/articles/'.$article->slug)
                ->assertNotFound();
            $this->assertStringNotContainsString('synthetic-secret', $response->getContent());
        }

        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data')
            ->assertDontSee('synthetic-secret');
    }

    public function test_harmless_nested_documentation_parameters_remain_public(): void
    {
        $article = $this->article();
        $source = 'https://docs.example.org/guide?lang=en&version=4%2520beta#chapter%25201';
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# A referenced public document',
            'citations_json' => [['source_url' => $source, 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            'status' => 'validated',
        ]);

        $this->assertTrue($revision->hasVerifiedEvidence());
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)
            ->assertOk()->assertJsonPath('data.citations.0', $source);
    }

    public function test_live_public_read_hides_pinned_revision_if_encoded_source_was_substituted(): void
    {
        $article = $this->article();
        $revision = $this->revision($article);
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();

        // Simulate a legacy record or bypassed model event. Runtime validation
        // must remain authoritative even if the persisted status is validated.
        \Illuminate\Support\Facades\DB::table('article_revisions')
            ->where('id', $revision->id)
            ->update(['citations_json' => json_encode([
                ['source_url' => 'https://docs.example.org/guide?%2574oken=synthetic-secret',
                 'verified' => true],
            ], JSON_THROW_ON_ERROR)]);

        $this->assertNull($article->fresh()->publicRevision());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data');
    }


    public function test_nested_encoded_redirect_fragment_cannot_leak_oauth_credentials(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $unsafe = 'https://docs.example.org/guide?next='.rawurlencode(rawurlencode(
            'https://docs.example.org/consent#access_token=synthetic-value'
        ));

        $draft = Article::create([
            'slug' => 'nested-redirect-fragment',
            'title_en' => 'Reviewed citation', 'title_ar' => 'مرجع',
            'canonical_lang' => 'en',
        ]);
        $invalid = $draft->revisions()->create([
            'revision_no' => 1, 'body_md' => '# Candidate text',
            'citations_json' => [['source_url' => $unsafe, 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            'status' => 'validated',
        ]);
        $this->assertFalse($invalid->hasVerifiedEvidence());
        $this->assertFalse($draft->isReadyToPublish());
        $this->getJson('/api/v1/public/articles/'.$draft->slug)->assertNotFound()
            ->assertDontSee('synthetic-value');

        // A previously approved snapshot must be revalidated on each read:
        // a legacy bulk import can bypass Eloquent's approval invalidation.
        $article = $this->article();
        $revision = $this->revision($article);
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();

        \Illuminate\Support\Facades\DB::table('article_revisions')
            ->where('id', $revision->id)
            ->update(['citations_json' => json_encode([
                ['source_url' => $unsafe, 'verified' => true],
            ], JSON_THROW_ON_ERROR)]);

        $this->assertNull($article->fresh()->publicRevision());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound()
            ->assertDontSee('synthetic-value');
        $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(0, 'data')->assertDontSee('synthetic-value');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_revoked_source_safety_hides_previously_published_snapshot(): void
    {
        $article = $this->article();
        $revision = $this->revision($article);
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();

        $revision->update(['citations_json' => [
            ['source_url' => 'https://localhost/secret', 'verified' => true],
        ]]);

        $this->assertNull($article->fresh()->publicRevision());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    }
}

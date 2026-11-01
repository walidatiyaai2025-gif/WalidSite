<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Asset;
use App\Models\Category;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_nested_encoded_credentials_never_authorize_public_citations(): void
    {
        Http::fake();

        $unsafe = [
            'https://docs.example.org/guide?%2574oken=synthetic-value',
            'https://docs.example.org/guide?next=%253Faccess_token%253Dsynthetic-value',
            'https://docs.example.org/guide?redirect='.rawurlencode(rawurlencode(
                'https://docs.example.org/next?client_secret=synthetic-value'
            )),
            'https://docs.example.org/guide#chapter%253Frefresh_token%253Dsynthetic-value',
            'https://docs.example.org/guide#%2558-Amz-Signature=synthetic-value',
            'https://docs.example.org/guide?next='.rawurlencode(rawurlencode(
                '?X-Goog-Credential=synthetic-value'
            )),
            'https://docs.example.org/guide?next='.rawurlencode(rawurlencode(
                '?sessionid=synthetic-value'
            )),
            'https://docs.example.org/guide?next='.rawurlencode(rawurlencode(
                '?bearer=synthetic-value'
            )),
            // More layers than the bounded scanner must also fail closed.
            'https://docs.example.org/guide?next='.array_reduce(
                range(1, 7),
                static fn (string $value): string => rawurlencode($value),
                '?token=synthetic-value'
            ),
        ];

        foreach ($unsafe as $index => $url) {
            $article = Article::create([
                'slug' => 'multi-encoded-secret-'.$index,
                'title_en' => 'Unreviewed citation',
                'title_ar' => 'مصدر غير معتمد',
                'canonical_lang' => 'en',
            ]);
            $revision = $article->revisions()->create([
                'revision_no' => 1,
                'body_md' => 'Claim with sensitive link',
                'citations_json' => [['source_url' => $url, 'verified' => true]],
                'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
                'status' => 'validated',
            ]);

            $this->assertFalse($revision->hasVerifiedEvidence());
            $this->assertFalse($article->isReadyToPublish());
            $this->getJson('/api/v1/public/articles/'.$article->slug)
                ->assertNotFound()->assertDontSee('synthetic-value');
        }

        $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertDontSee('synthetic-value');
        Http::assertNothingSent();
    }

    public function test_post_review_encoded_secret_change_hides_published_snapshot(): void
    {
        Http::fake();
        $article = $this->article();
        $revision = $this->revision($article);
        $article->publishVerified();

        $this->getJson('/api/v1/public/articles/'.$article->slug)
            ->assertOk()->assertJsonPath('data.citations.0', 'https://example.org/evidence');

        // Simulate a pre-approved stored row modified by an external import,
        // bypassing Eloquent's own immediate approval-invalidation events.
        // Every public read must still recheck the stored citation URL.
        DB::table('article_revisions')->where('id', $revision->id)->update([
            'citations_json' => json_encode([[
                'source_url' => 'https://docs.example.org/guide?next=%253Ftoken%253Dsynthetic-value',
                'verified' => true,
            ]], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame('published', $article->fresh()->status);
        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->assertNull($article->fresh()->publicRevision());
        $this->getJson('/api/v1/public/articles')->assertOk()
            ->assertJsonCount(0, 'data')->assertDontSee('synthetic-value');
        $this->getJson('/api/v1/public/articles/'.$article->slug)
            ->assertNotFound()->assertDontSee('synthetic-value');

        Http::assertNothingSent();
    }

    public function test_harmless_encoded_documentation_urls_and_fragments_remain_public(): void
    {
        Http::fake();
        $article = $this->article();
        $url = 'https://docs.example.org/guide?next='.rawurlencode(rawurlencode(
            'https://docs.example.org/install?lang=ar&page=2#chapter-1'
        )).'#section%2520two';

        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Public documentation with benign redirect',
            'citations_json' => [['source_url' => $url, 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            'status' => 'validated',
        ]);

        $this->assertTrue($revision->hasVerifiedEvidence());
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)
            ->assertOk()->assertJsonPath('data.citations.0', $url);
        Http::assertNothingSent();
    }

}

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

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

    private function revision(Article $article, int $number = 1, bool $valid = true): ArticleRevision
    {
        return $article->revisions()->create([
            'revision_no' => $number,
            'body_md' => '# Verified step '.$number,
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
}

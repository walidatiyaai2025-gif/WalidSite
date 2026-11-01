<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Asset;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleMetadataInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function publishedArticle(): array
    {
        $article = Article::create([
            'slug' => 'reviewed-technical-guide',
            'title_en' => 'Reviewed technical guide',
            'title_ar' => 'دليل تقني تمت مراجعته',
            'canonical_lang' => 'en',
        ]);

        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Reviewed content',
            'citations_json' => [[
                'source_url' => 'https://example.org/documentation',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
            'status' => 'validated',
        ]);

        $article->publishVerified();

        return [$article, $revision];
    }

    public function test_editing_a_published_title_unpublishes_and_requires_fresh_review(): void
    {
        [$article, $revision] = $this->publishedArticle();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();

        $article->update(['title_en' => 'A different public claim']);

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_revision_no);
        $this->assertNull($article->fresh()->published_at);
        $this->assertSame('draft', $revision->fresh()->status);
        $this->assertSame([
            'citations_passed' => false,
            'technical_passed' => false,
        ], $revision->fresh()->validation_json);
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        $this->getJson('/api/v1/public/articles')->assertJsonCount(0, 'data');

        try {
            $article->publishVerified();
            $this->fail('Unreviewed metadata was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }

        // A separate editorial approval, not the metadata write itself,
        // is needed to make the new title and old reviewed body public again.
        $revision->refresh()->update([
            'status' => 'validated',
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();

        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.title', 'A different public claim');
    }

    public function test_changing_canonical_language_and_hero_revokes_existing_review(): void
    {
        [$article, $revision] = $this->publishedArticle();
        $asset = Asset::create([
            'asset_key' => 'new-hero',
            'mime_type' => 'image/png',
            'storage_key' => 'editorial/approved-cover.png',
            'sha256' => str_repeat('e', 64),
            'origin' => 'owner-supplied',
            'rights_status' => 'cleared',
            'publishable' => true,
        ]);

        // The changed canonical language requires review; the image's
        // independent rights checks continue to apply to public output.
        $article->update([
            'canonical_lang' => 'ar',
            'hero_asset_id' => $asset->id,
        ]);

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    }

    public function test_new_hero_alone_uses_live_rights_gate_without_discarding_text_review(): void
    {
        [$article, $revision] = $this->publishedArticle();
        $asset = Asset::create([
            'asset_key' => 'pending-hero',
            'mime_type' => 'image/png',
            'storage_key' => 'private/unapproved-image.png',
            'sha256' => str_repeat('f', 64),
            'origin' => 'owner-supplied',
            'rights_status' => 'pending',
            'publishable' => false,
        ]);

        $article->update(['hero_asset_id' => $asset->id]);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertTrue($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();

        $asset->update(['rights_status' => 'cleared', 'publishable' => true]);
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.hero_image_url', null);
    }

    public function test_metadata_and_publication_in_one_save_cannot_reuse_old_approval(): void
    {
        [$article, $revision] = $this->publishedArticle();

        $article->forceFill([
            'slug' => 'unreviewed-new-slug',
            'title_ar' => 'ادعاء جديد غير معتمد',
            'status' => 'published',
            'published_at' => now(),
            'published_revision_no' => 1,
        ])->save();

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_at);
        $this->assertNull($article->fresh()->published_revision_no);
        $this->assertSame('draft', $revision->fresh()->status);
        $this->getJson('/api/v1/public/articles/unreviewed-new-slug')->assertNotFound();
        $this->getJson('/api/v1/public/articles/reviewed-technical-guide')->assertNotFound();
    }

    public function test_changing_metadata_on_prepublication_validated_draft_revokes_review(): void
    {
        [$article, $revision] = $this->publishedArticle();

        $article->forceFill([
            'status' => 'draft',
            'published_revision_no' => null,
            'published_at' => null,
        ])->save();

        $revision->update([
            'status' => 'validated',
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->update(['title_ar' => 'عنوان مختلف']);

        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->assertFalse($article->isReadyToPublish());
    }

    public function test_noneditorial_timestamp_update_preserves_published_snapshot(): void
    {
        [$article, $revision] = $this->publishedArticle();
        $article->forceFill(['updated_at' => now()->addMinute()])->save();

        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->assertTrue($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.body_markdown', '# Reviewed content');
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Asset;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorialPublicationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function reviewedArticle(string $slug): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Verified editorial guide',
            'title_ar' => 'دليل محرر موثق',
            'canonical_lang' => 'en',
        ]);

        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Approved, referenced content',
            'citations_json' => [
                ['source_url' => 'https://example.org/documentation', 'verified' => true],
            ],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
            'status' => 'validated',
        ]);

        return $article;
    }

    public function test_unsaved_public_metadata_cannot_produce_a_false_successful_publish(): void
    {
        $changes = [
            'title_en' => 'Unreviewed new title',
            'title_ar' => 'عنوان غير معتمد',
            'canonical_lang' => 'ar',
            'slug' => 'unreviewed-new-slug',
        ];

        foreach ($changes as $field => $value) {
            $article = $this->reviewedArticle('reviewed-'.$field);
            $article->publishVerified();
            $originalSlug = $article->slug;

            $article->{$field} = $value;
            $this->assertFalse($article->isReadyToPublish(), $field.' must require review');

            try {
                $article->publishVerified();
                $this->fail('Unsaved '.$field.' was wrongly accepted.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('incomplete', $exception->getMessage());
            }

            // The attempted publication did not modify the approved DB row.
            $this->assertSame('published', $article->fresh()->status);
            $this->getJson('/api/v1/public/articles/'.$originalSlug)->assertOk()
                ->assertJsonPath('data.title', 'Verified editorial guide');
        }
    }

    public function test_only_rights_cleared_raster_assets_are_publication_eligible(): void
    {
        $asset = Asset::create([
            'asset_key' => 'untrusted-editorial-vector',
            'mime_type' => 'image/svg+xml',
            'storage_key' => 'private/never-expose-image.svg',
            'sha256' => str_repeat('b', 64),
            'origin' => 'owner-supplied',
            'rights_status' => 'cleared',
            'publishable' => true,
        ]);

        $article = $this->reviewedArticle('raster-guard');
        $article->hero_asset_id = $asset->id;
        $article->save();

        foreach (['image/svg+xml', 'image/x-icon', 'image/tiff', 'application/pdf'] as $mime) {
            $asset->update(['mime_type' => $mime]);
            $this->assertFalse($asset->fresh()->isPubliclyUsable(), $mime);
            $this->assertFalse($article->isReadyToPublish(), $mime);
        }

        foreach (['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'] as $mime) {
            $asset->update(['mime_type' => $mime]);
            $this->assertTrue($asset->fresh()->isPubliclyUsable(), $mime);
            $this->assertTrue($article->isReadyToPublish(), $mime);
        }

        $article->publishVerified();
        $response = $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();
        $this->assertStringNotContainsString('private/never-expose-image.svg', $response->getContent());

        // A later media type change invalidates eligibility even after publish.
        $asset->update(['mime_type' => 'image/svg+xml']);
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data');
    }
}

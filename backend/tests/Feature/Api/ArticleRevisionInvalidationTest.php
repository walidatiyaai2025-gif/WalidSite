<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\ArticleRevision;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleRevisionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function reviewedArticle(): array
    {
        $article = Article::create([
            'slug' => 'reviewed-database-guide',
            'title_en' => 'Database guide',
            'title_ar' => 'دليل قواعد البيانات',
            'canonical_lang' => 'en',
        ]);

        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Reviewed content',
            'citations_json' => [
                ['source_url' => 'https://example.org/guide', 'verified' => true],
            ],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
            'status' => 'validated',
        ]);

        $article->publishVerified();

        return [$article, $revision];
    }

    public function test_editing_a_published_body_revokes_prior_approval_and_hides_public_content(): void
    {
        [$article, $revision] = $this->reviewedArticle();

        $this->getJson('/api/v1/public/articles/'.$article->slug)
            ->assertOk()
            ->assertJsonPath('data.body_markdown', '# Reviewed content');

        $revision->update(['body_md' => '# Changed after review']);

        $this->assertSame('draft', $revision->fresh()->status);
        $this->assertSame([
            'citations_passed' => false,
            'technical_passed' => false,
        ], $revision->fresh()->validation_json);
        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data');

        $this->expectException(DomainException::class);
        $article->publishVerified();
    }

    public function test_replacing_sources_revokes_approval_even_if_replacement_url_is_safe(): void
    {
        [$article, $revision] = $this->reviewedArticle();

        $revision->update(['citations_json' => [
            ['source_url' => 'https://example.org/replacement', 'verified' => true],
        ]]);

        $this->assertSame('draft', $revision->fresh()->status);
        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    }

    public function test_changing_content_and_resubmitting_validated_together_cannot_skip_review(): void
    {
        [$article, $revision] = $this->reviewedArticle();

        $revision->update([
            'body_md' => '# New unreviewed information',
            'status' => 'validated',
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);

        $this->assertSame('draft', $revision->fresh()->status);
        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();

        // Only a subsequent, distinct editorial review can restore the flags.
        $revision->update([
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
            'status' => 'validated',
        ]);

        $this->assertTrue($revision->fresh()->hasVerifiedEvidence());
        $article->publishVerified();
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.body_markdown', '# New unreviewed information');
    }

    public function test_unsaved_public_title_change_cannot_report_a_successful_publication(): void
    {
        [$article] = $this->reviewedArticle();
        $article->title_en = 'Unreviewed headline';

        $this->assertFalse($article->isReadyToPublish());
        try {
            $article->publishVerified();
            $this->fail('Unreviewed metadata was silently accepted as published.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Article evidence, editorial validation, or media rights are incomplete.',
                $exception->getMessage(),
            );
        }

        // A rejected, unsaved mutation cannot alter the persisted reviewed
        // public snapshot; an explicit save will instead revoke publication.
        $this->assertSame('Database guide', $article->fresh()->title_en);
        $this->getJson('/api/v1/public/articles/reviewed-database-guide')
            ->assertOk()->assertJsonPath('data.title', 'Database guide');

        $article->save();
        $this->assertSame('draft', $article->fresh()->status);
        $this->getJson('/api/v1/public/articles/reviewed-database-guide')
            ->assertNotFound();
    }

    public function test_transferred_validated_revision_must_undergo_fresh_editorial_review(): void
    {
        [$original, $revision] = $this->reviewedArticle();
        $other = Article::create([
            'slug' => 'destination-guide',
            'title_en' => 'Destination guide',
            'title_ar' => 'دليل جديد',
            'canonical_lang' => 'en',
        ]);

        $revision->update([
            'article_id' => $other->id,
            'status' => 'validated',
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);

        $this->assertSame($other->id, $revision->fresh()->article_id);
        $this->assertSame('draft', $revision->fresh()->status);
        $this->assertFalse($revision->fresh()->hasVerifiedEvidence());
        $this->assertFalse($other->isReadyToPublish());
        $this->getJson('/api/v1/public/articles/reviewed-database-guide')
            ->assertNotFound();
        $this->getJson('/api/v1/public/articles/destination-guide')
            ->assertNotFound();
    }

    public function test_renumbering_validated_revision_invalidates_stale_approval(): void
    {
        [$article, $revision] = $this->reviewedArticle();
        $revision->update([
            'revision_no' => 2,
            'status' => 'validated',
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);

        $this->assertSame(2, $revision->fresh()->revision_no);
        $this->assertSame('draft', $revision->fresh()->status);
        $this->assertFalse($article->isReadyToPublish());
        $this->getJson('/api/v1/public/articles/reviewed-database-guide')
            ->assertNotFound();
    }

    public function test_unmodified_validated_revision_keeps_published_snapshot(): void
    {
        [$article, $revision] = $this->reviewedArticle();

        $revision->update(['status' => 'validated']);
        $this->assertTrue($revision->fresh()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk()
            ->assertJsonPath('data.body_markdown', '# Reviewed content');
    }
}

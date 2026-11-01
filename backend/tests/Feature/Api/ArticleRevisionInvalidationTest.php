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

    public function test_reviewed_revision_cannot_be_transplanted_to_a_different_article(): void
    {
        [$original, $revision] = $this->reviewedArticle();
        $other = Article::create([
            'slug' => 'unreviewed-second-article',
            'title_en' => 'New, unreviewed claim',
            'title_ar' => 'ادعاء جديد لم يراجع',
            'canonical_lang' => 'en',
        ]);

        try {
            $revision->update(['article_id' => $other->id]);
            $this->fail('An approved revision was reparented without reviewing the new article.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame($original->id, $revision->fresh()->article_id);
        $this->assertSame(1, $revision->fresh()->revision_no);
        $this->assertFalse($other->isReadyToPublish());
        $this->getJson('/api/v1/public/articles/'.$original->slug)
            ->assertOk()->assertJsonPath('data.body_markdown', '# Reviewed content');
        $this->getJson('/api/v1/public/articles/'.$other->slug)->assertNotFound();
    }

    public function test_published_revision_number_cannot_be_reassigned_to_bypass_pin(): void
    {
        [$article, $revision] = $this->reviewedArticle();

        try {
            $revision->update(['revision_no' => 2]);
            $this->fail('An approved revision number was rewritten after publication.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame(1, $revision->fresh()->revision_no);
        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->getJson('/api/v1/public/articles/'.$article->slug)
            ->assertOk()->assertJsonPath('data.body_markdown', '# Reviewed content');
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

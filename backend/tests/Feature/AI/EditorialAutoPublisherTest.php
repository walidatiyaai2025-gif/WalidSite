<?php

namespace Tests\Feature\AI;

use App\AI\EditorialAutoPublisher;
use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EditorialAutoPublisherTest extends TestCase
{
    use RefreshDatabase;

    private function candidate(array $overrides = [], bool $validated = true): Article
    {
        $article = Article::create([
            'slug' => 'auto-'.Str::lower(Str::random(12)),
            'title_en' => 'Verified operational guide',
            'title_ar' => 'دليل تشغيلي موثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'A reproducible, evidence-backed guide.',
            'status' => $validated ? 'validated' : 'draft',
            'citations_json' => [[
                'source_url' => 'https://example.org/independent-proof',
                'verified' => $validated,
            ]],
            'validation_json' => array_merge([
                'citations_passed' => $validated,
                'technical_passed' => $validated,
                'risk_level' => 'low',
                'auto_publish_approved' => true,
                'contains_professional_claims' => false,
            ], $overrides),
        ]);
        return $article;
    }

    public function test_disabled_or_not_due_automation_makes_no_change(): void
    {
        $article = $this->candidate();
        $worker = new EditorialAutoPublisher();

        $this->assertSame('disabled', $worker->publishDue($article->id, false, now()->subMinute()));
        $this->assertSame('not_due', $worker->publishDue($article->id, true, now()->addDay()));
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_revision_no);
    }

    public function test_editor_approved_low_risk_revision_is_pinned_idempotently(): void
    {
        $article = $this->candidate();
        $worker = new EditorialAutoPublisher();

        $this->assertSame('published', $worker->publishDue($article->id, true, now()->subMinute()));
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->assertSame('already_published', $worker->publishDue($article->id, true, now()->subMinute()));
        $this->assertSame(1, $article->fresh()->published_revision_no);
    }

    public function test_high_unknown_or_professional_claims_never_auto_publish(): void
    {
        $worker = new EditorialAutoPublisher();
        foreach ([
            ['risk_level' => 'high'],
            ['risk_level' => 'unknown'],
            ['auto_publish_approved' => false],
            ['auto_publish_approved' => null],
            ['contains_professional_claims' => true],
            ['contains_professional_claims' => null],
        ] as $scenario) {
            $article = $this->candidate($scenario);
            $this->assertSame('requires_review', $worker->publishDue($article->id, true, now()->subMinute()));
            $this->assertSame('draft', $article->fresh()->status);
        }
    }

    public function test_missing_risk_attestation_does_not_silently_become_low_risk(): void
    {
        $article = $this->candidate([
            'risk_level' => null,
            'auto_publish_approved' => null,
            'contains_professional_claims' => null,
        ]);
        $this->assertSame('requires_review',
            (new EditorialAutoPublisher())->publishDue($article->id, true, now()->subMinute()));
        $this->assertNull($article->fresh()->published_revision_no);
    }

    public function test_failed_evidence_cannot_publish_even_with_low_risk_approval(): void
    {
        $article = $this->candidate([], false);
        $worker = new EditorialAutoPublisher();
        $this->assertSame('requires_review', $worker->publishDue($article->id, true, now()->subMinute()));
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_lost_evidence_revokes_public_revision_without_automatic_republication(): void
    {
        $article = $this->candidate();
        $article->publishVerified();
        $revision = $article->revisions()->firstOrFail();
        $revision->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
                'risk_level' => 'low',
                'auto_publish_approved' => true,
                'contains_professional_claims' => false,
            ],
        ]);
        $worker = new EditorialAutoPublisher();

        $this->assertSame('revoked', $worker->revokeInvalid($article->id));
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_revision_no);
        $this->assertSame('not_published', $worker->revokeInvalid($article->id));
        $this->assertSame('requires_review', $worker->publishDue($article->id, true, now()->subMinute()));
    }

    public function test_verified_public_article_is_not_revoked(): void
    {
        $article = $this->candidate();
        $article->publishVerified();

        $this->assertSame('still_verified',
            (new EditorialAutoPublisher())->revokeInvalid($article->id));
        $this->assertSame('published', $article->fresh()->status);
    }
}

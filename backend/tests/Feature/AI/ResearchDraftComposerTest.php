<?php

namespace Tests\Feature\AI;

use App\AI\ResearchDraftComposer;
use App\AI\ResearchSourceIntake;
use App\Models\Article;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class ResearchDraftComposerTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug = 'draft-research'): Article
    {
        return Article::create([
            'slug' => $slug,
            'title_en' => 'Database reliability',
            'title_ar' => 'موثوقية قواعد البيانات',
            'canonical_lang' => 'en',
        ]);
    }

    private function composer(): ResearchDraftComposer
    {
        return new ResearchDraftComposer(new ResearchSourceIntake());
    }

    public function test_candidates_are_saved_as_unverified_private_revisions_without_network_requests(): void
    {
        Http::fake();
        $article = $this->article();

        $revision = $this->composer()->saveDraft($article, '  ## Safe research notes  ', [
            ' HTTPS://Docs.Example.org:443/guide ',
        ]);

        $this->assertSame(1, $revision->revision_no);
        $this->assertSame('draft', $revision->status);
        $this->assertSame('## Safe research notes', $revision->body_md);
        $this->assertSame([
            ['source_url' => 'https://docs.example.org/guide', 'verified' => false],
        ], $revision->citations_json);
        $this->assertSame([
            'citations_passed' => false, 'technical_passed' => false,
        ], $revision->validation_json);
        $this->assertFalse($revision->hasVerifiedEvidence());
        $this->assertFalse($article->fresh()->isReadyToPublish());
        $this->assertNull($article->fresh()->publicRevision());
        Http::assertNothingSent();

        $this->expectException(DomainException::class);
        $article->publishVerified();
    }

    public function test_retries_are_idempotent_and_changed_content_produces_an_immutable_new_revision(): void
    {
        $article = $this->article();
        $composer = $this->composer();
        $sources = ['https://docs.example.org/guide'];

        $first = $composer->saveDraft($article, 'Research v1', $sources);
        $retry = $composer->saveDraft($article, 'Research v1', $sources);
        $second = $composer->saveDraft($article, 'Research v2', $sources);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(2, $second->revision_no);
        $this->assertSame('Research v1', $first->fresh()->body_md);
        $this->assertSame(2, $article->revisions()->count());
    }

    public function test_new_draft_does_not_overwrite_a_pinned_published_revision(): void
    {
        $article = $this->article();
        $published = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Previously reviewed publication',
            'citations_json' => [
                ['source_url' => 'https://docs.example.org/verified', 'verified' => true],
            ],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            'status' => 'validated',
        ]);
        $article->publishVerified();

        $draft = $this->composer()->saveDraft($article, 'Unreviewed follow-up', [
            'https://docs.example.org/new-source',
        ]);

        $this->assertSame(2, $draft->revision_no);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->assertSame($published->id, $article->fresh()->publicRevision()?->id);
        $this->assertFalse($article->fresh()->isReadyToPublish());
    }

    public function test_invalid_or_unbounded_input_never_creates_a_revision(): void
    {
        $article = $this->article();
        $composer = $this->composer();

        foreach ([
            ['', ['https://docs.example.org/guide']],
            [str_repeat('a', 40001), ['https://docs.example.org/guide']],
            ['Valid note', []],
            ['Valid note', ['https://127.0.0.1/internal']],
            ['Valid note', ['http://docs.example.org/guide']],
        ] as [$body, $sources]) {
            try {
                $composer->saveDraft($article, $body, $sources);
                $this->fail('Invalid research draft was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, $article->revisions()->count());
            }
        }

        $unpersisted = new Article();
        $this->expectException(InvalidArgumentException::class);
        $composer->saveDraft($unpersisted, 'A draft', ['https://docs.example.org/guide']);
    }
}

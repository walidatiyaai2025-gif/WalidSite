<?php

namespace App\AI;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Checkpoints research into an unpublished article revision.
 *
 * Candidate sources are NOT evidence. This service does not fetch URLs,
 * invoke an AI provider, validate technical claims, or publish an article.
 * Publishing still requires the independent editorial/evidence gates.
 */
final class ResearchDraftComposer
{
    private const MAX_BODY_LENGTH = 40000;

    public function __construct(private readonly ResearchSourceIntake $sourceIntake)
    {
    }

    /**
     * @param array<int, mixed> $sourceUrls
     */
    public function saveDraft(Article $article, string $bodyMarkdown, array $sourceUrls): ArticleRevision
    {
        if (! $article->exists) {
            throw new InvalidArgumentException('Research drafts require an existing article.');
        }

        $bodyMarkdown = trim($bodyMarkdown);
        if ($bodyMarkdown === '' || mb_strlen($bodyMarkdown) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('Research draft body must contain 1 to 40000 characters.');
        }

        // Validate every source before beginning a write transaction.
        // Intake returns unverified candidates only; never trust a client
        // supplied "verified" flag or an LLM assertion.
        $candidates = $this->sourceIntake->candidateCitations($sourceUrls);
        $unverified = ['citations_passed' => false, 'technical_passed' => false];

        return DB::transaction(function () use ($article, $bodyMarkdown, $candidates, $unverified): ArticleRevision {
            // Serializes revision allocation for concurrent saves to this
            // article without locking other providers/articles.
            $locked = Article::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
            $latest = $locked->revisions()->orderByDesc('revision_no')->first();

            // Safe at-least-once retry: a duplicate checkpoint is not a new
            // revision. A reviewed revision is never modified or recycled.
            if ($latest !== null
                && $latest->status === 'draft'
                && $latest->body_md === $bodyMarkdown
                && $latest->citations_json === $candidates
                && $latest->validation_json === $unverified) {
                return $latest;
            }

            return $locked->revisions()->create([
                'revision_no' => ($latest?->revision_no ?? 0) + 1,
                'body_md' => $bodyMarkdown,
                'citations_json' => $candidates,
                'validation_json' => $unverified,
                'status' => 'draft',
            ]);
        }, 3);
    }
}

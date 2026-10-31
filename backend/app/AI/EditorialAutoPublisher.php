<?php

namespace App\AI;

use App\Models\Article;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fail-closed publication transition invoked by a bounded scheduler.
 * This class does not start workers, poll cPanel or perform AI inference.
 *
 * A machine-generated risk label alone is never sufficient authorization:
 * the latest validated revision must carry explicit editorial low-risk
 * review, affirmative auto-publication approval, and an explicit finding
 * that it contains no sensitive professional claims. Missing fields block.
 */
final class EditorialAutoPublisher
{
    /**
     * Returns: disabled, not_due, requires_review, published, already_published,
     * or revoked (if a previously published revision loses its evidence).
     */
    public function publishDue(
        string $articleId,
        bool $automationEnabled,
        CarbonInterface $scheduledAt
    ): string {
        // Feature flags are caller-supplied from trusted server settings;
        // absence or disablement must not trigger a publication side effect.
        if (! $automationEnabled) {
            return 'disabled';
        }
        if ($scheduledAt->isFuture()) {
            return 'not_due';
        }

        return DB::transaction(function () use ($articleId): string {
            $article = Article::query()->whereKey($articleId)->lockForUpdate()->firstOrFail();

            if ($article->status === 'published') {
                if ($article->publicRevision() === null) {
                    $this->revoke($article);
                    return 'revoked';
                }
                return 'already_published';
            }

            if (! $article->isReadyToPublish()) {
                return 'requires_review';
            }

            $latest = $article->revisions()->orderByDesc('revision_no')->first();
            $checks = $latest?->validation_json;

            if (! is_array($checks)
                || ($checks['risk_level'] ?? null) !== 'low'
                || ($checks['auto_publish_approved'] ?? null) !== true
                || ($checks['contains_professional_claims'] ?? null) !== false) {
                return 'requires_review';
            }

            // The domain rechecks the most recent revision, public citations,
            // technical validation and the image rights before pinning it.
            $article->publishVerified();

            return 'published';
        }, 3);
    }

    /**
     * Allow a scheduled evidence audit to revoke stale public material even
     * if automatic NEW publication is disabled. Idempotent on drafts.
     */
    public function revokeInvalid(string $articleId): string
    {
        return DB::transaction(function () use ($articleId): string {
            $article = Article::query()->whereKey($articleId)->lockForUpdate()->firstOrFail();

            if ($article->status !== 'published') {
                return 'not_published';
            }
            if ($article->publicRevision() !== null) {
                return 'still_verified';
            }

            $this->revoke($article);
            return 'revoked';
        }, 3);
    }

    private function revoke(Article $article): void
    {
        $article->forceFill([
            'status' => 'draft',
            'published_at' => null,
            'published_revision_no' => null,
        ])->save();
    }
}

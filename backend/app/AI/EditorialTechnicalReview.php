<?php

namespace App\AI;

use App\Models\ArticleRevision;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Independent editorial sign-off, never a model's self-assessment.
 * Every source must be affirmed by an active owner/admin and match the exact
 * draft candidate. No URL is fetched or silently treated as evidence.
 */
final class EditorialTechnicalReview
{
    /**
     * @param list<string> $reviewedSourceUrls Exact candidate URLs independently checked by reviewer
     * @return bool true only when the reviewed revision passed all gates
     */
    public function decide(
        ArticleRevision $revision,
        User $reviewer,
        array $reviewedSourceUrls,
        bool $technicalApproved,
        bool $sourceApproved,
        bool $containsProfessionalClaims = true,
        bool $autoPublishApproved = false
    ): bool {
        if (! $reviewer->exists || $reviewer->status !== 'active'
            || ! ($reviewer->hasRole('owner') || $reviewer->hasRole('admin'))) {
            throw new DomainException('Active editorial administrator required.');
        }
        if (count($reviewedSourceUrls) > 20) {
            throw new InvalidArgumentException('Too many editorial sources.');
        }

        return DB::transaction(function () use (
            $revision, $reviewer, $reviewedSourceUrls, $technicalApproved,
            $sourceApproved, $containsProfessionalClaims, $autoPublishApproved
        ): bool {
            $lockedArticle = DB::table('articles')
                ->where('id', $revision->article_id)->lockForUpdate()->first();
            $current = ArticleRevision::query()->whereKey($revision->id)->lockForUpdate()->first();
            if (! $lockedArticle || ! $current || $current->article_id !== $lockedArticle->id) {
                throw new DomainException('Invalid editorial revision.');
            }
            $latest = ArticleRevision::query()->where('article_id', $current->article_id)
                ->orderByDesc('revision_no')->first();
            if ($latest?->id !== $current->id
                || $current->status !== 'draft') {
                throw new DomainException('Only the latest draft can receive independent review.');
            }

            $candidates = $current->citations_json;
            if (! is_array($candidates) || $candidates === [] ||
                count($candidates) !== count($reviewedSourceUrls) ||
                count($reviewedSourceUrls) !== count(array_unique($reviewedSourceUrls))) {
                throw new DomainException('Reviewed source set does not match draft candidates.');
            }
            $expected = [];
            foreach ($candidates as $citation) {
                if (! is_array($citation) || ! is_string($citation['source_url'] ?? null)) {
                    throw new DomainException('Invalid citation candidate.');
                }
                $expected[] = $citation['source_url'];
            }
            $verified = $sourceApproved && $technicalApproved;
            if (array_values($expected) !== array_values($reviewedSourceUrls)) {
                throw new DomainException('Reviewed source set does not match draft candidates.');
            }

            $citations = array_map(static fn (string $url): array => [
                'source_url' => $url,
                'verified' => $verified,
            ], $reviewedSourceUrls);

            // Save the exact reviewed URL set separately. ArticleRevision's
            // model hook deliberately invalidates any changed citations.
            $current->update(['citations_json' => $citations]);
            $current->update([
                'status' => $verified ? 'validated' : 'draft',
                'validation_json' => [
                    'citations_passed' => $sourceApproved,
                    'technical_passed' => $technicalApproved,
                    'reviewer_id' => $reviewer->id,
                    'risk_level' => $containsProfessionalClaims ? 'high' : 'low',
                    // No self-approval of professional/personal claims.
                    'contains_professional_claims' => $containsProfessionalClaims,
                    'auto_publish_approved' => $verified && ! $containsProfessionalClaims
                        && $autoPublishApproved,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ]);

            return $verified && $current->fresh()->hasVerifiedEvidence();
        }, 3);
    }
}

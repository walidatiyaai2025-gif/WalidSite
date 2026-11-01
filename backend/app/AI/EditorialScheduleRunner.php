<?php

namespace App\AI;

use App\Models\Article;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bounded cPanel-friendly publisher. No daemon, secrets, network fetches or
 * automated approval: an admin must schedule an evidence-validated LOW-risk
 * revision and explicitly enable the persisted site policy.
 */
final class EditorialScheduleRunner
{
    public function __construct(private readonly EditorialAutoPublisher $publisher)
    {
    }

    /** @return array{state:string,checked:int,published:int,review:int,refreshed:int} */
    public function run(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Editorial scheduler limit must be between 1 and 20.');
        }

        $policy = DB::table('editorial_policies')->where('id', 1)->first();
        $enabled = $policy && (bool) $policy->enabled;
        $max = min($limit, max(1, min(20, (int) ($policy?->max_per_tick ?? 10))));
        $result = Cache::lock('editorial:due-runner:v1', 120)->get(function () use ($max, $policy, $enabled): array {
            // Turning OFF new publication must NEVER cancel mandatory stale
            // content expiry. Only pending draft publication is feature gated.
            $ids = $enabled ? DB::table('editorial_schedules')
                ->where('status', 'pending')->where('due_at', '<=', now())
                ->orderBy('due_at')->orderBy('id')
                ->limit($max)->pluck('id')->all() : [];
            $result = $this->result($enabled ? 'ok' : 'disabled');
            foreach ($ids as $id) {
                $outcome = $this->publishOne($id, (int) $policy->refresh_days);
                if ($outcome !== null) {
                    ++$result['checked'];
                    if ($outcome === 'published') {
                        ++$result['published'];
                    } elseif ($outcome === 'requires_review') {
                        ++$result['review'];
                    }
                }
            }

            // One bounded refresh pass per tick. A stale publication is
            // unpublished for independent review, never auto-reapproved.
            $refreshIds = DB::table('editorial_schedules')
                ->where('status', 'published')->whereNotNull('refresh_due_at')
                ->where('refresh_due_at', '<=', now())
                ->orderBy('refresh_due_at')->orderBy('id')
                ->limit($max)->pluck('id')->all();
            foreach ($refreshIds as $id) {
                if ($this->refreshOne($id)) {
                    ++$result['refreshed'];
                }
            }
            return $result;
        });

        return $result === false ? $this->result('locked') : $result;
    }

    private function publishOne(string $id, int $refreshDays): ?string
    {
        return DB::transaction(function () use ($id, $refreshDays): ?string {
            $schedule = DB::table('editorial_schedules')->where('id', $id)->lockForUpdate()->first();
            if (! $schedule || $schedule->status !== 'pending' || CarbonImmutable::parse($schedule->due_at)->isFuture()) {
                return null;
            }
            $article = Article::query()->whereKey($schedule->article_id)->lockForUpdate()->first();
            $revision = $article?->revisions()->orderByDesc('revision_no')->first();
            $checks = $revision?->validation_json;
            $eligible = $article && $revision
                && $revision->revision_no === (int) $schedule->revision_no
                && $article->isReadyToPublish()
                && ($checks['risk_level'] ?? null) === 'low'
                && ($checks['auto_publish_approved'] ?? null) === true
                && ($checks['contains_professional_claims'] ?? null) === false;
            $outcome = $eligible
                ? $this->publisher->publishDue($article->id, true, now()->subSecond())
                : 'requires_review';
            $published = in_array($outcome, ['published', 'already_published'], true);
            $status = $published ? 'published' : 'requires_review';
            DB::table('editorial_schedules')->where('id', $id)->update([
                'status' => $status,
                'refresh_due_at' => $published ? now()->addDays($refreshDays) : null,
                'updated_at' => now(),
            ]);
            $this->event('scheduled:'.$id.':'.$status, $schedule->article_id,
                $schedule->revision_no, $schedule->approved_by, $status,
                $published ? null : 'evidence_or_risk_gate');
            return $status;
        }, 3);
    }

    private function refreshOne(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $schedule = DB::table('editorial_schedules')->where('id', $id)->lockForUpdate()->first();
            if (! $schedule || $schedule->status !== 'published' || CarbonImmutable::parse($schedule->refresh_due_at)->isFuture()) {
                return false;
            }
            // Stale content is removed from public circulation until
            // editorial review. It must not quietly inherit old approval.
            $article = Article::query()->whereKey($schedule->article_id)->lockForUpdate()->first();
            if ($article && $article->status === 'published') {
                // The old reviewed evidence must not be immediately reused
                // for manual publication without a new editorial decision.
                $reviewed = $article->revisions()
                    ->where('revision_no', $article->published_revision_no)->first();
                if ($reviewed) {
                    $checks = $reviewed->validation_json ?? [];
                    $reviewed->update([
                        'status' => 'draft',
                        'validation_json' => array_merge($checks, [
                            'citations_passed' => false, 'technical_passed' => false,
                            'auto_publish_approved' => false,
                        ]),
                    ]);
                }
                $article->forceFill([
                    'status' => 'draft', 'published_at' => null,
                    'published_revision_no' => null,
                ])->save();
            }
            DB::table('editorial_schedules')->where('id', $id)->update([
                'status' => 'requires_review', 'refresh_due_at' => null, 'updated_at' => now(),
            ]);
            $this->event('refresh:'.$id, $schedule->article_id, $schedule->revision_no,
                null, 'refresh_required', 'refresh_due');
            return true;
        }, 3);
    }

    /**
     * Persist only action, actor, revision and approved reason codes.
     * No article body, citation URL, secrets, or AI payload is logged.
     */
    public function event(
        string $key, ?string $articleId, ?int $revisionNo,
        ?string $actorId, string $action, ?string $reason = null
    ): void {
        DB::table('editorial_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'event_key' => $key,
            'article_id' => $articleId, 'revision_no' => $revisionNo,
            'actor_id' => $actorId, 'action' => $action, 'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** @return array{state:string,checked:int,published:int,review:int,refreshed:int} */
    private function result(string $state): array
    {
        return ['state' => $state, 'checked' => 0, 'published' => 0, 'review' => 0, 'refreshed' => 0];
    }
}

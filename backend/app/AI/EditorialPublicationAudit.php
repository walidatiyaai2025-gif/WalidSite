<?php

namespace App\AI;

use App\Models\Article;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Safe, checkpointed maintenance of previously published articles.
 *
 * Only revokes content whose published evidence has become invalid. It never
 * approves drafts, sends AI requests, or runs continuously on a web worker.
 */
final class EditorialPublicationAudit
{
    private const CURSOR_KEY = 'editorial:published-audit:cursor:v1';
    private const LOCK_KEY = 'editorial:published-audit:lock:v1';

    public function __construct(private readonly EditorialAutoPublisher $publisher)
    {
    }

    /**
     * Checks at most $limit published articles per invocation. A persistent
     * cache store retains the next UUID cursor so each scheduled invocation
     * advances, eventually wrapping to catch newly published lower UUIDs.
     *
     * The cursor is committed only after every item in the page succeeds.
     * If an item fails, the next run safely rechecks the same page; revocation
     * itself is idempotent and each article commits independently.
     *
     * @return array{checked:int,revoked:int,has_more:bool,locked:bool}
     */
    public function run(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Publication audit limit must be between 1 and 50.');
        }

        $result = Cache::lock(self::LOCK_KEY, 120)->get(function () use ($limit): array {
            $cursor = Cache::get(self::CURSOR_KEY);
            if (! is_string($cursor) || ! preg_match('/^[a-f0-9-]{36}$/i', $cursor)) {
                $cursor = null;
            }

            // UUID ordering and a one-item lookahead prevent an unbounded
            // scan and guarantee fair rotation, rather than rereading page 1.
            $query = Article::query()
                ->where('status', 'published')
                ->where(function ($query): void {
                    $query->whereNull('published_at')
                        ->orWhere('published_at', '<=', now());
                });
            if ($cursor !== null) {
                $query->where('id', '>', $cursor);
            }

            $ids = $query->orderBy('id')->limit($limit + 1)->pluck('id')->all();
            $hasMore = count($ids) > $limit;
            $page = array_slice($ids, 0, $limit);
            $revoked = 0;

            foreach ($page as $articleId) {
                if ($this->publisher->revokeInvalid($articleId) === 'revoked') {
                    ++$revoked;
                }
            }

            // Only advance after the complete page is checked. This cache
            // MUST be persistent on staging/production (file/redis/database).
            if ($hasMore) {
                Cache::put(self::CURSOR_KEY, end($page), now()->addDays(7));
            } else {
                Cache::forget(self::CURSOR_KEY);
            }

            return [
                'checked' => count($page),
                'revoked' => $revoked,
                'has_more' => $hasMore,
                'locked' => false,
            ];
        });

        if ($result === false) {
            return ['checked' => 0, 'revoked' => 0, 'has_more' => true, 'locked' => true];
        }

        return $result;
    }
}

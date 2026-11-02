<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MobileSavedController extends Controller
{
    private const PER_PAGE = 20;
    private const MAX_SAVES = 200;

    private function owner(Request $request): string
    {
        $user = $request->user();
        abort_unless($user !== null && $user->status === 'active'
            && $user->tokenCan('mobile'), 403);

        return (string) $user->getKey();
    }

    /**
     * The user owns a bookmark, never the right to read withdrawn material.
     * Validate resources against the exact public publication gates on every
     * read, not merely when saving. This does not fetch external source URLs.
     */
    private function resource(string $type, string $id, string $lang): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        if ($type === 'runbook') {
            $data = app(PublicRunbookController::class)->verifiedData($id);
            if ($data === null) {
                return null;
            }

            return [
                'title' => $data['title'],
                'detail_url' => route('api.mobile.solution', ['id' => $id], false),
            ];
        }

        if ($type !== 'article') {
            return null;
        }

        $article = Article::query()->publiclyVisible()->where('id', $id)->first();
        if ($article === null
            || preg_match('/\A[a-zA-Z0-9-]+\z/D', $article->slug) !== 1
            || $article->publicRevision() === null) {
            return null;
        }

        return [
            'title' => $lang === 'ar' ? $article->title_ar : $article->title_en,
            'detail_url' => route('api.public.article', ['slug' => $article->slug], false),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $lang = $input['lang'] ?? 'en';
        $page = (int) ($input['page'] ?? 1);
        $offset = ($page - 1) * self::PER_PAGE;
        $eligible = 0;
        $data = [];
        $hasMore = false;

        foreach (DB::table('saved_resources')->where('user_id', $owner)
            ->orderByDesc('saved_at')->orderBy('id')->lazy(100) as $entry) {
            $resource = $this->resource($entry->resource_type, $entry->resource_id, $lang);
            if ($resource === null) {
                continue;
            }
            if ($eligible >= $offset + self::PER_PAGE) {
                $hasMore = true;
                break;
            }
            if ($eligible >= $offset) {
                $data[] = [
                    'resource_type' => $entry->resource_type,
                    'resource_id' => $entry->resource_id,
                    'title' => $resource['title'],
                    'detail_url' => $resource['detail_url'],
                    'saved_at' => \Illuminate\Support\Carbon::parse(
                        $entry->saved_at
                    )->toIso8601String(),
                ];
            }
            $eligible++;
        }

        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $page,
            'has_more_pages' => $hasMore,
        ]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $input = $request->validate([
            'resource_type' => ['required', 'string', Rule::in(['article', 'runbook'])],
            'resource_id' => ['required', 'uuid'],
        ]);
        $type = $input['resource_type'];
        $id = $input['resource_id'];
        abort_unless($this->resource($type, $id, 'en') !== null, 404);

        // Admission is serialized per account, not per bookmark. Checking
        // count before the insert in separate autocommit statements lets two
        // devices both see 199 entries and each insert a different 200th item.
        // The user row also coordinates with account suspension/deletion.
        DB::transaction(function () use ($owner, $type, $id): void {
            $account = DB::table('users')->where('id', $owner)
                ->lockForUpdate()->first(['status']);
            abort_unless($account !== null && $account->status === 'active', 403);

            // A duplicate remains idempotent even when all 200 slots are full.
            $existing = DB::table('saved_resources')->where('user_id', $owner)
                ->where('resource_type', $type)->where('resource_id', $id)->exists();
            if ($existing) {
                return;
            }

            // Include withdrawn/hidden bookmarks: only explicit deletion
            // releases a slot, so re-publication cannot silently exceed cap.
            if (DB::table('saved_resources')->where('user_id', $owner)->count() >= self::MAX_SAVES) {
                throw ValidationException::withMessages([
                    'resource_id' => 'Saved-resource limit reached. Remove an item before saving another.',
                ]);
            }

            DB::table('saved_resources')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $owner,
                'resource_type' => $type,
                'resource_id' => $id,
                'saved_at' => now(),
            ]);
        }, 3);

        return response()->json(['data' => [
            'resource_type' => $type, 'resource_id' => $id, 'saved' => true,
        ]])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $resource_type, string $resource_id): JsonResponse
    {
        $owner = $this->owner($request);
        $removed = DB::table('saved_resources')->where('user_id', $owner)
            ->where('resource_type', $resource_type)
            ->where('resource_id', $resource_id)->delete();

        return response()->json(['data' => [
            'removed' => $removed > 0,
        ]])->header('Cache-Control', 'no-store');
    }
}

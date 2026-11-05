<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Public read-only runbooks. The presence of a DB row is NOT publication:
 * only an offline-approved, non-future review whose exact versioned bytes
 * match a SHA-256 snapshot can cross this response boundary.
 */
final class PublicRunbookController extends Controller
{
    private const PAGE_SIZE = 20;

    private function candidates(): Builder
    {
        return DB::table('runbooks as r')
            ->join('runbook_versions as v', static function ($join): void {
                $join->on('v.runbook_id', '=', 'r.id')
                    ->on('v.version', '=', 'r.version');
            })
            ->where('r.offline_ok', true)
            ->whereNotNull('r.validated_at')
            ->where('r.validated_at', '<=', now())
            // A new checksum-matching version cannot inherit a review that
            // happened BEFORE its snapshot was created.
            ->whereColumn('r.validated_at', '>=', 'v.created_at')
            ->where('r.version', '>', 0)
            ->select(
                'r.id', 'r.slug', 'r.title', 'r.body_md', 'r.version',
                'r.validated_at', 'v.body_md as reviewed_body_md',
                'v.reviewed_title', 'v.checksum',
                'v.reviewed_category_slug',
                'v.reviewed_category_name_en',
                'v.reviewed_category_name_ar'
            );
    }

    private function independentlyVerified(stdClass $row): bool
    {
        // Re-evaluate at read time. A later edit via SQL or an older validated
        // timestamp cannot silently inherit the previous review.
        if (! is_string($row->body_md)
            || trim($row->body_md) === ''
            || ! is_string($row->title)
            || trim($row->title) === ''
            // Live titles are mutable. The reviewed version must approve the
            // title too; no fallback to unsnapshotted legacy metadata.
            || ! is_string($row->reviewed_title)
            || trim($row->reviewed_title) === ''
            || ! hash_equals($row->reviewed_title, $row->title)
            || ! is_string($row->reviewed_body_md)
            || ! is_string($row->checksum)
            || ! preg_match('/^[a-f0-9]{64}$/D', $row->checksum)
            || ! hash_equals($row->reviewed_body_md, $row->body_md)) {
            return false;
        }

        return hash_equals($row->checksum, hash('sha256', $row->body_md))
            && $this->reviewedCategoryValid($row);
    }

    /**
     * A partial/malformed category cannot inherit the exact revision's
     * editorial approval. All-null legacy metadata is intentionally allowed.
     */
    private function reviewedCategoryValid(stdClass $row): bool
    {
        $slug = $row->reviewed_category_slug;
        $en = $row->reviewed_category_name_en;
        $ar = $row->reviewed_category_name_ar;
        if ($slug === null && $en === null && $ar === null) {
            return true;
        }

        return is_string($slug) && strlen($slug) <= 60
            && preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/D', $slug) === 1
            && is_string($en) && trim($en) !== ''
            && mb_strlen($en) <= 160
            && is_string($ar) && trim($ar) !== ''
            && mb_strlen($ar) <= 160
            && preg_match('/[\\x00-\\x1F\\x7F]/', $en) !== 1
            && preg_match('/[\\x00-\\x1F\\x7F]/', $ar) !== 1;
    }

    /** @return ?array{slug:string,name:string} */
    private function reviewedCategory(stdClass $row, string $locale): ?array
    {
        if ($row->reviewed_category_slug === null) {
            return null;
        }

        return [
            'slug' => $row->reviewed_category_slug,
            'name' => $locale === 'ar'
                ? $row->reviewed_category_name_ar
                : $row->reviewed_category_name_en,
        ];
    }

    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            // Never publish unsnapshotted mutable metadata as a verified
            // title or invent a translation for the requested locale.
            'title' => $row->reviewed_title,
            'markdown' => $row->body_md,
            'version' => (int) $row->version,
            'validated_at' => \Illuminate\Support\Carbon::parse(
                $row->validated_at
            )->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'lang' => ['sometimes', 'in:ar,en'],
            'category' => [
                'sometimes', 'string', 'max:60',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            ],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $locale = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $selectedCategory = $input['category'] ?? null;
        $offset = ($page - 1) * self::PAGE_SIZE;
        $eligible = 0;
        $data = [];
        $hasMore = false;
        $categories = [];

        // The source of each category is the exact, currently approved
        // version. Scan all independently verified guides so filter choices
        // are not confined to the first page, and never reveal draft-only
        // categories. Filter BEFORE offset accounting, not in Flutter.
        // lazy(100) caps DB memory; the result retains only page slots and
        // distinct reviewed category labels, no unreleased document bodies.
        foreach ($this->candidates()->orderBy('r.slug')->orderBy('r.id')->lazy(100) as $row) {
            if (! $this->independentlyVerified($row)) {
                continue;
            }
            $category = $this->reviewedCategory($row, $locale);
            if ($category !== null) {
                $categories[$category['slug']] ??= $category;
            }
            if ($selectedCategory !== null && ($category['slug'] ?? null) !== $selectedCategory) {
                continue;
            }
            if ($eligible >= $offset + self::PAGE_SIZE) {
                $hasMore = true;
                continue;
            }
            if ($eligible >= $offset) {
                $data[] = $this->serialize($row);
            }
            $eligible++;
        }
        ksort($categories, SORT_STRING);

        // Approval and rights may be revoked after this request. An HTTP
        // intermediary must never replay a previously reviewed runbook.
        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'has_more_pages' => $hasMore,
                'categories' => array_values($categories),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Reuse exactly the public read gate for the authenticated user's saved
     * library; a once-approved entry is no longer public after revocation.
     * Returns no private version history, storage key or unreviewed title.
     */
    public function verifiedData(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = $this->candidates()->where('r.id', $id)->first();
        return $row !== null && $this->independentlyVerified($row)
            ? $this->serialize($row) : null;
    }

    public function show(string $id): JsonResponse
    {
        // UUID validation is also enforced by the route. No sequential IDs,
        // slugs or user-supplied SQL parameters are allowed for detail reads.
        $data = $this->verifiedData($id);
        abort_unless($data !== null, 404);

        // A later withdrawal must take effect on the very next API read.
        return response()->json(['data' => $data])
            ->header('Cache-Control', 'no-store');
    }
}

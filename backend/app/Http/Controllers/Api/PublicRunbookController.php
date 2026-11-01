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
            ->where('r.version', '>', 0)
            ->select(
                'r.id', 'r.slug', 'r.title', 'r.body_md', 'r.version',
                'r.validated_at', 'v.body_md as reviewed_body_md', 'v.checksum'
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
            || ! is_string($row->reviewed_body_md)
            || ! is_string($row->checksum)
            || ! preg_match('/^[a-f0-9]{64}$/D', $row->checksum)
            || ! hash_equals($row->reviewed_body_md, $row->body_md)) {
            return false;
        }

        return hash_equals($row->checksum, hash('sha256', $row->body_md));
    }

    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            // The schema stores the reviewed title in its original language.
            // Never manufacture a translation based on an untrusted locale.
            'title' => $row->title,
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
        ]);
        $page = (int) ($input['page'] ?? 1);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $eligible = 0;
        $data = [];
        $hasMore = false;

        // Pagination happens AFTER immutable version and current checksum
        // validation so revoked or edited rows cannot leave sparse pages or
        // reveal their existence in has_more_pages. Chunked traversal bounds
        // memory and stops once one more eligible entry has been established.
        foreach ($this->candidates()->orderBy('r.slug')->orderBy('r.id')->lazy(100) as $row) {
            if (! $this->independentlyVerified($row)) {
                continue;
            }
            if ($eligible >= $offset + self::PAGE_SIZE) {
                $hasMore = true;
                break;
            }
            if ($eligible >= $offset) {
                $data[] = $this->serialize($row);
            }
            $eligible++;
        }

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $page, 'has_more_pages' => $hasMore],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        // UUID validation is also enforced by the route. No sequential IDs,
        // slugs or user-supplied SQL parameters are allowed for detail reads.
        abort_unless(Str::isUuid($id), 404);
        $row = $this->candidates()->where('r.id', $id)->first();
        abort_unless($row !== null && $this->independentlyVerified($row), 404);

        return response()->json(['data' => $this->serialize($row)]);
    }
}

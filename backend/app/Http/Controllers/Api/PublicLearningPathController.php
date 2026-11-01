<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;

/**
 * MOBILE-08: anonymous, read-only learning catalog.
 * "Published" is the only approved visibility state in the v6 learning schema.
 * Never expose the separate per-user learning_progress table or arbitrary
 * admin-only metadata that might be present in an outline JSON document.
 */
final class PublicLearningPathController extends Controller
{
    private const PAGE_SIZE = 20;

    /**
     * @return array{id:string,slug:string,title:string,outline:array<int,array<string,string>>}|null
     */
    private function publicData(stdClass $row): ?array
    {
        if (! is_string($row->id)
            || ! is_string($row->slug)
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,148}[a-z0-9])?$/D', $row->slug) !== 1
            || ! is_string($row->title)
            || trim($row->title) === ''
            || mb_strlen($row->title) > 220
            || ! is_string($row->outline_json)
            || strlen($row->outline_json) > 65536) {
            return null;
        }

        try {
            $outline = json_decode($row->outline_json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($outline) || ! array_is_list($outline)
            || count($outline) < 1 || count($outline) > 40) {
            return null;
        }

        $lessons = [];
        foreach ($outline as $lesson) {
            if (! is_array($lesson)
                || array_is_list($lesson)
                || ! isset($lesson['title'])
                || ! is_string($lesson['title'])
                || trim($lesson['title']) === ''
                || mb_strlen($lesson['title']) > 220
                || array_diff(array_keys($lesson), ['title', 'description']) !== []) {
                return null;
            }
            $description = $lesson['description'] ?? '';
            if (! is_string($description) || mb_strlen($description) > 1500) {
                return null;
            }

            $lessons[] = [
                'title' => $lesson['title'],
                'description' => $description,
            ];
        }

        return [
            'id' => $row->id,
            'slug' => $row->slug,
            'title' => $row->title,
            'outline' => $lessons,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'lang' => ['sometimes', 'in:ar,en'],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $seen = 0;
        $data = [];
        $hasMore = false;

        // A malformed or private-content outline cannot consume a page slot
        // or leak a result through the continuation flag. Lazy SQL chunking
        // keeps memory bounded without a one-page SQL prefilter mistake.
        foreach (DB::table('learning_paths')
            ->where('published', true)
            ->orderBy('slug')->orderBy('id')->lazy(100) as $row) {
            $public = $this->publicData($row);
            if ($public === null) {
                continue;
            }
            if ($seen >= $offset + self::PAGE_SIZE) {
                $hasMore = true;
                break;
            }
            if ($seen >= $offset) {
                $data[] = $public;
            }
            $seen++;
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'has_more_pages' => $hasMore,
            ],
        ]);
    }
}

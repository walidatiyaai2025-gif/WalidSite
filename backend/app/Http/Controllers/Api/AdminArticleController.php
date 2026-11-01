<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADMIN-02: bounded, status-only metadata for the authenticated content
 * editor. Draft contents, citations and private workflow inputs never leave
 * this read-only listing.
 */
final class AdminArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        // Sanctum may accept a first-party session. A content management API
        // still requires a genuine, admin-scoped bearer and active admin role.
        abort_unless(
            $user?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin'),
            403
        );

        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'status' => ['sometimes', 'in:draft,published'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $lang = $input['lang'] ?? 'en';

        $query = Article::query()->select([
            'id', 'slug', 'title_en', 'title_ar', 'status',
            'published_revision_no', 'published_at', 'created_at',
        ]);
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }

        $page = $query->orderByDesc('created_at')->orderBy('id')->paginate(20);
        return response()->json([
            'data' => $page->getCollection()->map(static fn (Article $article): array => [
                'id' => $article->id,
                'slug' => $article->slug,
                'title' => $lang === 'ar' ? $article->title_ar : $article->title_en,
                'status' => $article->status,
                'published_revision_no' => $article->published_revision_no,
                'published_at' => $article->published_at?->toIso8601String(),
                'created_at' => $article->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'lang' => $lang,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                // Database published state can outlive revoked citation
                // evidence; it is NOT a verified public-visibility count.
                'status_is_not_public_evidence_verification' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}

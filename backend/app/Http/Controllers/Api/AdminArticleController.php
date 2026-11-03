<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADMIN-02: bounded, status-only metadata for the authenticated content
 * editor. Draft contents, citations and private workflow inputs never leave
 * this read-only listing.
 */
final class AdminArticleController extends Controller
{
    /**
     * Canonical adminCreateArticle: create an unreviewed research brief only.
     *
     * Even an "auto_eligible" preference is NOT a publication authorization.
     * Topic and review metadata stay in the private draft revision and are
     * never delivered by the public article API or the admin list projection.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless(
            $user?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin'),
            403
        );

        if (array_diff(array_keys($request->all()), [
            'title', 'topic', 'language', 'publication_mode',
        ]) !== []) {
            throw ValidationException::withMessages([
                'request' => 'Unsupported request fields.',
            ]);
        }

        $input = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:250'],
            'topic' => ['required', 'string', 'min:1', 'max:2000'],
            'language' => ['required', 'string', 'in:ar,en'],
            'publication_mode' => ['required', 'string', 'in:draft,auto_eligible'],
        ]);

        $article = DB::transaction(static function () use ($input, $user): Article {
            // Use a collision-resistant internal slug; neither a fake English
            // translation nor an unreviewed research topic is made public.
            $article = Article::create([
                'slug' => 'editorial-draft-'.Str::uuid(),
                'title_en' => $input['language'] === 'en' ? trim($input['title']) : '',
                'title_ar' => $input['language'] === 'ar' ? trim($input['title']) : '',
                'canonical_lang' => $input['language'],
            ]);
            $revision = $article->revisions()->make([
                'revision_no' => 1,
                'body_md' => '',
                'citations_json' => [],
                'validation_json' => [
                    'research_topic' => trim($input['topic']),
                    'requested_publication_mode' => $input['publication_mode'],
                    'citations_passed' => false,
                    'technical_passed' => false,
                    'auto_publish_approved' => false,
                ],
                'status' => 'draft',
            ]);
            $revision->forceFill([
                'author_user_id' => $user->id,
                'last_editor_user_id' => $user->id,
            ])->save();

            return $article;
        }, 3);

        return response()->json([
            'data' => [
                'id' => $article->id,
                'slug' => $article->slug,
                'title' => $input['title'],
                'canonical_lang' => $article->canonical_lang,
                'status' => 'draft',
                'revision_no' => 1,
                'publication_mode_requested' => $input['publication_mode'],
                'requires_editorial_review' => true,
                'auto_publication_started' => false,
            ],
        ])->header('Cache-Control', 'no-store');
    }

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

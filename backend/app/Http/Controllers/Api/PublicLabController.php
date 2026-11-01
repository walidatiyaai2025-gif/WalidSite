<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicLabController extends Controller
{
    /**
     * WEB-09 Tech Lab: no separate, approved executable-tool registry exists.
     * Return an honest empty tools catalog plus the already verified article
     * and version-pinned runbook resources, never draft or invented content.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['prohibited'],
        ]);
        $lang = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';

        $articles = [];
        // SQL status is only a candidate filter; every pinned revision must
        // still pass current citation, editorial and hero-image rights checks.
        // Apply the 15-resource cap AFTER those checks, not before.
        foreach (Article::query()->publiclyVisible()
            ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
            if (preg_match('/\A[A-Za-z0-9-]+\z/D', $article->slug) !== 1
                || $article->publicRevision() === null) {
                continue;
            }

            $articles[] = [
                'slug' => $article->slug,
                'title' => $lang === 'ar' ? $article->title_ar : $article->title_en,
                'url' => route('public.article', ['slug' => $article->slug, 'lang' => $lang]),
                'published_at' => $article->published_at?->toIso8601String(),
            ];
            if (count($articles) === 15) {
                break;
            }
        }

        // Delegate to the exact existing immutable-snapshot / SHA-256 gate.
        // A new runbook query must never weaken review or expose history.
        // The public lab displays at most 15 of its 20 reviewed first-page
        // entries. No networking, signing or publication is done by this API.
        $verifiedRunbooks = app(PublicRunbookController::class)
            ->index($request)->getData(true)['data'] ?? [];

        return response()->json([
            'data' => [
                'tools' => [],
                'articles' => $articles,
                'runbooks' => array_slice($verifiedRunbooks, 0, 15),
            ],
            'meta' => ['lang' => $lang],
        ])->header('Cache-Control', 'no-store');
    }
}

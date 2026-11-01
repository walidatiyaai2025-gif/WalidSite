<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicHomeController extends Controller
{
    /**
     * WEB-01: the app/API landing is a read-only view of real public evidence,
     * not a source of invented CV claims, project numbers or draft articles.
     * Publication and proof policies deliberately match the public website.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
        ]);
        $lang = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $portfolio = app(PublicPortfolioController::class);

        // Reuse the existing single source of truth for the active owner's
        // published profile and individually approved, URL-safe CV claims.
        $profile = $portfolio->profile($request)->getData(true)['data'] ?? null;

        $projects = [];
        foreach (Project::query()->publiclyVerified()
            ->with(['evidence' => static fn ($evidence) => $evidence
                ->where('visibility', 'public')
                ->whereNotNull('verified_at')
                ->where('verified_at', '<=', now())
                ->orderBy('id')->limit(50)])
            ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $project) {
            if (preg_match('/\A[A-Za-z0-9-]+\z/D', $project->slug) !== 1) {
                continue;
            }
            $data = $portfolio->publicProjectData($project, $request);
            if (empty($data['evidence_urls'])) {
                continue;
            }
            $projects[] = [
                'slug' => $data['slug'],
                'title' => $data['name'],
                'summary' => $data['summary'],
                'role' => $data['role'],
                'evidence_urls' => $data['evidence_urls'],
                'url' => route('public.project', ['slug' => $data['slug'], 'lang' => $lang]),
            ];
            if (count($projects) === 3) {
                break;
            }
        }

        $articles = [];
        foreach (Article::query()->publiclyVisible()
            ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
            // The SQL publication scope is not final citation/media approval.
            // Reject revoked proof and any invalid route slug BEFORE the cap.
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
            if (count($articles) === 3) {
                break;
            }
        }

        return response()->json([
            'data' => [
                'profile' => $profile,
                'featured_projects' => $projects,
                'latest_articles' => $articles,
            ],
            'meta' => ['lang' => $lang],
        ])->header('Cache-Control', 'no-store');
    }
}

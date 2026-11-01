<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSearchController extends Controller
{
    /**
     * WEB-13: search only records that pass the live public evidence gates.
     * The SQL candidate scope is intentionally weaker than publicRevision()
     * and publicProjectData(): never limit it before checking those gates.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'q' => ['sometimes', 'string', 'max:80'],
            'lang' => ['sometimes', 'in:en,ar'],
        ]);
        $search = trim($input['q'] ?? '');
        $lang = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
        $articles = [];
        $projects = [];

        if ($search !== '') {
            // ! is the explicit ESCAPE character on SQLite and MariaDB.
            // Escaping these metacharacters preserves literal user searches.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

            foreach (Article::query()->publiclyVisible()
                ->where(static fn ($query) => $query
                    ->whereRaw("articles.title_en LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("articles.title_ar LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('revisions', static fn ($revision) => $revision
                        ->where('status', 'validated')
                        ->whereColumn('article_revisions.revision_no', 'articles.published_revision_no')
                        ->whereRaw("article_revisions.body_md LIKE ? ESCAPE '!'", [$term])))
                ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
                if (preg_match('/\A[A-Za-z0-9-]+\z/D', $article->slug) !== 1
                    || $article->publicRevision() === null) {
                    continue;
                }

                $articles[] = [
                    'slug' => $article->slug,
                    'title' => $lang === 'ar' ? $article->title_ar : $article->title_en,
                    'url' => route('public.article', ['slug' => $article->slug, 'lang' => $lang]),
                ];
                if (count($articles) === 10) {
                    break;
                }
            }

            $portfolio = app(PublicPortfolioController::class);
            foreach (Project::query()->publiclyVerified()
                ->where(static fn ($query) => $query
                    ->whereRaw("projects.title LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("projects.summary_en LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("projects.summary_ar LIKE ? ESCAPE '!'", [$term]))
                // Per-batch eager loading preserves the same 50-row proof
                // budget as the public portfolio endpoint, not N+1 lookups.
                ->with(['evidence' => static fn ($evidence) => $evidence
                    ->where('visibility', 'public')
                    ->whereNotNull('verified_at')
                    ->where('verified_at', '<=', now())
                    ->orderBy('id')->limit(50)])
                ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $project) {
                if (preg_match('/\A[A-Za-z0-9-]+\z/D', $project->slug) !== 1) {
                    continue;
                }
                $verified = $portfolio->publicProjectData($project, $request);
                if (empty($verified['evidence_urls'])) {
                    continue;
                }

                $projects[] = [
                    'slug' => $verified['slug'],
                    'title' => $verified['name'],
                    'summary' => $verified['summary'],
                    'url' => route('public.project', ['slug' => $verified['slug'], 'lang' => $lang]),
                ];
                if (count($projects) === 10) {
                    break;
                }
            }
        }

        return response()->json([
            'data' => [
                'articles' => $articles,
                'projects' => $projects,
            ],
            'meta' => [
                'lang' => $lang,
                'per_type_limit' => 10,
            ],
        ]);
    }
}

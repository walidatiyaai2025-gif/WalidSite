<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'q' => ['sometimes', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $locale = ($query['lang'] ?? '') === 'ar' ? 'ar' : 'en';
        $search = trim($query['q'] ?? '');

        $articles = Article::query()->publiclyVisible();
        if ($search !== '') {
            $articles->where(function ($q) use ($search): void {
                $q->where('title_en', 'like', '%'.$search.'%')
                    ->orWhere('title_ar', 'like', '%'.$search.'%');
            });
        }

        // Do not return data until the pinned revision and its citations pass
        // a live evidence check, even if a persisted status was changed later.
        $page = $articles->orderByDesc('published_at')->orderBy('id')->simplePaginate(12);
        $data = [];
        foreach ($page->items() as $article) {
            $revision = $article->publicRevision();
            if ($revision instanceof ArticleRevision) {
                $data[] = $this->serialize($article, $revision, $locale);
            }
        }

        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $page->currentPage(),
            'has_more_pages' => $page->hasMorePages(),
        ]]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $query = $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        $article = Article::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();
        $revision = $article->publicRevision();
        abort_unless($revision instanceof ArticleRevision, 404);

        return response()->json(['data' => $this->serialize(
            $article,
            $revision,
            ($query['lang'] ?? '') === 'ar' ? 'ar' : 'en'
        )]);
    }

    private function serialize(Article $article, ArticleRevision $revision, string $lang): array
    {
        return [
            'id' => $article->id,
            'slug' => $article->slug,
            'title' => $lang === 'ar' ? $article->title_ar : $article->title_en,
            'body_markdown' => $revision->body_md,
            'citations' => array_values(array_map(
                static fn (array $citation): string => $citation['source_url'],
                $revision->citations_json
            )),
            // Validation of a reference is not evidence of a real execution test.
            'tested_status' => 'documented',
            // No public signed-media endpoint is implemented yet. Never expose
            // raw storage_key, guessed CDN URLs, or private object locations.
            'hero_image_url' => null,
            'published_at' => $article->published_at?->toIso8601String(),
        ];
    }
}

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
            // Explicit LIKE escape is needed on both SQLite (CI) and
            // MariaDB (production). Backslash without ESCAPE is not portable.
            // Bind the pattern; never interpolate untrusted text into SQL.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $articles->where(function ($q) use ($term): void {
                $q->whereRaw("articles.title_en LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("articles.title_ar LIKE ? ESCAPE '!'", [$term])
                    // Match the exact revision pinned at publication, never
                    // a newer draft or an unrelated validated revision.
                    // The definitive publicRevision() rights/citation gate
                    // still runs before result slots or next-page metadata.
                    ->orWhereHas('revisions', fn ($revision) => $revision
                        ->where('status', 'validated')
                        ->whereColumn('article_revisions.revision_no', 'articles.published_revision_no')
                        ->whereRaw("article_revisions.body_md LIKE ? ESCAPE '!'", [$term]));
            });
        }

        $perPage = 12;
        $currentPage = (int) ($query['page'] ?? 1);
        $offset = ($currentPage - 1) * $perPage;
        $eligibleSeen = 0;
        $data = [];
        $hasMorePages = false;

        // The SQL publication scope is a fast first pass, not proof of safe
        // citations/media: publicRevision() performs the definitive live
        // rights/source verification. Apply that check BEFORE page offsets
        // and before computing has_more_pages to avoid sparse pages and
        // exposing the existence of hidden/invalid publications.
        // lazy() loads only 100 candidates at a time; stop as soon as the
        // next eligible article proves there is another public page.
        foreach ($articles->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
            // A reviewed article can have a legacy slug that the public and
            // mobile detail route cannot resolve. Never let such records
            // consume a page slot or advertise a phantom next page.
            if (preg_match('/\\A[A-Za-z0-9-]+\\z/D', $article->slug) !== 1) {
                continue;
            }

            $revision = $article->publicRevision();
            if (! $revision instanceof ArticleRevision) {
                continue;
            }

            if ($eligibleSeen >= $offset + $perPage) {
                $hasMorePages = true;
                break;
            }
            if ($eligibleSeen >= $offset) {
                $data[] = $this->serialize($article, $revision, $locale);
            }
            $eligibleSeen++;
        }

        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $currentPage,
            'has_more_pages' => $hasMorePages,
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

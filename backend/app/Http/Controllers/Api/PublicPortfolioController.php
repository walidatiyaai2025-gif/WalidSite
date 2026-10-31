<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicPortfolioController extends Controller
{
    public function projects(Request $request): JsonResponse
    {
        $page = Project::query()->publiclyVerified()->orderByDesc('published_at')
            ->orderBy('id')->paginate(12);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Project $project) => $this->projectData($project, $request))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function project(Request $request, string $slug): JsonResponse
    {
        $project = Project::query()->publiclyVerified()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $this->projectData($project, $request)]);
    }

    public function profile(Request $request): JsonResponse
    {
        // An owner-imported CV baseline is "review", never public by default.
        $profile = Profile::query()->where('publish_status', 'published')->first();
        if (! $profile) {
            return response()->json(['data' => null]);
        }

        $claims = ProfessionalClaim::query()->publiclyApproved()->where('profile_id', $profile->id)
            ->orderBy('id')->get()->filter(fn (ProfessionalClaim $claim) => $this->safeEvidenceUrl($claim->evidence_url));

        $ar = $request->query('lang') === 'ar';

        return response()->json(['data' => [
            'id' => $profile->id,
            'name' => $profile->public_name,
            // Bio and credentials remain withheld pending external evidence and editorial approval.
            'claims' => $claims->map(fn (ProfessionalClaim $claim) => [
                'id' => $claim->id,
                'text' => $ar ? $claim->claim_ar : $claim->claim_en,
                'evidence_url' => $claim->evidence_url,
            ])->values()->all(),
        ]]);
    }

    private function projectData(Project $project, Request $request): array
    {
        $ar = $request->query('lang') === 'ar';
        $urls = $project->evidence()
            ->where('visibility', 'public')
            ->whereNotNull('verified_at')
            ->where('verified_at', '<=', now())
            ->orderBy('id')->limit(50)->pluck('url')
            ->filter(fn ($url) => $this->safeEvidenceUrl($url))
            ->unique()->values()->all();

        return [
            'id' => $project->id,
            'slug' => $project->slug,
            'name' => $project->title,
            'summary' => $ar ? $project->summary_ar : $project->summary_en,
            'role' => $ar && $project->role_ar ? $project->role_ar : $project->role_en,
            'status' => $project->status,
            'evidence_urls' => $urls,
            // GitHub links must pass T017's public allowlist verification before
            // display. A UUID alone is not proof of public visibility.
            'public_repo_url' => null,
        ];
    }

    private function safeEvidenceUrl(?string $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || ! str_starts_with($url, 'https://')) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            // Repository visibility is checked only by T017, not by this evidence flag.
            && ! in_array(strtolower($parts['host']), ['github.com', 'www.github.com'], true);
    }
}

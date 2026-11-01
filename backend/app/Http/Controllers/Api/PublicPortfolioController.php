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
        // Do not silently reinterpret malformed or extreme pagination input.
        // A public API consumer must see a validation error rather than a
        // different page or an unbounded offset. The same locale contract
        // applies to public expertise and the browser-facing listings.
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'lang' => ['sometimes', 'in:ar,en'],
        ]);
        $perPage = 12;
        $currentPage = (int) ($input['page'] ?? 1);
        $offset = ($currentPage - 1) * $perPage;
        $visible = [];
        $total = 0;

        // Filtering AFTER SQL pagination leaks the number of projects whose
        // evidence is unsafe and produces empty pages ahead of valid projects.
        // The URL policy is deliberately checked in PHP (not a DB-specific
        // regex) so SQLite and MariaDB produce the same exact pagination.
        // lazy() holds only one small project chunk in memory; no remote
        // evidence URL is ever fetched.
        foreach (Project::query()->publiclyVerified()
            // Laravel eager-loads each 100-row lazy() chunk in one query.
            // Keep the exact same per-project limit used by detail results,
            // rather than issuing one evidence query for every candidate.
            ->with(['evidence' => static fn ($evidence) => $evidence
                ->where('visibility', 'public')
                ->whereNotNull('verified_at')
                ->where('verified_at', '<=', now())
                ->orderBy('id')
                ->limit(50)])
            ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $project) {
            $data = $this->publicProjectData($project, $request);
            if (empty($data['evidence_urls'])) {
                continue;
            }

            if ($total >= $offset && count($visible) < $perPage) {
                $visible[] = $data;
            }
            $total++;
        }

        return response()->json([
            'data' => $visible,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
            ],
        ]);
    }

    public function project(Request $request, string $slug): JsonResponse
    {
        $project = Project::query()->publiclyVerified()->where('slug', $slug)->firstOrFail();
        $data = $this->publicProjectData($project, $request);
        abort_if(empty($data['evidence_urls']), 404);

        return response()->json(['data' => $data]);
    }

    public function profile(Request $request): JsonResponse
    {
        // Publishing a record is not authorization to represent the site owner.
        // Prevent unrelated or deactivated accounts from leaking public CV claims.
        $profile = Profile::query()->published()
            ->whereHas('user', fn ($user) => $user->where('status', 'active')
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
            ->first();
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

    /**
     * WEB-03 contract: an independently consumable public expertise feed.
     * A verified skill is only publishable under the active owner's published
     * profile; other users' CV data and unreviewed rows never enter the feed.
     */
    public function expertise(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'category' => ['sometimes', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $empty = static fn () => response()->json([
            'data' => [],
            'meta' => ['current_page' => $page, 'last_page' => 1, 'total' => 0],
        ]);

        $profile = Profile::query()->published()
            ->whereHas('user', fn ($user) => $user->where('status', 'active')
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
            ->first();
        if ($profile === null) {
            return $empty();
        }

        $query = \App\Models\Skill::query()->withVerifiedEvidence()
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('profile_skill')
                ->where('profile_id', $profile->id)->select('skill_id'));
        if (isset($input['category']) && $input['category'] !== '') {
            $query->where('category', $input['category']);
        }

        $skills = $query->orderBy('category')->orderBy('name_en')->orderBy('id')
            ->paginate(25, ['*'], 'page', $page);
        $arabic = ($input['lang'] ?? 'en') === 'ar';
        return response()->json([
            'data' => $skills->getCollection()->map(static fn (\App\Models\Skill $skill) => [
                'slug' => $skill->slug,
                'name' => $arabic ? $skill->name_ar : $skill->name_en,
                'category' => $skill->category,
            ])->values()->all(),
            'meta' => [
                'current_page' => $skills->currentPage(),
                'last_page' => $skills->lastPage(),
                'total' => $skills->total(),
            ],
        ]);
    }

    /** Shared verified DTO serializer for public web cards and API responses.
     * Callers must apply publiclyVerified() and discard empty evidence lists.
     */
    public function publicProjectData(Project $project, Request $request): array
    {
        $ar = $request->query('lang') === 'ar';
        // List results use the bounded eager-loaded evidence for their chunk.
        // Detail requests are not eager loaded and retain the original guarded
        // 50-row query. Both paths apply the same URL safety policy below.
        // An eager-loaded relationship is not proof of permission to disclose.
        // Other callers may have loaded *all* evidence (including private,
        // revoked or future-dated records). Apply the same publication gate
        // before exposing URLs, even when no further SQL query is required.
        $evidenceUrls = $project->relationLoaded('evidence')
            ? $project->evidence
                ->filter(static fn ($evidence) => $evidence->visibility === 'public'
                    && $evidence->verified_at !== null
                    && $evidence->verified_at->lessThanOrEqualTo(now()))
                ->sortBy('id')->take(50)->pluck('url')
            : $project->evidence()
                ->where('visibility', 'public')
                ->whereNotNull('verified_at')
                ->where('verified_at', '<=', now())
                ->orderBy('id')->limit(50)->pluck('url');

        $urls = $evidenceUrls
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
            && $this->safePublicHostname($parts['host'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            // Repository visibility is checked only by T017, not by this evidence flag.
            && ! in_array(strtolower($parts['host']), ['github.com', 'www.github.com'], true);
    }

    /**
     * Public references are hyperlinks, not network fetch targets. Reject
     * loopback, IP literals, and internal-only names without DNS lookups.
     * DNS/redirect egress policy belongs to any later active fetcher.
     */
    private function safePublicHostname(string $hostname): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        if (filter_var(trim($hostname, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if (preg_match('/\\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}\\z/iD', $hostname) !== 1) {
            return false;
        }

        // Internal resolver zones can resolve to private/LAN services even when
        // they look like valid public HTTPS hostnames. This validation is for
        // displaying public proof links; no DNS lookup or network fetch occurs.
        foreach (['localhost', 'local', 'localdomain', 'internal', 'svc', 'lan', 'home', 'home.arpa', 'invalid', 'onion'] as $suffix) {
            if ($hostname === $suffix || str_ends_with($hostname, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }
}

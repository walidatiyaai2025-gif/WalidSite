<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubAllowlistProposal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

final class AdminGithubProposalController extends Controller
{
    private function actor(Request $request, bool $owner = false, bool $lock = false): User
    {
        $initial = $request->user();
        $token = $initial?->currentAccessToken();
        abort_unless($initial instanceof User && $token instanceof PersonalAccessToken
            && $token->can('admin'), 403);

        $query = User::query()->whereKey($initial->id);
        $actor = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($actor?->canAccessAdmin()
            && (! $owner || $actor->hasRole('owner'))
            && $actor->tokens()->whereKey($token->getKey())
                ->where(function ($q): void {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })->exists(), 403);

        return $actor;
    }

    private function onlyFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unsupported fields.']);
        }
    }

    private function output(GithubAllowlistProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'repository_slug' => $proposal->repository_slug,
            'status' => $proposal->status,
            'submitted_at' => $proposal->created_at?->toIso8601String(),
            'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
            'verified_at' => $proposal->verified_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->actor($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'status' => ['sometimes', 'in:pending,approved,rejected'],
        ]);
        $page = GithubAllowlistProposal::query()
            ->when(isset($input['status']), fn ($q) => $q->where('status', $input['status']))
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(20, ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => array_map($this->output(...), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $this->actor($request);
        $this->onlyFields($request, ['repository_slug']);
        $input = $request->validate([
            'repository_slug' => [
                'required', 'string', 'max:140',
                'regex:/\\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\\/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}\\z/D',
            ],
        ]);
        $slug = strtolower($input['repository_slug']);
        if (str_contains($slug, '..') || str_ends_with($slug, '.')) {
            throw ValidationException::withMessages(['repository_slug' => 'Invalid repository slug.']);
        }

        $proposal = DB::transaction(function () use ($request, $slug) {
            $actor = $this->actor($request, lock: true);
            abort_if(GithubAllowlistProposal::query()->where('repository_slug', $slug)->exists(),
                409, 'Repository already proposed.');
            return GithubAllowlistProposal::create([
                'repository_slug' => $slug,
                'submitted_by_user_id' => $actor->id,
                'status' => 'pending',
            ]);
        }, 3);

        return response()->json(['data' => $this->output($proposal)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $this->actor($request, owner: true);
        $this->onlyFields($request, ['decision']);
        $input = $request->validate(['decision' => ['required', 'in:approve,reject']]);

        $proposal = DB::transaction(function () use ($request, $id, $input) {
            $owner = $this->actor($request, owner: true, lock: true);
            $proposal = GithubAllowlistProposal::query()->whereKey($id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($proposal->status === 'pending', 409);
            abort_if($proposal->submitted_by_user_id === $owner->id, 403,
                'Independent owner review required.');

            if ($input['decision'] === 'approve') {
                // Construct the only permitted outbound destination from a
                // validated canonical slug. No token, custom URL or redirect.
                try {
                    $reply = Http::acceptJson()->withHeaders([
                        'User-Agent' => 'WalidSite-Github-Allowlist-Review',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])->connectTimeout(3)->timeout(5)
                        ->withOptions(['allow_redirects' => false])
                        ->get('https://api.github.com/repos/'.$proposal->repository_slug);
                    if (! $reply->ok()
                        || $reply->json('private') !== false
                        || $reply->json('visibility') !== 'public'
                        || ! is_string($reply->json('full_name'))
                        || strcasecmp($reply->json('full_name'), $proposal->repository_slug) !== 0
                        || $reply->json('archived') === true) {
                        abort(409, 'Public repository verification failed.');
                    }
                    $url = $reply->json('html_url');
                    $parts = is_string($url) ? parse_url($url) : false;
                    if (! is_array($parts)
                        || strtolower($parts['scheme'] ?? '') !== 'https'
                        || strtolower($parts['host'] ?? '') !== 'github.com'
                        || strtolower(trim($parts['path'] ?? '', '/')) !== $proposal->repository_slug
                        || isset($parts['user'], $parts['pass'])
                        || isset($parts['port']) || isset($parts['query'])
                        || isset($parts['fragment'])) {
                        abort(409, 'Public repository verification failed.');
                    }
                } catch (\Illuminate\Http\Client\ConnectionException) {
                    abort(409, 'Public repository verification unavailable.');
                }
                $proposal->verified_at = now('UTC');
                $proposal->status = 'approved';
            } else {
                $proposal->status = 'rejected';
            }
            $proposal->reviewed_by_user_id = $owner->id;
            $proposal->reviewed_at = now('UTC');
            $proposal->save();
            return $proposal;
        }, 3);

        return response()->json(['data' => $this->output($proposal)])
            ->header('Cache-Control', 'no-store, private');
    }
}

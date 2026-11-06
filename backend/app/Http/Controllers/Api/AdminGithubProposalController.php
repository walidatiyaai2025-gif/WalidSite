<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubAllowlistProposal;
use App\Models\GithubAllowlistProposalEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

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
            'decision_reason' => $proposal->decision_reason,
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

        try {
            $proposal = DB::transaction(function () use ($request, $slug) {
            $actor = $this->actor($request, lock: true);
            abort_if(GithubAllowlistProposal::query()->where('repository_slug', $slug)->exists(),
                409, 'Repository already proposed.');
            $item = GithubAllowlistProposal::create([
                'repository_slug' => $slug,
                'submitted_by_user_id' => $actor->id,
                'status' => 'pending',
            ]);
            GithubAllowlistProposalEvent::create([
                'proposal_id' => $item->id,
                'actor_user_id' => $actor->id,
                'action' => 'submitted',
                'created_at' => now('UTC'),
            ]);
            return $item;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two writers can pass the precheck. The normalized unique
            // index decides the race; never expose database constraint text.
            abort(409, 'Repository already proposed.');
        }

        return response()->json(['data' => $this->output($proposal)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $owner = $this->actor($request, owner: true);
        $this->onlyFields($request, ['decision', 'reason']);
        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            // Stable, auditable codes; no free-text credentials or private URLs.
            'reason' => ['sometimes', 'in:source_verified,not_public,owner_policy,out_of_scope'],
        ]);

        $initial = GithubAllowlistProposal::query()->findOrFail($id);
        abort_unless($initial->status === 'pending', 409);
        abort_if($initial->submitted_by_user_id === $owner->id, 403,
            'Independent owner review required.');

        // External network activity occurs BEFORE acquiring DB row locks.
        // This is always a freshly verified public record, never an old cache.
        if ($input['decision'] === 'approve') {
            $this->verifyPublicRepository($initial->repository_slug);
        }

        $proposal = DB::transaction(function () use ($request, $id, $input, $initial) {
            $owner = $this->actor($request, owner: true, lock: true);
            $locked = GithubAllowlistProposal::query()->whereKey($id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending'
                && $locked->repository_slug === $initial->repository_slug, 409);
            abort_if($locked->submitted_by_user_id === $owner->id, 403,
                'Independent owner review required.');

            $locked->status = $input['decision'] === 'approve' ? 'approved' : 'rejected';
            $locked->verified_at = $input['decision'] === 'approve' ? now('UTC') : null;
            $locked->reviewed_by_user_id = $owner->id;
            $locked->reviewed_at = now('UTC');
            $locked->decision_reason = $input['reason'] ?? (
                $input['decision'] === 'approve' ? 'source_verified' : 'owner_policy'
            );
            $locked->save();
            GithubAllowlistProposalEvent::create([
                'proposal_id' => $locked->id,
                'actor_user_id' => $owner->id,
                'action' => $locked->status,
                'reason' => $locked->decision_reason,
                'created_at' => now('UTC'),
            ]);
            return $locked;
        }, 3);

        return response()->json(['data' => $this->output($proposal)])
            ->header('Cache-Control', 'no-store, private');
    }

    private function verifyPublicRepository(string $slug): void
    {
        // Repository path derives ONLY from our server-validated canonical
        // owner/repo identifier. Never accept arbitrary URLs or OAuth tokens.
        try {
            $reply = Http::acceptJson()->withHeaders([
                'User-Agent' => 'WalidSite-Github-Allowlist-Review',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->connectTimeout(3)->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get('https://api.github.com/repos/'.$slug);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(503, 'Public repository verification unavailable.');
        }
        if ($reply->status() === 404) {
            abort(409, 'Public repository verification failed.');
        }
        if ($reply->status() === 403 || $reply->status() === 429
            || ! $reply->ok()) {
            abort(503, 'Public repository verification unavailable.');
        }
        if ($reply->json('private') !== false
            || $reply->json('visibility') !== 'public'
            || ! is_string($reply->json('full_name'))
            || strcasecmp($reply->json('full_name'), $slug) !== 0) {
            abort(409, 'Public repository verification failed.');
        }
        $url = $reply->json('html_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'github.com'
            || strtolower(trim($parts['path'] ?? '', '/')) !== $slug
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query'])
            || isset($parts['fragment'])) {
            abort(409, 'Public repository verification failed.');
        }
    }
}

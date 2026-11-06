<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubRepositoryCandidate;
use App\Models\GithubRepositoryCandidateEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AdminGithubAllowlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'status' => ['sometimes', 'in:pending,approved,rejected'],
        ]);
        $query = GithubRepositoryCandidate::query()
            ->when(isset($input['status']), static fn ($q) => $q->where('status', $input['status']))
            ->orderByDesc('created_at')->orderBy('id');
        $candidates = $query->paginate(20, ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $candidates->getCollection()->map(static fn (GithubRepositoryCandidate $item): array => [
                'id' => $item->id,
                'repository' => $item->repository_slug,
                'status' => $item->status,
                'submitted_at' => $item->created_at?->toIso8601String(),
                'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                'verified_at' => $item->verified_at?->toIso8601String(),
                'decision_reason' => $item->decision_reason,
            ])->values()->all(),
            'meta' => [
                'current_page' => $candidates->currentPage(),
                'last_page' => $candidates->lastPage(),
                'total' => $candidates->total(),
                'per_page' => $candidates->perPage(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);
        $this->onlyFields($request, ['repository']);
        $input = $request->validate([
            'repository' => [
                'required', 'string', 'max:140',
                'regex:/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}\z/D',
            ],
        ]);
        $slug = trim($input['repository']);
        if (str_contains($slug, '..')) {
            throw ValidationException::withMessages(['repository' => 'Invalid public repository identifier.']);
        }

        try {
            $candidate = DB::transaction(function () use ($request, $actor, $slug): GithubRepositoryCandidate {
                $lockedActor = $this->lockedActiveActor($request, $actor, false);
                $normalized = strtolower($slug);
                if (GithubRepositoryCandidate::query()->where('normalized_slug', $normalized)->lockForUpdate()->exists()) {
                    abort(409, 'Repository already has a review record.');
                }
                $item = GithubRepositoryCandidate::create([
                    'repository_slug' => $slug,
                    'normalized_slug' => $normalized,
                    'status' => 'pending',
                    'submitted_by_user_id' => $lockedActor->id,
                ]);
                GithubRepositoryCandidateEvent::create([
                    'candidate_id' => $item->id,
                    'actor_user_id' => $lockedActor->id,
                    'action' => 'submitted',
                    'created_at' => now(),
                ]);
                return $item;
            });
        } catch (\Illuminate\Database\QueryException $error) {
            // The unique normalized slug is the final concurrency guard.
            if (str_contains(strtolower($error->getMessage()), 'unique')
                || str_contains(strtolower($error->getMessage()), 'duplicate')) {
                abort(409, 'Repository already has a review record.');
            }
            throw $error;
        }

        return response()->json([
            'data' => [
                'id' => $candidate->id,
                'repository' => $candidate->repository_slug,
                'status' => $candidate->status,
                'submitted_at' => $candidate->created_at?->toIso8601String(),
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeOwner($request);
        $this->onlyFields($request, ['decision', 'reason']);
        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:180'],
        ]);

        $candidate = GithubRepositoryCandidate::query()->findOrFail($id);
        abort_if($candidate->submitted_by_user_id === $actor->id, 403, 'Independent owner review is required.');

        // Verify outside the DB transaction to avoid holding locks during a
        // remote request. The locked transaction below rechecks status, role,
        // token and maker/checker identity before writing the decision.
        $verifiedAt = null;
        if ($input['decision'] === 'approve') {
            $this->verifyPublicRepository($candidate->repository_slug);
            $verifiedAt = now();
        }

        $updated = DB::transaction(function () use ($request, $actor, $candidate, $input, $verifiedAt): GithubRepositoryCandidate {
            $lockedActor = $this->lockedActiveActor($request, $actor, true);
            $locked = GithubRepositoryCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 409, 'Only pending repository proposals can be decided.');
            abort_if($locked->submitted_by_user_id === $lockedActor->id, 403, 'Independent owner review is required.');

            $locked->forceFill([
                'status' => $input['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by_user_id' => $lockedActor->id,
                'reviewed_at' => now(),
                'verified_at' => $verifiedAt,
                'decision_reason' => isset($input['reason']) ? trim((string) $input['reason']) : null,
            ])->save();

            GithubRepositoryCandidateEvent::create([
                'candidate_id' => $locked->id,
                'actor_user_id' => $lockedActor->id,
                'action' => $input['decision'] === 'approve' ? 'approved' : 'rejected',
                'reason' => $locked->decision_reason,
                'created_at' => now(),
            ]);

            return $locked->refresh();
        });

        return response()->json([
            'data' => [
                'id' => $updated->id,
                'repository' => $updated->repository_slug,
                'status' => $updated->status,
                'reviewed_at' => $updated->reviewed_at?->toIso8601String(),
                'verified_at' => $updated->verified_at?->toIso8601String(),
                'decision_reason' => $updated->decision_reason,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function verifyPublicRepository(string $slug): void
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => 'WalidSite-Admin-Allowlist-Review',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->connectTimeout(3)
                ->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get('https://api.github.com/repos/'.$slug);
        } catch (\Throwable) {
            abort(503, 'GitHub public verification is temporarily unavailable.');
        }

        if ($response->status() === 404) {
            throw ValidationException::withMessages(['repository' => 'Repository is not verified as public.']);
        }
        if ($response->status() === 403 || ! $response->ok()) {
            abort(503, 'GitHub public verification is temporarily unavailable.');
        }

        $payload = $response->json();
        $url = is_array($payload) ? ($payload['html_url'] ?? null) : null;
        $parts = is_string($url) ? parse_url($url) : false;
        $valid = is_array($payload)
            && ($payload['private'] ?? null) === false
            && ($payload['visibility'] ?? null) === 'public'
            && is_string($payload['full_name'] ?? null)
            && strcasecmp($payload['full_name'], $slug) === 0
            && is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'github.com'
            && strtolower(trim($parts['path'] ?? '', '/')) === strtolower($slug)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);

        if (! $valid) {
            throw ValidationException::withMessages(['repository' => 'Repository is not verified as public.']);
        }
    }

    private function authorizeAdmin(Request $request): User
    {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();
        abort_unless(
            $actor?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin')
                && $actor->tokens()->whereKey($token->getKey())
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists(),
            403
        );
        return $actor;
    }

    private function authorizeOwner(Request $request): User
    {
        $actor = $this->authorizeAdmin($request);
        abort_unless($actor->hasRole('owner'), 403);
        return $actor;
    }

    private function lockedActiveActor(Request $request, User $initial, bool $ownerOnly): User
    {
        $token = $initial->currentAccessToken();
        $actor = User::query()->whereKey($initial->id)->lockForUpdate()->first();
        abort_unless(
            $actor?->canAccessAdmin()
                && (! $ownerOnly || $actor->hasRole('owner'))
                && $token instanceof PersonalAccessToken
                && $token->can('admin')
                && $actor->tokens()->whereKey($token->getKey())
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists(),
            403
        );
        return $actor;
    }

    private function onlyFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unsupported request fields.']);
        }
    }
}

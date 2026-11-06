<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Domain\GitHub\GithubProposalWorkflow;
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

    public function store(Request $request, GithubProposalWorkflow $workflow): JsonResponse
    {
        $this->actor($request);
        $this->onlyFields($request, ['repository_slug']);
        $input = $request->validate([
            'repository_slug' => ['required', 'string', 'max:140'],
        ]);
        $proposal = $workflow->submit(
            fn (bool $lock): User => $this->actor($request, lock: $lock),
            $input['repository_slug'],
        );
        return response()->json(['data' => $this->output($proposal)], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, string $id, GithubProposalWorkflow $workflow): JsonResponse
    {
        $this->actor($request, owner: true);
        $this->onlyFields($request, ['decision', 'reason']);
        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['sometimes', 'in:source_verified,not_public,owner_policy,out_of_scope'],
        ]);
        $proposal = $workflow->decide(
            fn (bool $lock): User => $this->actor($request, owner: true, lock: $lock),
            $id, $input['decision'], $input['reason'] ?? null,
        );
        return response()->json(['data' => $this->output($proposal)])
            ->header('Cache-Control', 'no-store, private');
    }

}

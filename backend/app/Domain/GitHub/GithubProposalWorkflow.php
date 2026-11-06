<?php

namespace App\Domain\GitHub;

use App\Models\GithubAllowlistProposal;
use App\Models\GithubAllowlistProposalEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Shared authoritative workflow for Sanctum API and authenticated web admin.
 * Callers supply an actor resolver that rechecks their OWN credential/role
 * under lock. No server-wide API token and no parallel approval policy.
 */
final class GithubProposalWorkflow
{
    public function submit(callable $actor, string $rawSlug): GithubAllowlistProposal
    {
        if (strlen($rawSlug) > 140
            || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}\z/D', $rawSlug) !== 1) {
            throw ValidationException::withMessages(['repository_slug' => 'Invalid repository slug.']);
        }
        $slug = strtolower($rawSlug);
        if (str_contains($slug, '..') || str_ends_with($slug, '.')) {
            throw ValidationException::withMessages(['repository_slug' => 'Invalid repository slug.']);
        }
        try {
            return DB::transaction(function () use ($actor, $slug) {
                $user = $actor(true);
                abort_unless($user instanceof User && $user->canAccessAdmin(), 403);
                abort_if(GithubAllowlistProposal::query()
                    ->where('repository_slug', $slug)->exists(), 409, 'Repository already proposed.');
                $item = GithubAllowlistProposal::create([
                    'repository_slug' => $slug,
                    'submitted_by_user_id' => $user->id,
                    'status' => 'pending',
                ]);
                GithubAllowlistProposalEvent::create([
                    'proposal_id' => $item->id,
                    'actor_user_id' => $user->id,
                    'action' => 'submitted',
                    'created_at' => now('UTC'),
                ]);
                return $item;
            }, 3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(409, 'Repository already proposed.');
        }
    }

    public function decide(callable $actor, string $id, string $decision, ?string $reason = null): GithubAllowlistProposal
    {
        if (! in_array($decision, ['approve', 'reject'], true)
            || ($reason !== null && ! in_array($reason,
                ['source_verified', 'not_public', 'owner_policy', 'out_of_scope'], true))) {
            throw ValidationException::withMessages(['decision' => 'Invalid decision.']);
        }
        $owner = $actor(false);
        abort_unless($owner instanceof User && $owner->canAccessAdmin()
            && $owner->hasRole('owner'), 403);
        $initial = GithubAllowlistProposal::query()->findOrFail($id);
        abort_unless($initial->status === 'pending', 409);
        abort_if($initial->submitted_by_user_id === $owner->id, 403,
            'Independent owner review required.');

        // Never hold DB locks across a remote HTTP request.
        if ($decision === 'approve') {
            $this->verifyPublicRepository($initial->repository_slug);
        }
        return DB::transaction(function () use ($actor, $initial, $decision, $reason) {
            $owner = $actor(true);
            abort_unless($owner instanceof User && $owner->canAccessAdmin()
                && $owner->hasRole('owner'), 403);
            $locked = GithubAllowlistProposal::query()->whereKey($initial->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending'
                && $locked->repository_slug === $initial->repository_slug, 409);
            abort_if($locked->submitted_by_user_id === $owner->id, 403,
                'Independent owner review required.');

            $locked->status = $decision === 'approve' ? 'approved' : 'rejected';
            $locked->verified_at = $decision === 'approve' ? now('UTC') : null;
            $locked->reviewed_by_user_id = $owner->id;
            $locked->reviewed_at = now('UTC');
            $locked->decision_reason = $reason ?? ($decision === 'approve'
                ? 'source_verified' : 'owner_policy');
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
    }

    private function verifyPublicRepository(string $slug): void
    {
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
        if ($reply->status() === 403 || $reply->status() === 429 || ! $reply->ok()) {
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

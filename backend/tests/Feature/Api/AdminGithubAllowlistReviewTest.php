<?php

namespace Tests\Feature\Api;

use App\Models\GithubRepositoryCandidate;
use App\Models\GithubRepositoryCandidateEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGithubAllowlistReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:string} */
    private function account(string $role = 'owner', string $ability = 'admin'): array
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return [$user, $user->createToken('github-allowlist-test', [$ability], now()->addHour())->plainTextToken];
    }

    private function proposal(string $slug = 'walidatiyaai2025-gif/reviewed-repo', ?User $submitter = null): GithubRepositoryCandidate
    {
        [$user] = $submitter === null ? $this->account('admin') : [$submitter];
        return GithubRepositoryCandidate::create([
            'repository_slug' => $slug,
            'normalized_slug' => strtolower($slug),
            'status' => 'pending',
            'submitted_by_user_id' => $user->id,
        ]);
    }

    public function test_canonical_allowlist_proposal_routes_are_registered(): void
    {
        $required = [
            'api/v1/admin/github/proposals' => ['GET', 'POST'],
            'api/v1/admin/github/proposals/{id}/decision' => ['POST'],
        ];
        $routes = collect(Route::getRoutes()->getRoutes());
        foreach ($required as $uri => $methods) {
            $matches = $routes->filter(static fn ($route) => $route->uri() === $uri);
            $this->assertNotEmpty($matches->all(), 'Missing '.$uri);
            $registered = $matches->flatMap(static fn ($route) => $route->methods())->unique()->values()->all();
            foreach ($methods as $method) {
                $this->assertContains($method, $registered);
            }
        }
    }

    public function test_guest_reader_and_mobile_only_tokens_cannot_access_proposals(): void
    {
        $this->getJson('/api/v1/admin/github/proposals')->assertUnauthorized();
        [, $reader] = $this->account('reader');
        $this->withToken($reader)->getJson('/api/v1/admin/github/proposals')->assertForbidden();
        Auth::forgetGuards();
        [, $mobile] = $this->account('owner', 'mobile');
        $this->withToken($mobile)->getJson('/api/v1/admin/github/proposals')->assertForbidden();
    }

    public function test_admin_can_submit_a_candidate_without_network_and_duplicate_case_is_blocked(): void
    {
        Http::fake();
        [, $admin] = $this->account('admin');
        $created = $this->withToken($admin)->postJson('/api/v1/admin/github/proposals', [
            'repository' => 'WalidAtiyaaI2025-GIF/Review-Repo',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.repository', 'WalidAtiyaaI2025-GIF/Review-Repo');

        $id = $created->json('data.id');
        $this->assertDatabaseHas('github_repository_candidates', [
            'id' => $id,
            'normalized_slug' => 'walidatiyaai2025-gif/review-repo',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('github_repository_candidate_events', [
            'candidate_id' => $id,
            'action' => 'submitted',
        ]);
        $this->assertStringNotContainsString($admin, $created->getContent());
        Http::assertNothingSent();

        $this->postJson('/api/v1/admin/github/proposals', [
            'repository' => 'walidatiyaai2025-gif/review-repo',
        ])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_submission_rejects_urls_traversal_and_extra_fields_without_network(): void
    {
        Http::fake();
        [, $token] = $this->account('admin');
        foreach ([
            'https://github.com/owner/repo',
            'owner/repo?token=x',
            'owner/../repo',
            'owner//repo',
            'owner/repo#fragment',
            'owner',
            'x'.str_repeat('a', 140).'/repo',
        ] as $slug) {
            $this->withToken($token)->postJson('/api/v1/admin/github/proposals', [
                'repository' => $slug,
            ])->assertUnprocessable();
        }
        $this->withToken($token)->postJson('/api/v1/admin/github/proposals', [
            'repository' => 'owner/repo',
            'url' => 'https://evil.example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('request');
        Http::assertNothingSent();
    }

    public function test_only_an_independent_owner_can_approve_after_fresh_public_verification(): void
    {
        [$submitter, $adminToken] = $this->account('admin');
        $candidate = $this->proposal('octocat/Hello-World', $submitter);
        [, $ownerToken] = $this->account('owner');

        Http::fake([
            'https://api.github.com/repos/octocat/Hello-World' => Http::response([
                'full_name' => 'octocat/Hello-World',
                'private' => false,
                'visibility' => 'public',
                'html_url' => 'https://github.com/octocat/Hello-World',
                'description' => 'Public sample',
            ], 200),
        ]);

        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/github/proposals/'.$candidate->id.'/decision', ['decision' => 'approve'])
            ->assertForbidden();

        $response = $this->withToken($ownerToken)
            ->postJson('/api/v1/admin/github/proposals/'.$candidate->id.'/decision', [
                'decision' => 'approve',
                'reason' => 'Public source verified.',
            ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.repository', 'octocat/Hello-World');

        $this->assertNotNull($response->json('data.verified_at'));
        $this->assertDatabaseHas('github_repository_candidates', [
            'id' => $candidate->id,
            'status' => 'approved',
            'reviewed_by_user_id' => User::query()->where('email', '!=', $submitter->email)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->value('id'),
        ]);
        $this->assertDatabaseHas('github_repository_candidate_events', [
            'candidate_id' => $candidate->id,
            'action' => 'approved',
            'reason' => 'Public source verified.',
        ]);
        Http::assertSentCount(1);
    }

    public function test_owner_cannot_approve_own_proposal_and_rejected_proposal_never_calls_github(): void
    {
        Http::fake();
        [$owner, $token] = $this->account('owner');
        $candidate = $this->proposal('octocat/own-repo', $owner);

        $this->withToken($token)
            ->postJson('/api/v1/admin/github/proposals/'.$candidate->id.'/decision', ['decision' => 'approve'])
            ->assertForbidden();
        Http::assertNothingSent();

        [$submitter] = $this->account('admin');
        $other = $this->proposal('octocat/reject-me', $submitter);
        $this->withToken($token)
            ->postJson('/api/v1/admin/github/proposals/'.$other->id.'/decision', [
                'decision' => 'reject',
                'reason' => 'Not in scope.',
            ])->assertOk()->assertJsonPath('data.status', 'rejected');
        Http::assertNothingSent();
        $this->assertDatabaseHas('github_repository_candidate_events', [
            'candidate_id' => $other->id,
            'action' => 'rejected',
            'reason' => 'Not in scope.',
        ]);
    }

    public function test_private_or_unavailable_repository_cannot_be_approved_or_claim_verified(): void
    {
        [$submitter] = $this->account('admin');
        [, $ownerToken] = $this->account('owner');
        $private = $this->proposal('owner/private-repo', $submitter);
        $missing = $this->proposal('owner/missing-repo', $submitter);
        $unavailable = $this->proposal('owner/unavailable-repo', $submitter);

        Http::fake([
            'https://api.github.com/repos/owner/private-repo' => Http::response([
                'full_name' => 'owner/private-repo',
                'private' => true,
                'visibility' => 'private',
                'html_url' => 'https://github.com/owner/private-repo',
            ], 200),
            'https://api.github.com/repos/owner/missing-repo' => Http::response([], 404),
            'https://api.github.com/repos/owner/unavailable-repo' => Http::response([], 503),
        ]);

        $this->withToken($ownerToken)->postJson('/api/v1/admin/github/proposals/'.$private->id.'/decision', [
            'decision' => 'approve',
        ])->assertUnprocessable();
        $this->withToken($ownerToken)->postJson('/api/v1/admin/github/proposals/'.$missing->id.'/decision', [
            'decision' => 'approve',
        ])->assertUnprocessable();
        $this->withToken($ownerToken)->postJson('/api/v1/admin/github/proposals/'.$unavailable->id.'/decision', [
            'decision' => 'approve',
        ])->assertStatus(503);

        foreach ([$private, $missing, $unavailable] as $item) {
            $this->assertDatabaseHas('github_repository_candidates', [
                'id' => $item->id,
                'status' => 'pending',
                'reviewed_at' => null,
                'verified_at' => null,
            ]);
        }
    }

    public function test_decision_requires_owner_and_unknown_fields_are_rejected(): void
    {
        [$submitter] = $this->account('admin');
        $candidate = $this->proposal('owner/safe-repo', $submitter);
        [, $adminToken] = $this->account('admin');
        [, $ownerToken] = $this->account('owner');
        Http::fake();

        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/github/proposals/'.$candidate->id.'/decision', ['decision' => 'reject'])
            ->assertForbidden();

        $this->withToken($ownerToken)
            ->postJson('/api/v1/admin/github/proposals/'.$candidate->id.'/decision', [
                'decision' => 'reject', 'unexpected' => 'value',
            ])->assertUnprocessable()->assertJsonValidationErrors('request');
        Http::assertNothingSent();
    }
}

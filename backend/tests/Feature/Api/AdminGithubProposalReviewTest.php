<?php

namespace Tests\Feature\Api;

use App\Models\GithubAllowlistProposal;
use App\Models\GithubAllowlistProposalEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGithubProposalReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise all negative cases without consuming a shared test-IP quota.
        // Real API routes keep their production throttling middleware.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function asBearer(string $token): static
    {
        // Sanctum/Illuminate guard may cache an earlier request user in a
        // multi-identity test; always re-resolve the bearer on each request.
        Auth::forgetGuards();
        Auth::forgetGuards();
        $this->withToken($token);
        return $this;
    }

    private function identity(string $role): array
    {
        $user = User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password_hash' => Hash::make('TestSecret123!'),
            'status' => 'active',
        ]);
        if ($role !== 'reader') {
            $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        }
        return [$user, $user->createToken('admin-review-test', ['admin'],
            now()->addHour())->plainTextToken];
    }

    private function githubPublic(string $name): array
    {
        return [
            'private' => false,
            'visibility' => 'public',
            'full_name' => $name,
            'html_url' => 'https://github.com/'.$name,
            'archived' => false,
        ];
    }

    public function test_admin_submission_requires_real_bearer_and_no_network_or_secrets(): void
    {
        Http::fake();
        [$admin, $token] = $this->identity('admin');
        $url = '/api/v1/admin/github/proposals';

        $this->postJson($url, ['repository_slug' => 'Trusted/Repo'])->assertUnauthorized();
        [$reader, $readerToken] = $this->identity('reader');
        $this->asBearer($readerToken)->postJson($url,
            ['repository_slug' => 'Trusted/Repo'])->assertForbidden();

        $created = $this->asBearer($token)->postJson($url,
            ['repository_slug' => 'Trusted/Repo'])->assertCreated()
            ->assertJsonPath('data.repository_slug', 'trusted/repo')
            ->assertJsonPath('data.status', 'pending');
        $proposal = GithubAllowlistProposal::query()->firstOrFail();
        $this->assertSame($admin->id, $proposal->submitted_by_user_id);
        $this->assertNull($proposal->reviewed_at);
        $this->assertNull($proposal->verified_at);
        $this->assertSame(1, GithubAllowlistProposal::count());
        $this->assertSame(1, GithubAllowlistProposalEvent::query()->where(
            'proposal_id', $proposal->id
        )->where('action', 'submitted')->count());
        $this->assertStringNotContainsString($admin->email, $created->getContent());

        $this->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.repository_slug', 'trusted/repo');
        $this->postJson($url, ['repository_slug' => 'TRUSTED/REPO'])
            ->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_proposals_reject_malformed_urls_traversal_and_extra_fields(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        [, $token] = $this->identity('admin');
        foreach ([
            'https://github.com/owner/repo', '../repo', 'owner/../repo',
            'owner/repo?token=private', 'owner/repo/extra',
            'owner/repo.', 'owner//repo', 'one', 'owner/..',
        ] as $value) {
            $this->asBearer($token)->postJson('/api/v1/admin/github/proposals',
                ['repository_slug' => $value])->assertUnprocessable();
        }
        $this->postJson('/api/v1/admin/github/proposals',
            ['repository_slug' => 'safe/repo', 'api_key' => 'PRIVATE'])
            ->assertUnprocessable();
        $this->assertSame(0, GithubAllowlistProposal::count());
        Http::assertNothingSent();
    }

    public function test_only_independent_owner_can_approve_verified_public_github_repository(): void
    {
        [$admin, $submitToken] = $this->identity('admin');
        [$owner, $ownerToken] = $this->identity('owner');
        [, $otherToken] = $this->identity('admin');
        Http::fake(['api.github.com/repos/*' => Http::response(
            $this->githubPublic('trusted/repo'), 200,
        )]);
        $created = $this->asBearer($submitToken)
            ->postJson('/api/v1/admin/github/proposals',
                ['repository_slug' => 'Trusted/Repo'])->assertCreated();
        Http::assertNothingSent();
        $id = $created->json('data.id');
        $path = '/api/v1/admin/github/proposals/'.$id.'/decision';
        $this->asBearer($otherToken)->postJson($path,
            ['decision' => 'approve'])->assertForbidden();

        $this->asBearer($ownerToken)->postJson($path,
            ['decision' => 'approve'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.repository_slug', 'trusted/repo');
        $fresh = GithubAllowlistProposal::findOrFail($id);
        $this->assertSame($owner->id, $fresh->reviewed_by_user_id);
        $this->assertSame($admin->id, $fresh->submitted_by_user_id);
        $this->assertNotNull($fresh->verified_at);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame('source_verified', $fresh->decision_reason);
        $this->assertSame(['submitted', 'approved'],
            GithubAllowlistProposalEvent::query()
                ->where('proposal_id', $id)->orderBy('id')->pluck('action')->all());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/trusted/repo'
            && ! $request->hasHeader('Authorization'));
        $this->postJson($path, ['decision' => 'reject'])->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_owner_cannot_approve_own_proposal_and_reject_is_network_free(): void
    {
        [$owner, $ownerToken] = $this->identity('owner');
        [, $otherOwnerToken] = $this->identity('owner');
        Http::fake();
        $id = $this->asBearer($ownerToken)
            ->postJson('/api/v1/admin/github/proposals',
                ['repository_slug' => 'example/public'])->assertCreated()
            ->json('data.id');
        $path = '/api/v1/admin/github/proposals/'.$id.'/decision';
        $this->postJson($path, ['decision' => 'approve'])->assertForbidden();
        $this->asBearer($otherOwnerToken)->postJson($path,
            ['decision' => 'reject'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.verified_at', null);
        $this->assertNotNull(GithubAllowlistProposal::findOrFail($id)->reviewed_at);
        $this->assertSame(['submitted', 'rejected'],
            GithubAllowlistProposalEvent::query()->where(
                'proposal_id', $id
            )->orderBy('id')->pluck('action')->all());
        $this->postJson($path, ['decision' => 'approve'])->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame($owner->id,
            GithubAllowlistProposal::findOrFail($id)->submitted_by_user_id);
    }

    public function test_private_deleted_rate_limited_and_untrusted_github_do_not_approve(): void
    {
        [, $adminToken] = $this->identity('admin');
        [, $ownerToken] = $this->identity('owner');
        $cases = [
            [200, ['private' => true, 'visibility' => 'private']],
            [404, []], [429, []], [503, []],
            [200, array_merge($this->githubPublic('trusted/repo'),
                ['html_url' => 'https://github.com@evil.example/trusted/repo'])],
            [200, $this->githubPublic('wrong/repo')],
        ];
        foreach ($cases as $index => [$status, $payload]) {
            Http::fake(['api.github.com/repos/*' => Http::response($payload, $status)]);
            $slug = 'trusted/repo-'.$index;
            $id = $this->asBearer($adminToken)
                ->postJson('/api/v1/admin/github/proposals',
                    ['repository_slug' => $slug])->assertCreated()
                ->json('data.id');
            $this->asBearer($ownerToken)->postJson(
                '/api/v1/admin/github/proposals/'.$id.'/decision',
                ['decision' => 'approve'],
            )->assertStatus(in_array($status, [403, 429, 503], true) ? 503 : 409);
            $proposal = GithubAllowlistProposal::findOrFail($id);
            $this->assertSame('pending', $proposal->status);
            $this->assertNull($proposal->verified_at);
            $this->assertNull($proposal->reviewed_by_user_id);
            $this->assertSame(1, GithubAllowlistProposalEvent::query()
                ->where('proposal_id', $id)->count());
        }
    }

    public function test_reason_is_audit_safe_and_cannot_contain_secrets(): void
    {
        [, $adminToken] = $this->identity('admin');
        [, $ownerToken] = $this->identity('owner');
        Http::fake();
        $id = $this->asBearer($adminToken)->postJson(
            '/api/v1/admin/github/proposals',
            ['repository_slug' => 'safe/project'],
        )->assertCreated()->json('data.id');
        $path = '/api/v1/admin/github/proposals/'.$id.'/decision';
        $this->asBearer($ownerToken)->postJson($path, [
            'decision' => 'reject', 'reason' => 'api_key=SECRET',
        ])->assertUnprocessable();
        $this->postJson($path, [
            'decision' => 'reject', 'reason' => 'out_of_scope',
        ])->assertOk()->assertJsonPath('data.decision_reason', 'out_of_scope');
        $events = GithubAllowlistProposalEvent::query()
            ->where('proposal_id', $id)->orderBy('id')->get();
        $this->assertSame(['submitted', 'rejected'], $events->pluck('action')->all());
        $this->assertSame('out_of_scope', $events->last()->reason);
        $this->assertSame(2, $events->count());
        $this->assertStringNotContainsString('SECRET',
            (string) $events->last()->reason);
        Http::assertNothingSent();
    }

    public function test_revoked_owner_role_prevents_decision(): void
    {
        [, $adminToken] = $this->identity('admin');
        [$owner, $ownerToken] = $this->identity('owner');
        $id = $this->asBearer($adminToken)->postJson(
            '/api/v1/admin/github/proposals', ['repository_slug' => 'demo/approved'],
        )->assertCreated()->json('data.id');
        $owner->roles()->detach();
        Http::fake();
        $this->asBearer($ownerToken)->postJson(
            '/api/v1/admin/github/proposals/'.$id.'/decision',
            ['decision' => 'approve'],
        )->assertForbidden();
        $this->assertSame('pending', GithubAllowlistProposal::findOrFail($id)->status);
        Http::assertNothingSent();
    }
}

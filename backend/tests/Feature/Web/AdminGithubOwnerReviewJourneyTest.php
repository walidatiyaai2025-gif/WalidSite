<?php

namespace Tests\Feature\Web;

use App\Models\GithubAllowlistProposal;
use App\Models\GithubAllowlistProposalEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGithubOwnerReviewJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['portfolio.github_public_repos' => []]);
        // Multiple security-negative cases share a test IP. Production
        // web throttling and CSRF protections are unchanged.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function account(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function makeProposal(User $user, string $slug): GithubAllowlistProposal
    {
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
    }

    public function test_guest_and_reader_are_denied_web_submit_review_and_list(): void
    {
        Http::fake();
        $submit = '/admin/github/proposals';
        $decision = '/admin/github/proposals/'.Str::uuid().'/decision';
        $this->get('/admin/github')->assertRedirect('/admin/login');
        $this->post($submit, ['repository_slug' => 'one/public'])
            ->assertRedirect('/admin/login');
        $this->post($decision, ['decision' => 'approve'])
            ->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web');
        $this->get('/admin/github')->assertForbidden();
        $this->post($submit, ['repository_slug' => 'one/public'])->assertForbidden();
        $this->post($decision, ['decision' => 'approve'])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, GithubAllowlistProposal::count());
    }

    public function test_admin_can_submit_and_owner_can_independently_review_in_both_locales(): void
    {
        $admin = $this->account('admin');
        $owner = $this->account('owner');
        Http::fake(['api.github.com/repos/*' => Http::response([
            'full_name' => 'acme/public',
            'private' => false,
            'visibility' => 'public',
            'html_url' => 'https://github.com/acme/public',
        ])]);
        $this->actingAs($admin, 'web');
        foreach (['en', 'ar'] as $lang) {
            $response = $this->get('/admin/github?lang='.$lang)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('lang="'.$lang.'"', false)
                ->assertSee('dir="'.($lang === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('name="repository_slug"', false);
            // The admin is not permitted to render any decision form.
            $this->assertStringNotContainsString('name="decision" value="approve"',
                $response->getContent());
        }
        $this->post('/admin/github/proposals?lang=ar', [
            'repository_slug' => 'Acme/Public',
        ])->assertRedirect('/admin/github?lang=ar');
        Http::assertNothingSent();
        $proposal = GithubAllowlistProposal::firstOrFail();
        $this->assertSame('acme/public', $proposal->repository_slug);
        $this->assertSame('pending', $proposal->status);
        $this->assertNull($proposal->verified_at);
        $this->assertSame('submitted',
            GithubAllowlistProposalEvent::where('proposal_id', $proposal->id)->value('action'));

        $this->actingAs($owner, 'web');
        $this->get('/admin/github?lang=ar')->assertOk()
            ->assertSee('acme/public')->assertSee('value="approve"', false)
            ->assertSee('value="reject"', false);
        $this->post('/admin/github/proposals/'.$proposal->id.'/decision?lang=ar', [
            'decision' => 'approve',
            'reason' => 'source_verified',
        ])->assertRedirect('/admin/github?lang=ar');
        $proposal->refresh();
        $this->assertSame('approved', $proposal->status);
        $this->assertSame($owner->id, $proposal->reviewed_by_user_id);
        $this->assertNotNull($proposal->verified_at);
        $this->assertSame(['submitted', 'approved'],
            GithubAllowlistProposalEvent::where('proposal_id', $proposal->id)
                ->orderBy('id')->pluck('action')->all());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() ===
            'https://api.github.com/repos/acme/public'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_admin_cannot_approve_and_owner_cannot_review_own_submission(): void
    {
        Http::fake();
        $owner = $this->account('owner');
        $admin = $this->account('admin');
        $first = $this->makeProposal($owner, 'owner/own');
        $second = $this->makeProposal($admin, 'admin/other');
        $url = fn ($p) => '/admin/github/proposals/'.$p->id.'/decision';
        $this->actingAs($admin, 'web')->post($url($second), [
            'decision' => 'approve', 'reason' => 'source_verified',
        ])->assertForbidden();
        $this->actingAs($owner, 'web')->post($url($first), [
            'decision' => 'approve', 'reason' => 'source_verified',
        ])->assertForbidden();
        $this->get('/admin/github')->assertOk()
            ->assertDontSee('action="'.route('admin.operations.github.decide', [
                'id' => $first->id, 'lang' => 'en',
            ]).'"', false);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_rejected_and_private_candidate_never_become_public_or_verified(): void
    {
        $admin = $this->account('admin');
        $owner = $this->account('owner');
        $item = $this->makeProposal($admin, 'acme/private');
        Http::fake(['api.github.com/repos/*' => Http::response([
            'full_name' => 'acme/private',
            'visibility' => 'private', 'private' => true,
            'html_url' => 'https://github.com/acme/private',
        ])]);
        $this->actingAs($owner, 'web')->post(
            '/admin/github/proposals/'.$item->id.'/decision',
            ['decision' => 'approve', 'reason' => 'source_verified'],
        )->assertStatus(409);
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertNull($item->fresh()->verified_at);
        $this->post('/admin/github/proposals/'.$item->id.'/decision',
            ['decision' => 'reject', 'reason' => 'not_public'])
            ->assertRedirect('/admin/github?lang=en');
        $this->assertSame('rejected', $item->fresh()->status);
        $this->assertNull($item->fresh()->verified_at);
        $this->assertSame(['submitted', 'rejected'],
            GithubAllowlistProposalEvent::where('proposal_id', $item->id)
                ->orderBy('id')->pluck('action')->all());
        $this->assertSame([], app(\App\Integrations\GitHub\PublicRepositoryDirectory::class)
            ->configuredSlugs());
    }

    public function test_arabic_proposal_pages_keep_the_language_and_do_not_replay_unverified_repo_queries(): void
    {
        $admin = $this->account('admin');
        for ($i = 0; $i < 22; $i++) {
            $this->makeProposal($admin, 'acme/entry-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->actingAs($admin, 'web');
        $page = $this->get('/admin/github?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false);
        preg_match_all('/href="([^"]+)"/', $page->getContent(), $links);
        $pagination = false;
        foreach ($links[1] as $encoded) {
            $href = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = parse_url($href);
            if (($parts['path'] ?? null) !== '/admin/github') {
                continue;
            }
            parse_str($parts['query'] ?? '', $query);
            if (($query['page'] ?? null) === '2') {
                $pagination = true;
                $this->assertSame('ar', $query['lang'] ?? null);
                $this->assertArrayNotHasKey('repo', $query);
            }
        }
        $this->assertTrue($pagination, 'Arabic review list must have an accessible second page');
        $this->get('/admin/github?lang=ar&page=2')->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    public function test_web_owner_decision_requires_explicit_audit_reason(): void
    {
        $admin = $this->account('admin');
        $owner = $this->account('owner');
        $item = $this->makeProposal($admin, 'acme/reason-required');
        Http::fake();
        $this->actingAs($owner, 'web');
        $url = '/admin/github/proposals/'.$item->id.'/decision';
        $page = $this->get('/admin/github')->assertOk();
        $this->assertStringContainsString(
            'name="reason" required', $page->getContent()
        );
        $this->post($url, ['decision' => 'reject'])
            ->assertSessionHasErrors(['reason']);
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertSame(1, GithubAllowlistProposalEvent::where(
            'proposal_id', $item->id)->count());
        $this->post($url, ['decision' => 'reject', 'reason' => 'owner_policy'])
            ->assertRedirect('/admin/github?lang=en');
        $this->assertSame('rejected', $item->fresh()->status);
        $this->assertSame('owner_policy', $item->fresh()->decision_reason);
        $this->assertSame('owner_policy', GithubAllowlistProposalEvent::where(
            'proposal_id', $item->id)->where('action', 'rejected')->value('reason'));
        Http::assertNothingSent();
    }

    public function test_malformed_repo_secret_fields_and_revoked_owner_role_fail_closed(): void
    {
        Http::fake();
        $admin = $this->account('admin');
        $owner = $this->account('owner');
        $item = $this->makeProposal($admin, 'acme/submitted');
        $this->actingAs($admin, 'web');
        foreach (['https://github.com/acme/public', '../repo', 'x/../private'] as $slug) {
            $this->post('/admin/github/proposals', [
                'repository_slug' => $slug,
            ])->assertSessionHasErrors();
        }
        $this->post('/admin/github/proposals', [
            'repository_slug' => 'acme/public',
            'token' => 'dont-store-this',
        ])->assertSessionHasErrors();
        $this->actingAs($owner, 'web');
        $owner->roles()->detach();
        $this->post('/admin/github/proposals/'.$item->id.'/decision', [
            'decision' => 'reject',
        ])->assertForbidden();
        $this->assertSame('pending', $item->fresh()->status);
        Http::assertNothingSent();
    }
}

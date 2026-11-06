<?php

namespace Tests\Feature\GitHub;

use App\Integrations\GitHub\PublicRepositoryDirectory;
use App\Models\GithubAllowlistProposal;
use App\Models\GithubAllowlistProposalEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApprovedRepositoryDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['portfolio.github_public_repos' => []]);
    }

    private function proposal(string $slug, string $status = 'approved', bool $verified = true): GithubAllowlistProposal
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
        ]);
        $proposal = GithubAllowlistProposal::create([
            'repository_slug' => $slug,
            'status' => $status,
            'submitted_by_user_id' => $user->id,
        ]);
        // Test fixtures emulate the independent owner decision and audit
        // event that the real W1 approval API persists atomically.
        if ($status === 'approved' && $verified) {
            $reviewer = User::create([
                'email' => Str::lower(Str::random(16)).'@example.test',
                'status' => 'active',
            ]);
            $proposal->reviewed_by_user_id = $reviewer->id;
            $proposal->reviewed_at = now()->subMinute();
            $proposal->verified_at = now()->subMinute();
            $proposal->save();
            GithubAllowlistProposalEvent::create([
                'proposal_id' => $proposal->id,
                'actor_user_id' => $reviewer->id,
                'action' => 'approved',
                'reason' => 'source_verified',
                'created_at' => now()->subMinute(),
            ]);
        } else {
            $proposal->verified_at = $verified ? now()->subMinute() : null;
            $proposal->save();
        }
        return $proposal;
    }

    private function publicMetadata(string $slug): array
    {
        return [
            'full_name' => $slug,
            'private' => false,
            'visibility' => 'public',
            'html_url' => 'https://github.com/'.$slug,
            'description' => 'Public source',
        ];
    }

    public function test_only_approved_and_verified_db_repositories_join_public_directory(): void
    {
        $this->proposal('acme/public');
        $this->proposal('acme/pending', 'pending', false);
        $this->proposal('acme/rejected', 'rejected', false);
        $this->proposal('acme/unverified', 'approved', false);
        config(['portfolio.github_public_repos' => ['first/env-repo']]);
        Http::fake([
            'api.github.com/repos/first/env-repo' => Http::response(
                $this->publicMetadata('first/env-repo')),
            'api.github.com/repos/acme/public' => Http::response(
                $this->publicMetadata('acme/public')),
            '*' => Http::response([], 404),
        ]);

        $directory = app(PublicRepositoryDirectory::class);
        $this->assertSame(['first/env-repo', 'acme/public'], $directory->configuredSlugs());
        $repositories = $directory->repositories();
        $this->assertSame(['first/env-repo', 'acme/public'],
            array_column($repositories, 'full_name'));
        $this->assertSame(['verified', 'verified'],
            array_column($repositories, 'status'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url()
            === 'https://api.github.com/repos/acme/public'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_approved_db_repository_must_still_pass_fresh_public_metadata_gate(): void
    {
        $this->proposal('acme/was-public');
        Http::fake(['api.github.com/repos/*' => Http::response([
            'full_name' => 'acme/was-public',
            'private' => true,
            'visibility' => 'private',
            'html_url' => 'https://github.com/acme/was-public',
        ])]);
        $entry = app(PublicRepositoryDirectory::class)->repositories()[0];
        $this->assertSame('unavailable', $entry['status']);
        $this->assertNull($entry['full_name']);
        $this->assertArrayNotHasKey('url', $entry);
        Http::assertSentCount(1);
    }

    public function test_env_precedence_case_dedup_and_bounded_twenty_entries(): void
    {
        $this->proposal('OWNER/REPO');
        $this->proposal('other/additional');
        config(['portfolio.github_public_repos' => ['owner/repo', 'OWNER/REPO']]);
        $this->assertSame(['owner/repo', 'other/additional'],
            app(PublicRepositoryDirectory::class)->configuredSlugs());

        config(['portfolio.github_public_repos' => array_map(
            fn (int $i) => 'env/repo-'.$i, range(1, 20),
        )]);
        $this->assertCount(20, app(PublicRepositoryDirectory::class)->configuredSlugs());
        $this->assertNotContains('other/additional',
            app(PublicRepositoryDirectory::class)->configuredSlugs());
    }

    public function test_future_approval_verification_is_not_public_permission(): void
    {
        $item = $this->proposal('acme/future');
        $item->verified_at = now()->addHour();
        $item->save();
        Http::fake();
        $this->assertSame([], app(PublicRepositoryDirectory::class)->repositories());
        Http::assertNothingSent();
    }

    public function test_mutable_approved_flag_without_independent_owner_receipt_is_not_public(): void
    {
        $noEvent = $this->proposal('acme/no-event');
        GithubAllowlistProposalEvent::query()
            ->where('proposal_id', $noEvent->id)->delete();

        $noReviewer = $this->proposal('acme/no-reviewer');
        $noReviewer->reviewed_by_user_id = null;
        $noReviewer->save();

        $selfApproved = $this->proposal('acme/self-approved');
        $selfApproved->reviewed_by_user_id = $selfApproved->submitted_by_user_id;
        $selfApproved->save();

        $futureReview = $this->proposal('acme/future-review');
        $futureReview->reviewed_at = now()->addHour();
        $futureReview->save();

        Http::fake();
        $directory = app(PublicRepositoryDirectory::class);
        $this->assertSame([], $directory->configuredSlugs());
        $this->assertSame([], $directory->repositories());
        Http::assertNothingSent();
    }

    public function test_missing_approval_table_keeps_existing_env_config_usable(): void
    {
        \Illuminate\Support\Facades\Schema::drop('github_allowlist_proposal_events');
        \Illuminate\Support\Facades\Schema::drop('github_allowlist_proposals');
        config(['portfolio.github_public_repos' => ['owner/repo']]);
        $this->assertSame(['owner/repo'], app(PublicRepositoryDirectory::class)->configuredSlugs());
    }

    public function test_malformed_env_config_does_not_hide_approved_repositories(): void
    {
        $this->proposal('acme/public');
        config(['portfolio.github_public_repos' => 'unexpected-string']);
        $this->assertSame(['acme/public'],
            app(PublicRepositoryDirectory::class)->configuredSlugs());
    }
}

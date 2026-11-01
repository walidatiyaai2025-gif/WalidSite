<?php

namespace Tests\Feature\GitHub;

use App\Integrations\GitHub\PublicRepositoryDirectory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicRepositoryDirectoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['portfolio.github_public_repos' => []]);
    }

    private function publicResponse(): array
    {
        return [
            'full_name' => 'owner/public-repo',
            'html_url' => 'https://github.com/owner/public-repo',
            'private' => false,
            'visibility' => 'public',
            'description' => 'Public description',
            'language' => 'PHP',
            'stargazers_count' => 123,
        ];
    }

    public function test_empty_allowlist_never_contacts_github(): void
    {
        Http::fake();
        $this->assertSame([], app(PublicRepositoryDirectory::class)->repositories());
        Http::assertNothingSent();
    }

    public function test_verified_public_repos_only_and_timestamped_short_cache(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo', 'OWNER/PUBLIC-REPO']]);
        Http::fake(['api.github.com/repos/*' => Http::response($this->publicResponse(), 200)]);
        $directory = app(PublicRepositoryDirectory::class);
        $one = $directory->repositories();
        $this->assertCount(1, $one);
        $this->assertSame('verified', $one[0]['status']);
        $this->assertSame('https://github.com/owner/public-repo', $one[0]['url']);
        $this->assertNotEmpty($one[0]['verified_at']);
        $this->assertSame('github_public_rest', $one[0]['source']);
        $this->assertArrayNotHasKey('stargazers_count', $one[0]);
        $this->assertSame($one, $directory->repositories());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/owner/public-repo'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_private_or_unverified_repository_is_never_shown(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response(array_merge($this->publicResponse(), [
            'private' => true,
            'visibility' => 'private',
        ]), 200)]);
        $repo = app(PublicRepositoryDirectory::class)->repositories()[0];
        $this->assertSame('unavailable', $repo['status']);
        $this->assertArrayNotHasKey('url', $repo);
        $this->assertNull($repo['verified_at']);
    }

    public function test_repository_identity_and_github_host_are_strictly_verified(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response(array_merge($this->publicResponse(), [
            'html_url' => 'https://github.com.attacker.test/owner/public-repo',
        ]), 200)]);
        $repo = app(PublicRepositoryDirectory::class)->repositories()[0];
        $this->assertSame('unavailable', $repo['status']);
        $this->assertArrayNotHasKey('url', $repo);
    }

    public function test_credential_bearing_or_noncanonical_github_links_are_never_published(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);

        foreach ([
            'https://visitor:secret@github.com/owner/public-repo',
            'https://visitor@github.com/owner/public-repo',
            'https://github.com:8443/owner/public-repo',
            'https://github.com:443/owner/public-repo',
            'https://github.com/owner/public-repo?token=secret',
            'https://github.com/owner/public-repo#token=secret',
        ] as $untrusted) {
            Cache::flush();
            Http::fake([
                'api.github.com/repos/*' => Http::response(array_merge(
                    $this->publicResponse(), ['html_url' => $untrusted]
                ), 200),
            ]);

            $repo = app(PublicRepositoryDirectory::class)->repositories()[0];
            $this->assertSame('unavailable', $repo['status'], $untrusted);
            $this->assertArrayNotHasKey('url', $repo, $untrusted);
            Http::assertSentCount(1);
        }
    }

    public function test_metadata_redirect_is_not_treated_as_verified_public_repository(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake([
            'api.github.com/repos/*' => Http::response([], 302, [
                'Location' => 'http://127.0.0.1/private/metadata',
            ]),
            '127.0.0.1/*' => Http::response($this->publicResponse(), 200),
        ]);

        $repo = app(PublicRepositoryDirectory::class)->repositories()[0];
        $this->assertSame('unavailable', $repo['status']);
        $this->assertArrayNotHasKey('url', $repo);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() ===
            'https://api.github.com/repos/owner/public-repo');
    }

    public function test_untrusted_slugs_are_rejected_before_http_request(): void
    {
        config(['portfolio.github_public_repos' => ['https://github.com/owner/repo', 'owner/../private', 'owner/repo?token=x', 'owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response($this->publicResponse(), 200)]);
        $repos = app(PublicRepositoryDirectory::class)->repositories();
        $this->assertCount(1, $repos);
        Http::assertSentCount(1);
    }

    public function test_network_failure_fails_closed_without_a_stale_public_link(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(fn () => throw new ConnectionException('Network unavailable'));
        $repo = app(PublicRepositoryDirectory::class)->repositories()[0];
        $this->assertSame('unavailable', $repo['status']);
        $this->assertArrayNotHasKey('url', $repo);
    }
}

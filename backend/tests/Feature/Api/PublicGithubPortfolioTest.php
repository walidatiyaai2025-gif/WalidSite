<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicGithubPortfolioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['portfolio.github_public_repos' => []]);
    }

    private function verifiedRepo(string $slug = 'owner/public-repo'): array
    {
        return [
            'full_name' => $slug,
            'html_url' => 'https://github.com/'.$slug,
            'private' => false,
            'visibility' => 'public',
            'description' => 'Verified public integration guide',
            'language' => 'PHP',
        ];
    }

    public function test_anonymous_empty_allowlist_never_invents_repositories_or_network_calls(): void
    {
        Http::fake();
        $this->getJson('/api/v1/public/github/portfolio')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.lang', 'en')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/public/github/portfolio?lang=ar')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.lang', 'ar');
        Http::assertNothingSent();
    }

    public function test_verified_repository_reuses_exact_public_rest_proof_and_bilingual_filters(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response($this->verifiedRepo(), 200)]);

        $response = $this->getJson('/api/v1/public/github/portfolio?lang=ar&q=INTEGRATION&language=PHP')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.lang', 'ar')
            ->assertJsonPath('data.0.full_name', 'owner/public-repo')
            ->assertJsonPath('data.0.url', 'https://github.com/owner/public-repo')
            ->assertJsonPath('data.0.description', 'Verified public integration guide')
            ->assertJsonPath('data.0.language', 'PHP')
            ->assertJsonPath('data.0.status', 'verified')
            ->assertJsonPath('data.0.source', 'github_public_rest');

        $this->assertNotEmpty($response->json('data.0.verified_at'));
        $this->assertEqualsCanonicalizing(
            ['full_name', 'url', 'description', 'language', 'status', 'verified_at', 'source'],
            array_keys($response->json('data.0'))
        );
        $this->getJson('/api/v1/public/github/portfolio?q=not-present')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/public/github/portfolio?language=Flutter')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/github/portfolio?q=PUBLIC-REPO')
            ->assertOk()->assertJsonCount(1, 'data');

        // Verification cache belongs to the existing shared read-only service.
        Http::assertSentCount(1);
    }

    public function test_private_and_deleted_allowlist_entries_never_leak_even_through_search(): void
    {
        config(['portfolio.github_public_repos' => [
            'owner/private-repo', 'owner/deleted-repo', 'owner/public-repo',
        ]]);
        Http::fake([
            'api.github.com/repos/owner/private-repo' =>
                Http::response(array_merge($this->verifiedRepo('owner/private-repo'), [
                    'private' => true, 'visibility' => 'private',
                    'description' => 'CONFIDENTIAL_WORK',
                ]), 200),
            'api.github.com/repos/owner/deleted-repo' => Http::response([], 404),
            'api.github.com/repos/owner/public-repo' =>
                Http::response($this->verifiedRepo(), 200),
        ]);

        $this->getJson('/api/v1/public/github/portfolio?lang=en')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.full_name', 'owner/public-repo')
            ->assertDontSee('owner/private-repo')
            ->assertDontSee('owner/deleted-repo')
            ->assertDontSee('CONFIDENTIAL_WORK');

        foreach (['private-repo', 'deleted-repo', 'CONFIDENTIAL_WORK'] as $term) {
            $this->getJson('/api/v1/public/github/portfolio?q='.$term)
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0)
                ->assertDontSee('owner/private-repo')
                ->assertDontSee('owner/deleted-repo');
        }
    }

    public function test_tampered_repository_metadata_cannot_become_public_api_proof(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response(
            array_merge($this->verifiedRepo(), [
                'html_url' => 'https://github.com@intranet.local/owner/public-repo',
            ]), 200
        )]);
        $this->getJson('/api/v1/public/github/portfolio')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertDontSee('intranet.local')
            ->assertDontSee('owner/public-repo');
    }

    public function test_invalid_locale_and_nested_filters_get_validation_errors(): void
    {
        Http::fake();
        foreach ([
            '/api/v1/public/github/portfolio?lang=fr',
            '/api/v1/public/github/portfolio?lang[]=ar',
            '/api/v1/public/github/portfolio?q[]=public',
            '/api/v1/public/github/portfolio?language[]=PHP',
            '/api/v1/public/github/portfolio?q='.str_repeat('x', 81),
            '/api/v1/public/github/portfolio?language='.str_repeat('x', 61),
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }
}

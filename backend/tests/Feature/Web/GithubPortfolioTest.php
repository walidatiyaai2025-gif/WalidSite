<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubPortfolioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['portfolio.github_public_repos' => []]);
    }

    private function verifiedRepo(): array
    {
        return [
            'full_name' => 'owner/public-repo',
            'html_url' => 'https://github.com/owner/public-repo',
            'private' => false,
            'visibility' => 'public',
            'description' => 'Real open source work',
            'language' => 'PHP',
        ];
    }

    public function test_empty_portfolio_is_honest_and_accessible_without_outbound_calls(): void
    {
        Http::fake();
        $this->get('/github')->assertOk()
            ->assertSee('GitHub portfolio')
            ->assertSee('lang="en"', false)
            ->assertSee('No approved public repositories to show yet')
            ->assertSee('role="search"', false)
            ->assertSee('href="#main-content"', false);
        Http::assertNothingSent();
    }

    public function test_rtl_and_locale_switch_preserve_github_page(): void
    {
        Http::fake();
        $this->get('/github?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مشاريعي على GitHub')
            ->assertSee('href="'.route('public.github', ['lang' => 'en']).'"', false);
    }

    public function test_verified_public_repository_renders_real_link_and_timestamp(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response($this->verifiedRepo(), 200)]);
        $this->get('/github')->assertOk()
            ->assertSee('owner/public-repo')
            ->assertSee('Real open source work')
            ->assertSee('Last verified')
            ->assertSee('https://github.com/owner/public-repo')
            ->assertSee('rel="noopener noreferrer"', false);
        Http::assertSentCount(1);
    }

    public function test_private_repository_never_renders_clickable_link(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response(array_merge($this->verifiedRepo(), ['private' => true, 'visibility' => 'private']), 200)]);
        $this->get('/github')->assertOk()
            ->assertSee('Verification unavailable')
            ->assertDontSee('href="https://github.com/owner/public-repo"', false);
    }

    public function test_search_and_language_filters_affect_actual_cards(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response($this->verifiedRepo(), 200)]);
        $this->get('/github?q=unknown&language=PHP')->assertOk()->assertSee('No repositories match');
        $this->get('/github?q=real&language=PHP')->assertOk()->assertSee('View repository');
        $this->get('/github?language=Flutter')->assertOk()->assertSee('No repositories match');
    }
}

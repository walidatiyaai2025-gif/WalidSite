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
            ->assertSee('Repository unavailable')
            ->assertDontSee('owner/public-repo')
            ->assertDontSee('Real open source work')
            ->assertDontSee('href="https://github.com/owner/public-repo"', false);
        $this->get('/github?lang=ar')->assertOk()
            ->assertSee('مستودع غير متاح')
            ->assertDontSee('owner/public-repo');
    }

    public function test_unverified_or_deleted_allowlist_names_cannot_be_discovered_by_search(): void
    {
        config(['portfolio.github_public_repos' => ['internal-owner/private-project']]);
        Http::fake(['api.github.com/repos/*' => Http::response([], 404)]);

        // An allowlisted slug is not verified public content. An unavailable
        // card can explain the failed verification without disclosing the
        // private or deleted repository identifier.
        $this->get('/github')->assertOk()
            ->assertSee('Repository unavailable')
            ->assertSee('Verification unavailable')
            ->assertDontSee('internal-owner/private-project')
            ->assertDontSee('https://github.com/internal-owner/private-project');

        $this->get('/github?lang=ar')->assertOk()
            ->assertSee('مستودع غير متاح')
            ->assertDontSee('internal-owner/private-project');

        // Searching for a known/guessed private name must return the same
        // empty result as an unrelated term, not confirm the allowlist entry.
        $this->get('/github?q=private-project')->assertOk()
            ->assertSee('No repositories match')
            ->assertDontSee('Repository unavailable');
        $this->get('/github?q=internal-owner')->assertOk()
            ->assertSee('No repositories match')
            ->assertDontSee('Repository unavailable');

        // No unavailable record is cached as if it had been verified.
        Http::assertSentCount(4);
    }

    public function test_array_valued_github_filters_fail_to_neutral_filters_without_warning(): void
    {
        config(['portfolio.github_public_repos' => ['owner/public-repo']]);
        Http::fake(['api.github.com/repos/*' => Http::response($this->verifiedRepo(), 200)]);

        // A request can supply nested arrays instead of strings. The verified
        // repository must still render, rather than erroring or interpreting
        // PHP's "Array" conversion as an actual search/filter value.
        $this->get('/github?q[]=unknown&language[]=Flutter')->assertOk()
            ->assertSee('owner/public-repo')
            ->assertSee('View repository')
            ->assertSee('value=""', false)
            ->assertDontSee('No repositories match');

        $this->get('/github?lang=ar&q[query][]=unknown&language[choice]=Flutter')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('owner/public-repo')
            ->assertDontSee('No repositories match');

        // A valid scalar filter still applies even if the *other* filter is
        // malformed; invalid array values never override valid user choices.
        $this->get('/github?q[]=unknown&language=Flutter')->assertOk()
            ->assertSee('No repositories match');
        $this->get('/github?q=real&language[]=Flutter')->assertOk()
            ->assertSee('View repository');

        Http::assertSentCount(1);
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

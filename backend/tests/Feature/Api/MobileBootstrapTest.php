<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_app_bootstrap_exposes_only_real_guest_capabilities(): void
    {
        config([
            'app.locale' => 'en',
            'app.key' => 'synthetic-secret-never-public',
            'site.setup_token' => 'synthetic-setup-token-never-public',
            'site.release_ref' => 'internal-release-ref',
        ]);
        Http::fake();
        $result = $this->getJson('/api/v1/public/app-bootstrap')->assertOk()
            ->assertJsonPath('data.app_name', 'Waleed Tech Companion')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.default_locale', 'en')
            ->assertJsonPath('data.supported_locales', ['en', 'ar'])
            ->assertJsonPath('data.guest_entry_allowed', true)
            ->assertJsonPath('data.available_themes', ['light'])
            ->assertJsonPath('data.availability.guest_reading', true)
            ->assertJsonPath('data.availability.guest_runbooks', true)
            ->assertJsonPath('data.availability.guest_learning_paths', true)
            ->assertJsonPath('data.availability.authenticated_ai_diagnosis', false)
            ->assertJsonPath('data.availability.server_saved_resources', false)
            ->assertJsonPath('data.privacy_policy_url', null)
            ->assertJsonMissingPath('data.admin')
            ->assertJsonMissingPath('data.setup_token')
            ->assertJsonMissingPath('data.publisher_key');

        foreach ([
            'home' => 'api.mobile.home',
            'articles' => 'api.mobile.articles',
            'runbooks' => 'api.mobile.runbooks',
            'learning_paths' => 'api.mobile.learn',
        ] as $name => $route) {
            $this->assertTrue(Route::has($route), 'Bootstrap advertised a missing route.');
            $result->assertJsonPath('data.routes.'.$name, route($route, [], false));
        }
        $this->assertStringContainsString('no-store', (string) $result->headers->get('Cache-Control'));
        foreach (['synthetic-secret-never-public', 'synthetic-setup-token-never-public',
            'internal-release-ref', 'SITE_SETUP_ENABLED', 'DB_PASSWORD'] as $secret) {
            $result->assertDontSee($secret);
        }
        Http::assertNothingSent();
    }

    public function test_requested_arabic_locale_and_mobile_guest_preferences_are_consistent(): void
    {
        config(['app.locale' => 'ar']);
        Http::fake();

        $bootstrap = $this->getJson('/api/v1/public/app-bootstrap')->assertOk()
            ->assertJsonPath('data.app_name', 'رفيق وليد التقني')
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.locale', 'ar');
        $preferences = $this->getJson('/api/v1/mobile/preferences')->assertOk()
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.supported_locales', ['en', 'ar'])
            ->assertJsonPath('data.available_themes', ['light'])
            ->assertJsonPath('data.theme', 'light')
            ->assertJsonPath('data.locale_saved_on_server', false)
            ->assertJsonPath('data.requires_authentication', false)
            ->assertJsonMissingPath('data.user_id');

        $this->assertStringContainsString('no-store',
            (string) $preferences->headers->get('Cache-Control'));
        $this->getJson('/api/v1/public/app-bootstrap?lang=en')->assertOk()
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.app_name', 'Waleed Tech Companion');
        $this->getJson('/api/v1/mobile/preferences?lang=en')->assertOk()
            ->assertJsonPath('data.default_locale', 'ar')
            ->assertJsonPath('data.locale', 'en');
        $this->getJson('/api/v1/mobile/preferences')->assertOk()
            ->assertJsonPath('data.locale', 'ar');
        $bootstrap->assertJsonMissingPath('data.preferences_saved');
        Http::assertNothingSent();
    }

    public function test_invalid_configured_default_fails_to_safe_english_without_leaking_input(): void
    {
        config(['app.locale' => 'xx-internal-secret-locale']);
        $this->getJson('/api/v1/public/app-bootstrap')->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.default_locale', 'en')
            ->assertDontSee('xx-internal-secret-locale');
        $this->getJson('/api/v1/mobile/preferences?lang=ar')->assertOk()
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.default_locale', 'en')
            ->assertDontSee('xx-internal-secret-locale');
    }

    public function test_unsupported_query_and_array_locale_are_rejected_on_both_public_contracts(): void
    {
        Http::fake();
        foreach (['/api/v1/public/app-bootstrap', '/api/v1/mobile/preferences'] as $uri) {
            foreach (['?lang=fr', '?lang[]=en', '?page=1', '?page[]=2'] as $query) {
                $this->getJson($uri.$query)->assertUnprocessable();
            }
        }
        Http::assertNothingSent();
    }
}

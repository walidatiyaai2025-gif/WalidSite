<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

final class LatestVerifiedAndroidRoutesTest extends TestCase
{
    public function test_stable_binary_and_metadata_routes_are_not_a_debug_qa_fallback(): void
    {
        config([
            'app.url' => 'https://walidatiya.com',
            'site.android_download_enabled' => false,
        ]);
        foreach ([
            'https://walidatiya.com/downloads/android/latest',
            'https://walidatiya.com/downloads/android/latest/metadata',
        ] as $url) {
            $response = $this->get($url)->assertStatus(503);
            $response->assertDontSee('app-debug.apk')
                ->assertDontSee('actions/runs/')
                ->assertDontSee('GITHUB_TOKEN')
                ->assertDontSee('.env');
        }
    }

    public function test_https_origin_and_request_host_are_required_even_when_feature_is_enabled(): void
    {
        config([
            'app.url' => 'https://walidatiya.com',
            'site.android_download_enabled' => true,
            'site.android_trusted_signer_sha256' => str_repeat('c', 64),
        ]);
        $this->get('http://walidatiya.com/downloads/android/latest')->assertStatus(503);
        $this->get('https://untrusted.example.com/downloads/android/latest')->assertStatus(503);
        $this->get('https://walidatiya.com/downloads/android/latest')->assertStatus(503);
        // Even when a private feature toggle is enabled, no forged web
        // pairing or local QA archive may be advertised as production-ready.
        $this->get('https://walidatiya.com/downloads/android/latest/metadata')
            ->assertStatus(503)
            ->assertDontSee('VERIFIED_COMPATIBLE');
    }

    public function test_routes_are_named_permanent_paths_not_external_redirects(): void
    {
        config(['app.url' => 'https://walidatiya.com']);
        $this->assertSame(
            'https://walidatiya.com/downloads/android/latest',
            route('public.android.latest', [], true)
        );
    }
}

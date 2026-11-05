<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminTrustedUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function token(string $role = 'owner', string $ability = 'admin', string $status = 'active'): string
    {
        return $this->user($role, $status)
            ->createToken('release-check', [$ability], now()->addHour())
            ->plainTextToken;
    }

    private function configuration(string $key, ?string $version = null): void
    {
        config()->set('site.updates_enabled', true);
        // A signed update is eligible only against the actual deployed code.
        config()->set('site.trusted_installed_version', $version ?? trim(
            (string) file_get_contents(base_path('VERSION')),
        ));
        config()->set('site.trusted_release_origin', 'https://updates.example.com');
        config()->set('site.trusted_release_public_key', $key);
    }

    private function envelope(string $secretKey): string
    {
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '1.2.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 1234,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v1.2.0.zip',
        ], JSON_THROW_ON_ERROR);
        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secretKey)),
        ], JSON_THROW_ON_ERROR);
    }

    public function test_owner_admin_token_can_check_publisher_signed_release_without_apply_or_secrets(): void
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('Ed25519 is required.');
        }
        $pair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $envelope = $this->envelope(sodium_crypto_sign_secretkey($pair));
        $this->configuration($publicKey);
        $requests = 0;
        $this->app->instance(TrustedReleaseCatalog::class,
            new TrustedReleaseCatalog(static function (string $url, \Closure $write) use (
                &$requests, $envelope,
            ): int {
                $requests++;
                self::assertSame('https://updates.example.com/releases/latest.json', $url);
                self::assertSame(strlen($envelope), $write($envelope));
                return 200;
            }),
        );
        $this->withToken($this->token());
        $response = $this->getJson('/api/v1/admin/system/updates/check?lang=ar')
            ->assertOk()
            ->assertJsonPath('data.current_version', trim((string) file_get_contents(base_path('VERSION'))))
            ->assertJsonPath('data.available_version', '1.2.0')
            ->assertJsonPath('data.source_sha', str_repeat('a', 40))
            ->assertJsonPath('data.artifact_sha256', str_repeat('b', 64))
            ->assertJsonPath('data.status', 'signed_release_available')
            ->assertJsonPath('data.apply_available', false)
            ->assertJsonPath('data.code_update_ready', false)
            ->assertJsonPath('meta.publisher_verified', true)
            ->assertJsonPath('meta.lang', 'ar');
        self::assertSame(1, $requests);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        foreach ([$publicKey, $envelope, 'updates.example.com'] as $secretOrEndpoint) {
            self::assertStringNotContainsString($secretOrEndpoint, $response->getContent());
        }
    }

    public function test_stale_or_foreign_configured_version_never_checks_publisher_or_claims_installed_code(): void
    {
        // This test makes four requests in one minute; isolate the trust
        // boundary from the unrelated production throttle:3,1 policy.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        // Signature verification alone cannot repair a false source-version
        // identity, even if the configured publisher key is legitimate.
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function () use (&$calls): int {
                $calls++;
                throw new \LogicException('Network should not be reached for a stale installed version');
            },
        ));
        $this->withToken($this->token());
        $realVersion = trim((string) file_get_contents(base_path('VERSION')));
        if (! function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Ed25519 required for trusted-release fixtures.');
        }
        $realKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        foreach (['0.0.0', '9.9.9', 'unidentified', ''] as $incorrectVersion) {
            $this->configuration($realKey, $incorrectVersion);
            $response = $this->getJson('/api/v1/admin/system/updates/check?lang=ar')
                ->assertOk()
                ->assertJsonPath('data.current_version', $realVersion)
                ->assertJsonPath('data.available_version', null)
                ->assertJsonPath('data.status', 'not_configured')
                ->assertJsonPath('data.apply_available', false)
                ->assertJsonPath('data.code_update_ready', false)
                ->assertJsonPath('meta.publisher_verified', false);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertStringNotContainsString($realKey, $response->getContent());
        }
        self::assertSame(0, $calls);
    }

    public function test_unconfigured_or_disabled_release_check_has_no_network_and_no_false_update_claim(): void
    {
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function () use (&$calls): int {
                $calls++;
                return 200;
            },
        ));
        $this->withToken($this->token());
        config()->set('site.updates_enabled', true);
        config()->set('site.trusted_release_origin', null);
        config()->set('site.trusted_release_public_key', null);
        config()->set('site.trusted_installed_version', 'unidentified');
        $this->getJson('/api/v1/admin/system/updates/check')->assertOk()
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.available_version', null)
            ->assertJsonPath('data.apply_available', false)
            ->assertJsonPath('meta.publisher_verified', false);
        config()->set('site.updates_enabled', false);
        $this->getJson('/api/v1/admin/system/updates/check')->assertOk()
            ->assertJsonPath('data.status', 'disabled');
        self::assertSame(0, $calls);
    }

    public function test_unverified_publisher_catalog_does_not_report_an_eligible_release(): void
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('Ed25519 is required.');
        }
        $pair = sodium_crypto_sign_keypair();
        $otherPair = sodium_crypto_sign_keypair();
        $this->configuration(base64_encode(sodium_crypto_sign_publickey($pair)));
        $wrongEnvelope = $this->envelope(sodium_crypto_sign_secretkey($otherPair));
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function (string $url, \Closure $write) use ($wrongEnvelope): int {
                $write($wrongEnvelope);
                return 200;
            },
        ));
        $this->withToken($this->token());
        $response = $this->getJson('/api/v1/admin/system/updates/check')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'publisher_unverified')
            ->assertJsonPath('data.available_version', null)
            ->assertJsonPath('data.apply_available', false)
            ->assertJsonPath('meta.publisher_verified', false);
        self::assertStringNotContainsString($wrongEnvelope, $response->getContent());
    }

    public function test_guest_mobile_admin_nonowner_suspended_and_web_session_cannot_check_release(): void
    {
        $url = '/api/v1/admin/system/updates/check';
        $this->getJson($url)->assertUnauthorized();
        foreach ([
            ['owner', 'mobile', 'active'],
            ['admin', 'admin', 'active'],
            ['reader', 'admin', 'active'],
            ['owner', 'admin', 'inactive'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status))
                ->getJson($url)->assertForbidden();
        }
        Auth::forgetGuards();
        $this->actingAs($this->user(), 'web')->getJson($url)->assertForbidden();
    }

    public function test_query_cannot_override_publisher_key_origin_or_installed_version(): void
    {
        // Isolate input validation: the production route still enforces throttle:3,1.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function () use (&$calls): int {
                $calls++;
                return 200;
            },
        ));
        $this->withToken($this->token());
        foreach (['origin', 'publisher_key', 'installed_version', 'url'] as $field) {
            $this->getJson('/api/v1/admin/system/updates/check?'.$field.'=https%3A%2F%2Fevil.example')
                ->assertUnprocessable();
        }
        self::assertSame(0, $calls);
    }
}

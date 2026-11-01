<?php

namespace Tests\Feature\AI;

use App\AI\Adapters\OllamaAdapter;
use App\AI\Adapters\OpenAiCompatibleAdapter;
use App\AI\ProviderEndpoint;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderAdaptersTest extends TestCase
{
    public function test_loopback_ollama_health_probe_uses_tags_without_inference(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::response(['models' => []], 200),
        ]);

        $this->assertTrue((new OllamaAdapter)->checkHealth('http://127.0.0.1:11434'));
        Http::assertSentCount(1);
    }

    public function test_managed_provider_requires_secret_and_valid_models_response(): void
    {
        Http::fake([
            'https://models.example.test/v1/models' => Http::response(['data' => []], 200),
        ]);

        $adapter = new OpenAiCompatibleAdapter;

        $this->assertFalse($adapter->checkHealth('https://models.example.test/v1'));
        Http::assertNothingSent();
        $this->assertTrue($adapter->checkHealth('https://models.example.test/v1', 'dummy-test-token'));
        Http::assertSent(fn ($request) =>
            $request->hasHeader('Authorization', 'Bearer dummy-test-token')
        );
    }

    public function test_failed_health_check_never_reports_success(): void
    {
        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $this->assertFalse((new OllamaAdapter)->checkHealth('http://127.0.0.1:11434'));
    }

    public function test_disallows_insecure_remote_http_and_embedded_credentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProviderEndpoint::normalize('http://192.168.1.22:11434');
    }


    public function test_blocks_private_bracketed_ipv6_and_alternate_numeric_host_destinations(): void
    {
        foreach ([
            'https://[::ffff:127.0.0.1]/v1',
            'https://[::ffff:169.254.169.254]/v1',
            'https://[fc00::1]/v1',
            'https://[fe80::1]/v1',
            'http://[::ffff:127.0.0.1]:11434',
            'https://2130706433/v1',
            'https://0177.0.0.1/v1',
            'https://0x7f000001/v1',
            'https://127.1/v1',
        ] as $endpoint) {
            try {
                ProviderEndpoint::normalize($endpoint);
                $this->fail('Private or ambiguous numeric provider address was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertContains($exception->getMessage(), [
                    'Unsafe provider URL.',
                    'Remote provider endpoints require HTTPS.',
                    'Private network endpoints are not allowed.',
                ]);
            }
        }
    }

    public function test_rejects_internal_dns_names_even_when_provider_url_uses_https(): void
    {
        foreach ([
            'https://metadata.google.internal/v1',
            'https://service.local:443/api',
            'https://kubernetes.default.svc/v1',
            'https://dashboard.cluster.local/v1',
            'https://router.lan/v1',
            'https://server.home/v1',
            'https://admin.localhost/v1',
            'https://example.invalid/v1',
            'https://gateway.onion/v1',
            'https://router.home.arpa/v1',
            'https://service.localdomain/v1',
            'https://9.2.0.192.in-addr.arpa/v1',
            'HTTPS://SERVICE.INTERNAL./v1',
            'https://localhost.evil.internal/v1',
            'https://model', // Single-label destinations can resolve privately.
            'https://api..public.com/v1',
            'https://internal_model.example.com/v1',
        ] as $url) {
            try {
                ProviderEndpoint::normalize($url);
                $this->fail('Internal or malformed provider host was accepted: '.$url);
            } catch (InvalidArgumentException $exception) {
                $this->assertContains($exception->getMessage(), [
                    'Unsafe provider URL.',
                    'Internal provider DNS destinations are not allowed.',
                ]);
            }
        }
    }

    public function test_internal_provider_hosts_are_rejected_before_any_health_request(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'models' => []], 200)]);

        $this->assertFalse((new OpenAiCompatibleAdapter)->checkHealth(
            'https://metadata.google.internal/v1', 'dummy-test-token'
        ));
        $this->assertFalse((new OllamaAdapter)->checkHealth('https://kubernetes.default.svc'));
        Http::assertNothingSent();
    }

    public function test_preserves_canonical_local_ollama_and_public_https_provider_hosts(): void
    {
        foreach ([
            'http://127.0.0.1:11434',
            'http://[::1]:11434',
            'https://api.example.test/v1',
            'https://[2001:4860:4860::8888]/v1',
        ] as $endpoint) {
            $this->assertSame($endpoint, ProviderEndpoint::normalize($endpoint));
        }
    }

    public function test_rejects_userinfo_and_query_from_provider_base_url(): void
    {
        foreach ([
            'https://user:password@example.test/v1',
            'https://example.test/v1?url=http://localhost',
        ] as $url) {
            try {
                ProviderEndpoint::normalize($url);
                $this->fail('Unsafe endpoint should have been rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}

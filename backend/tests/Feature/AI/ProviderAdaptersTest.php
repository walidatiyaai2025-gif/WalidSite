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

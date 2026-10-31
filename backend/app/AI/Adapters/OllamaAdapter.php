<?php

namespace App\AI\Adapters;

use App\AI\Contracts\ProviderAdapter;
use App\AI\ProviderEndpoint;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OllamaAdapter implements ProviderAdapter
{
    public function type(): string
    {
        return 'ollama-compatible';
    }

    public function checkHealth(
        string $baseUrl,
        ?string $secret = null,
        int $timeoutSeconds = 5,
    ): bool {
        try {
            $endpoint = ProviderEndpoint::normalize($baseUrl);

            $response = Http::acceptJson()
                ->timeout(max(1, min(30, $timeoutSeconds)))
                ->withOptions(['allow_redirects' => false])
                ->get($endpoint.'/api/tags');

            return $response->successful() && is_array($response->json('models'));
        } catch (Throwable) {
            // Health state is false, never a fabricated successful connection.
            return false;
        }
    }
}

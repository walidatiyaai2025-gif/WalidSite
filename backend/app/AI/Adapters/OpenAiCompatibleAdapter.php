<?php

namespace App\AI\Adapters;

use App\AI\Contracts\ProviderAdapter;
use App\AI\ProviderEndpoint;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenAiCompatibleAdapter implements ProviderAdapter
{
    public function type(): string
    {
        return 'openai-compatible';
    }

    public function checkHealth(
        string $baseUrl,
        ?string $secret = null,
        int $timeoutSeconds = 5,
    ): bool {
        if ($secret === null || trim($secret) === '') {
            return false;
        }

        try {
            $endpoint = ProviderEndpoint::normalize($baseUrl);

            $response = Http::acceptJson()
                ->withToken($secret)
                ->timeout(max(1, min(30, $timeoutSeconds)))
                ->withOptions(['allow_redirects' => false])
                ->get($endpoint.'/models');

            return $response->successful() && is_array($response->json('data'));
        } catch (Throwable) {
            return false;
        }
    }
}

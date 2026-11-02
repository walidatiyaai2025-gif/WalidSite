<?php

namespace App\AI;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class ProviderUsage
{
    /**
     * Normalize only provider-reported counters. Missing usage is allowed
     * because compatible/self-hosted endpoints may omit it; a partial,
     * negative, non-integer or implausibly large report fails closed.
     *
     * @return array{input_tokens:int,output_tokens:int}|array{}
     */
    public static function tokens(string $providerType, Response $response): array
    {
        [$input, $output] = match ($providerType) {
            'openai-compatible' => [
                $response->json('usage.prompt_tokens'),
                $response->json('usage.completion_tokens'),
            ],
            'ollama-compatible' => [
                $response->json('prompt_eval_count'),
                $response->json('eval_count'),
            ],
            default => [null, null],
        };

        if ($input === null && $output === null) {
            return [];
        }

        if (! is_int($input) || ! is_int($output)
            || $input < 0 || $output < 0
            || $input > 100_000_000 || $output > 100_000_000) {
            throw new RuntimeException('Provider usage counters are malformed.');
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
        ];
    }
}

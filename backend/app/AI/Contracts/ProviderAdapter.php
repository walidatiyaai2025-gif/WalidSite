<?php

namespace App\AI\Contracts;

interface ProviderAdapter
{
    public function type(): string;

    /**
     * Only a transport health probe. It must not perform paid inference,
     * return any credentials, or pretend model capabilities are verified.
     */
    public function checkHealth(
        string $baseUrl,
        ?string $secret = null,
        int $timeoutSeconds = 5,
    ): bool;
}

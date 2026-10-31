<?php

namespace App\AI;

use InvalidArgumentException;

final class ProviderEndpoint
{
    /**
     * Accept HTTPS provider endpoints or HTTP loopback for a local Ollama service.
     * Reject credentials and input that could change the destination of a request.
     * Admin-configurable remote HTTP must use a TLS proxy.
     */
    public static function normalize(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $parts = parse_url($baseUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('A complete provider URL is required.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['https', 'http'], true) ||
            isset($parts['user'], $parts['pass']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['query']) ||
            isset($parts['fragment']) ||
            $host === '') {
            throw new InvalidArgumentException('Unsafe provider URL.');
        }

        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new InvalidArgumentException('Remote provider endpoints require HTTPS.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) &&
            ! in_array($host, ['127.0.0.1', '::1'], true) &&
            ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('Private network endpoints are not allowed.');
        }

        return rtrim($baseUrl, '/');
    }
}

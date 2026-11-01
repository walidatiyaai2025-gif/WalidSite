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

        // parse_url() retains square brackets for IPv6 literals. Strip them
        // solely for IP classification so mapped private/metadata targets
        // cannot masquerade as unclassified DNS hostnames.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Unsafe provider URL.');
            }
        } elseif (str_contains($host, '[') || str_contains($host, ']')) {
            throw new InvalidArgumentException('Unsafe provider URL.');
        }

        // HTTP clients may resolve shorthand IPv4, octal or hexadecimal
        // host forms as loopback/private IPs even if FILTER_VALIDATE_IP
        // does not classify them. Never accept ambiguous numeric DNS names.
        if (filter_var($host, FILTER_VALIDATE_IP) === false &&
            preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/iD', $host) === 1) {
            throw new InvalidArgumentException('Unsafe provider URL.');
        }

        // HTTPS is not proof that a DNS name belongs to a public provider.
        // Reserved/internal resolver suffixes can address cluster, home LAN
        // or metadata services even when the address is not an IP literal.
        // Keep intentional loopback Ollama as an explicit special case.
        if (filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'localhost') {
            $dnsHost = rtrim($host, '.');
            if (strlen($dnsHost) > 253
                || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/iD', $dnsHost) !== 1) {
                throw new InvalidArgumentException('Unsafe provider URL.');
            }

            foreach (['localhost', 'local', 'internal', 'svc', 'lan', 'home', 'invalid', 'onion'] as $suffix) {
                if ($dnsHost === $suffix || str_ends_with($dnsHost, '.'.$suffix)) {
                    throw new InvalidArgumentException('Internal provider DNS destinations are not allowed.');
                }
            }
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

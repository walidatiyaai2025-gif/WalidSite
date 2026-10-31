<?php

namespace App\AI;

use InvalidArgumentException;

/**
 * Validates candidate citation URLs before editorial evidence review.
 *
 * This class performs no network requests and never marks a source verified.
 * Any future fetcher must additionally resolve and deny private IPs and apply
 * an egress allowlist before connecting.
 */
final class ResearchSourceIntake
{
    private const MAX_SOURCES = 20;
    private const MAX_URL_LENGTH = 2048;

    /**
     * @param array<int, mixed> $sourceUrls
     * @return list<array{source_url: string, verified: false}>
     */
    public function candidateCitations(array $sourceUrls): array
    {
        if ($sourceUrls === [] || count($sourceUrls) > self::MAX_SOURCES) {
            throw new InvalidArgumentException('Provide between one and twenty research sources.');
        }

        $candidates = [];
        foreach ($sourceUrls as $sourceUrl) {
            if (! is_string($sourceUrl)) {
                throw new InvalidArgumentException('Research source URLs must be strings.');
            }

            $normalized = $this->normalizePublicHttpsUrl($sourceUrl);
            $candidates[$normalized] = [
                'source_url' => $normalized,
                'verified' => false,
            ];
        }

        return array_values($candidates);
    }

    private function normalizePublicHttpsUrl(string $sourceUrl): string
    {
        $sourceUrl = trim($sourceUrl);
        $parts = parse_url($sourceUrl);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('Research sources must use a safe public HTTPS URL.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = $parts['port'] ?? null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $query = (string) ($parts['query'] ?? '');

        if (strlen($sourceUrl) > self::MAX_URL_LENGTH
            || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            || $scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ($port !== null && $port !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ! str_contains($host, '.')
            || $this->isLocalHost($host)
            || preg_match('/(?:^|&)(?:access[_-]?token|api[_-]?key|auth(?:orization)?|password|secret|signature|session)=/i', $query) === 1) {
            throw new InvalidArgumentException('Research sources must use a safe public HTTPS URL.');
        }

        $url = 'https://'.$host.(string) ($parts['path'] ?? '');
        if (array_key_exists('query', $parts)) {
            $url .= '?'.$parts['query'];
        }
        if (array_key_exists('fragment', $parts)) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }

    private function isLocalHost(string $host): bool
    {
        foreach (['localhost', '.localhost', '.local', '.internal', '.test', '.invalid'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

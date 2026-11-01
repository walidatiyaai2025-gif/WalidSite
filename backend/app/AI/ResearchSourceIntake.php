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
            || $this->containsCredentialParameter($query)
            || $this->containsCredentialParameter((string) ($parts['fragment'] ?? ''))) {
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

    /**
     * Citation URLs are public, shareable evidence, never signed download links
     * or credential containers. Check encoded keys, fragments and nested URLs
     * without making any network request or logging their values.
     */
    private function containsCredentialParameter(string $value): bool
    {
        // These candidate URLs can be copied into paid-provider AI prompts,
        // before the later public ArticleRevision evidence safety gate runs.
        // Match its sensitive keys and fail closed on excess nesting.
        for ($depth = 0; $depth < 4; $depth++) {
            if (preg_match(
                '/(?:^|[?#&;])(?:access[_-]?token|refresh[_-]?token|id[_-]?token|token|api[_-]?key|key|auth(?:orization)?|password|passwd|secret|client[_-]?secret|private[_-]?key|signature|sig|session(?:id)?|jwt|bearer|code|ticket|awsaccesskeyid|x[-_]amz[-_][a-z0-9_-]+|x[-_]goog[-_][a-z0-9_-]+)=/i',
                $value,
            ) === 1) {
                return true;
            }

            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                return false;
            }

            $value = $decoded;
        }

        // If four decoding rounds still change the URL, there is no safe
        // public interpretation. Fail closed before a model sees the source.
        return true;
    }

    private function isLocalHost(string $host): bool
    {
        // A candidate can enter the AI provider prompt BEFORE a later
        // publication review. Never copy local DNS names or internal routes
        // into third-party inference, even if HTTPS syntax is valid.
        foreach (['localhost', 'local', 'localdomain', 'internal', 'svc',
            'lan', 'home', 'arpa', 'test', 'invalid', 'onion'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }
}

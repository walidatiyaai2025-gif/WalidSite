<?php

namespace App\Integrations\GitHub;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only, fail-closed repository metadata for the public portfolio.
 *
 * This is NOT an authenticated GitHub connector. It deliberately uses only the
 * unauthenticated public REST endpoint and never accepts a caller-supplied URL.
 */
final class PublicRepositoryDirectory
{
    /**
     * @return list<array<string, mixed>>
     */
    public function repositories(): array
    {
        $configured = config('portfolio.github_public_repos', []);
        if (! is_array($configured)) {
            return [];
        }

        $seen = [];
        $result = [];
        foreach ($configured as $candidate) {
            if (! is_string($candidate) || ! $this->validSlug($candidate)) {
                continue;
            }
            $slug = strtolower($candidate);
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $result[] = $this->fetchOne($candidate);
            if (count($result) >= 20) {
                break;
            }
        }

        return $result;
    }

    private function validSlug(string $slug): bool
    {
        // Reject URLs, slashes beyond owner/repo, traversal and query strings.
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$/D', $slug) === 1
            && ! str_contains($slug, '..');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOne(string $slug): array
    {
        $unavailable = [
            'full_name' => $slug,
            'status' => 'unavailable',
            'verified_at' => null,
        ];
        $key = 'public-github:'.hash('sha256', strtolower($slug));
        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['status'] ?? null) === 'verified') {
            // Cache always retains original verification timestamp.
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'WalidSite-Public-Portfolio', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->connectTimeout(3)
                ->timeout(5)
                // A public metadata lookup must never follow a redirect to
                // another host (including an internal/private destination).
                ->withOptions(['allow_redirects' => false])
                ->get('https://api.github.com/repos/'.$slug);

            // Never reuse a private/deleted/unknown repository snapshot. A network failure
            // also fails closed instead of showing possibly revoked public permissions.
            if (! $response->ok()
                || $response->json('private') !== false
                || $response->json('visibility') !== 'public'
                || ! is_string($response->json('full_name'))
                || strcasecmp($response->json('full_name'), $slug) !== 0) {
                return $unavailable;
            }

            $url = $response->json('html_url');
            $parts = is_string($url) ? parse_url($url) : false;
            if (! is_array($parts)
                || strtolower($parts['scheme'] ?? '') !== 'https'
                || strtolower($parts['host'] ?? '') !== 'github.com'
                || strtolower(trim($parts['path'] ?? '', '/')) !== strtolower($slug)
                // Host/path alone are insufficient: an attacker-controlled
                // URL can also contain credentials or a non-GitHub port.
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                return $unavailable;
            }

            $data = [
                'full_name' => $response->json('full_name'),
                'url' => $url,
                'description' => is_string($response->json('description')) ? $response->json('description') : null,
                'language' => is_string($response->json('language')) ? $response->json('language') : null,
                'status' => 'verified',
                'verified_at' => now()->toIso8601String(),
                'source' => 'github_public_rest',
            ];
            Cache::put($key, $data, now()->addMinutes(5));

            return $data;
        } catch (Throwable) {
            // Never log the URL, headers, credentials or response body here.
            return $unavailable;
        }
    }
}

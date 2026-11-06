<?php

namespace App\Integrations\GitHub;

use App\Models\GithubAllowlistProposal;
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
     * The one canonical, bounded public allowlist. Invalid and repeated names
     * are not configuration slots and cannot be counted as verified sources.
     * The cap applies AFTER validation/deduplication, just as public fetching
     * historically did; never expose rejected or private identifiers.
     *
     * @return list<string>
     */
    public function configuredSlugs(): array
    {
        $configured = config('portfolio.github_public_repos', []);
        if (! is_array($configured)) {
            // A malformed legacy environment value must not block the
            // separately approved DB allowlist.
            $configured = [];
        }

        $seen = [];
        $slugs = [];
        foreach ($configured as $candidate) {
            if (! is_string($candidate) || ! $this->validSlug($candidate)) {
                continue;
            }
            $normalized = strtolower($candidate);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $slugs[] = $candidate;
            if (count($slugs) === 20) {
                return $slugs;
            }
        }

        // Existing environment entries stay first and retain their prior
        // behavior. Only independently owner-approved DB candidates with a
        // real verification receipt can fill remaining slots. A pending or
        // rejected proposal is never permission for public disclosure.
        // Do not query the database when all 20 env slots are occupied.
        try {
            foreach (GithubAllowlistProposal::query()
                ->where('status', 'approved')
                ->whereNotNull('verified_at')
                ->where('verified_at', '<=', now('UTC'))
                ->orderBy('created_at')->orderBy('id')
                ->limit(200)->pluck('repository_slug') as $candidate) {
                if (! is_string($candidate) || ! $this->validSlug($candidate)) {
                    continue;
                }
                $normalized = strtolower($candidate);
                if (isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $slugs[] = $normalized;
                if (count($slugs) === 20) {
                    break;
                }
            }
        } catch (Throwable) {
            // During first-install migrations or a database outage, preserve
            // configured entries without claiming unverified DB approvals.
        }

        return $slugs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function repositories(): array
    {
        $result = [];
        foreach ($this->configuredSlugs() as $slug) {
            $result[] = $this->fetchOne($slug);
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
        // An allowlist entry is not public evidence. GitHub may return 404,
        // private visibility or malformed metadata: do not disclose the
        // configured (possibly private) repository name to the public page
        // or through its search filter until verification succeeds.
        $unavailable = [
            'full_name' => null,
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

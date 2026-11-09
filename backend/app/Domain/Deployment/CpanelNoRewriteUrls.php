<?php

namespace App\Domain\Deployment;

use Illuminate\Routing\UrlGenerator;
use InvalidArgumentException;

/**
 * Explicit cPanel/LiteSpeed fallback when clean URL rewrites are unavailable.
 *
 * Only generated dynamic application URLs get /index.php. Static assets keep
 * their normal document-root URLs. This is not a rewrite-rule substitute:
 * externally requested clean paths still require a working web-server rewrite.
 */
final class CpanelNoRewriteUrls
{
    public static function apply(UrlGenerator $urls, string $siteOrigin, ?string $assetOrigin = null): void
    {
        $parts = parse_url($siteOrigin);

        // Use only the canonical, configured HTTPS site origin. Never derive
        // host, scheme or index.php URL from user-controlled request headers.
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query'])
            || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '') {
            throw new InvalidArgumentException('No-rewrite URL mode requires a canonical HTTPS APP_URL without a path.');
        }

        $siteOrigin = rtrim($siteOrigin, '/');
        $urls->forceScheme('https');
        $urls->useOrigin($siteOrigin.'/index.php');
        // URL::asset must NOT inherit the /index.php prefix. Respect an
        // explicitly configured asset origin (e.g. a pre-approved CDN).
        $urls->useAssetOrigin(is_string($assetOrigin) && $assetOrigin !== ''
            ? rtrim($assetOrigin, '/') : $siteOrigin);
    }
}

<?php

namespace App\Domain\Deployment;

use DomainException;

/**
 * The configured release publisher must be an exact public HTTPS hostname.
 *
 * Catalog discovery and direct signed-archive acquisition MUST use the same
 * origin policy. A valid Ed25519 signature does not authorize changing the
 * network destination to an IP literal, localhost, a single-label name, an
 * alternate port or a URL containing credentials/path/query/fragment.
 */
final class TrustedReleaseOrigin
{
    public static function assertValid(string $origin): void
    {
        $pattern = '~\\Ahttps://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63})\\z~iD';
        if (! preg_match($pattern, $origin, $matches)
            || strlen($matches[1]) > 253
            || filter_var($matches[1], FILTER_VALIDATE_IP) !== false) {
            throw new DomainException('Trusted release origin requires a pinned public HTTPS hostname.');
        }
    }
}

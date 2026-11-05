<?php

namespace App\Domain\Deployment;

use Closure;
use DomainException;

/**
 * DNS-to-cURL trust boundary for the approved, signed-release publisher.
 *
 * Resolve once, reject every local/special-use address in an answer set,
 * then pin the exact checked address for TLS-authenticated HTTPS. Neither
 * a CNAME nor a second cURL DNS lookup may redirect traffic to private hosts.
 */
final class TrustedReleasePublicNetwork
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96',
        '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
        '2001::/32', '2001:2::/48', '2001:10::/28',
        '2001:20::/28', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /**
     * cURL must not consult DNS again after this validated lookup. An optional
     * resolver exists ONLY for isolated no-network tests; the production path
     * always calls PHP's native DNS query and never accepts caller-supplied IPs.
     *
     * @param ?Closure(string):array<int,array<string,string>> $lookup
     */
    public static function resolvePin(string $url, ?Closure $lookup = null): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host']) || ! is_string($parts['host'])
            || isset($parts['port'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new DomainException('Trusted release network destination is invalid.');
        }

        $host = strtolower($parts['host']);
        TrustedReleaseMetadata::assertPinnedHttpsOrigin('https://'.$host);
        if ($lookup === null && ! function_exists('dns_get_record')) {
            throw new DomainException('Trusted release DNS validation is unavailable.');
        }

        $current = $host;
        $visited = [];
        for ($hop = 0; $hop < 5; $hop++) {
            if (isset($visited[$current])) {
                throw new DomainException('Trusted release publisher DNS alias loop.');
            }
            $visited[$current] = true;
            $records = $lookup !== null
                ? $lookup($current)
                : @dns_get_record($current, DNS_A | DNS_AAAA | DNS_CNAME);

            if (! is_array($records) || $records === [] || count($records) > 32) {
                throw new DomainException('Trusted release publisher DNS answer is unavailable.');
            }

            $addresses = [];
            $alias = null;
            foreach ($records as $record) {
                if (! is_array($record)) {
                    throw new DomainException('Trusted release publisher DNS answer is invalid.');
                }
                $type = $record['type'] ?? null;
                if ($type === 'CNAME') {
                    $target = $record['target'] ?? null;
                    if (! is_string($target) || $target === '') {
                        throw new DomainException('Trusted release publisher DNS alias is invalid.');
                    }
                    $target = strtolower(rtrim($target, '.'));
                    TrustedReleaseMetadata::assertPinnedHttpsOrigin('https://'.$target);
                    if ($alias !== null && $alias !== $target) {
                        throw new DomainException('Trusted release publisher DNS aliases conflict.');
                    }
                    $alias = $target;
                    continue;
                }
                if ($type !== 'A' && $type !== 'AAAA') {
                    throw new DomainException('Trusted release publisher DNS record is unsupported.');
                }
                $ip = $record[$type === 'A' ? 'ip' : 'ipv6'] ?? null;
                if (! is_string($ip) || ! self::isPublicAddress($ip)
                    || ($type === 'A') !== (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)) {
                    throw new DomainException('Trusted release publisher DNS returned a non-public address.');
                }
                $addresses[$ip] = true;
            }

            // Every address, including unused fallbacks, was checked above.
            if ($addresses !== []) {
                // IPv4 is preferred for shared hosting, but a public IPv6-only
                // publisher is supported without weakening the network gate.
                $selected = array_key_first($addresses);
                foreach (array_keys($addresses) as $candidate) {
                    if (! str_contains($candidate, ':')) {
                        $selected = $candidate;
                        break;
                    }
                }

                return $host.':443:'.(str_contains($selected, ':') ? '['.$selected.']' : $selected);
            }
            if ($alias === null) {
                throw new DomainException('Trusted release publisher has no public DNS address.');
            }
            $current = $alias;
        }

        throw new DomainException('Trusted release publisher DNS alias chain is too deep.');
    }

    /**
     * @return array<int,mixed> cURL-only options; never used by test transports.
     * @param ?Closure(string):array<int,array<string,string>> $lookup
     */
    public static function curlPinOptions(string $url, ?Closure $lookup = null): array
    {
        if (! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_PROXY')
            || ! defined('CURLOPT_NOPROXY')) {
            throw new DomainException('Verified HTTPS release transport is unavailable.');
        }

        return [
            CURLOPT_RESOLVE => [self::resolvePin($url, $lookup)],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ];
    }

    public static function isPublicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && ! self::withinCidr($packed, '2000::/3')) {
            // Only globally routable unicast IPv6, not scoped/transition ranges.
            return false;
        }
        foreach (strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $range) {
            if (self::withinCidr($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function withinCidr(string $packed, string $range): bool
    {
        [$network, $prefix] = explode('/', $range, 2);
        $base = inet_pton($network);
        if ($base === false || strlen($packed) !== strlen($base)) {
            return false;
        }
        $bits = (int) $prefix;
        $whole = intdiv($bits, 8);
        if (substr($packed, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainder)) & 0xff;

        return (ord($packed[$whole]) & $mask) === (ord($base[$whole]) & $mask);
    }
}

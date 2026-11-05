<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseMetadata;
use App\Domain\Deployment\TrustedReleasePublicNetwork;
use Closure;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TrustedReleasePublicNetworkTest extends TestCase
{
    /** @return Closure(string):array<int,array<string,string>> */
    private function records(array $addresses): Closure
    {
        return static fn (string $host): array => array_map(
            static fn (string $ip): array => str_contains($ip, ':')
                ? ['type' => 'AAAA', 'ipv6' => $ip]
                : ['type' => 'A', 'ip' => $ip],
            $addresses,
        );
    }

    public function test_public_ipv4_and_ipv6_are_pinned_to_the_approved_tls_hostname(): void
    {
        self::assertSame(
            'updates.example.com:443:8.8.8.8',
            TrustedReleasePublicNetwork::resolvePin(
                'https://updates.example.com/releases/latest.json',
                $this->records(['2606:4700:4700::1111', '8.8.8.8']),
            ),
        );
        self::assertSame(
            'updates.example.com:443:[2606:4700:4700::1111]',
            TrustedReleasePublicNetwork::resolvePin(
                'https://updates.example.com/releases/WalidSite-update-v1.2.0.zip',
                $this->records(['2606:4700:4700::1111']),
            ),
        );
    }

    public function test_private_and_special_use_ips_are_rejected_even_for_public_syntax_hosts(): void
    {
        foreach ([
            '0.0.0.1', '10.2.3.4', '100.64.1.1', '127.0.0.1',
            '169.254.1.1', '172.16.0.8', '192.0.0.8', '192.0.2.10',
            '192.88.99.2', '192.168.0.1', '198.19.0.2',
            '198.51.100.3', '203.0.113.10', '224.1.2.3', '240.1.2.3',
            '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1',
            '64:ff9b::a00:1', '100::1', '2001:db8::1',
            '2002:c0a8:1::1', '2001::1', 'ff02::1',
        ] as $ip) {
            self::assertFalse(TrustedReleasePublicNetwork::isPublicAddress($ip), $ip);
            try {
                TrustedReleasePublicNetwork::resolvePin(
                    'https://updates.example.com/releases/latest.json',
                    $this->records([$ip]),
                );
                self::fail('Non-public publisher IP was accepted: '.$ip);
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
        self::assertTrue(TrustedReleasePublicNetwork::isPublicAddress('8.8.8.8'));
        self::assertTrue(TrustedReleasePublicNetwork::isPublicAddress('2606:4700:4700::1111'));
    }

    public function test_mixed_public_private_dns_answers_fail_closed_without_choosing_public_only(): void
    {
        foreach ([['8.8.8.8', '127.0.0.1'], ['2606:4700:4700::1111', 'fc00::1']] as $ips) {
            $this->expectNoPrivateTraffic(
                'https://updates.example.com/releases/latest.json',
                $this->records($ips),
            );
        }
    }

    public function test_empty_malformed_oversized_and_looping_dns_answers_fail_closed(): void
    {
        foreach ([
            static fn (string $host): array => [],
            static fn (string $host): bool => false,
            static fn (string $host): array => [['type' => 'A', 'ip' => 'not-an-ip']],
            static fn (string $host): array => array_fill(0, 33, ['type' => 'A', 'ip' => '8.8.8.8']),
            static fn (string $host): array => [['type' => 'CNAME', 'target' => $host]],
            static fn (string $host): array => [['type' => 'CNAME', 'target' => 'localhost.']],
            static fn (string $host): array => [['type' => 'AAAA', 'ipv6' => '8.8.8.8']],
        ] as $lookup) {
            $this->expectNoPrivateTraffic(
                'https://updates.example.com/releases/latest.json',
                $lookup,
            );
        }
    }

    public function test_public_cname_is_resolved_then_original_https_host_is_pinned(): void
    {
        $visited = [];
        $pin = TrustedReleasePublicNetwork::resolvePin(
            'https://updates.example.com/releases/latest.json',
            static function (string $host) use (&$visited): array {
                $visited[] = $host;

                return $host === 'updates.example.com'
                    ? [['type' => 'CNAME', 'target' => 'cdn.example.com.']]
                    : [['type' => 'A', 'ip' => '1.1.1.1']];
            },
        );
        self::assertSame(['updates.example.com', 'cdn.example.com'], $visited);
        self::assertSame('updates.example.com:443:1.1.1.1', $pin);
    }

    public function test_origin_abuse_fails_before_any_dns_lookup(): void
    {
        foreach ([
            'http://updates.example.com/releases/latest.json',
            'https://127.0.0.1/releases/latest.json',
            'https://updates.localhost/releases/latest.json',
            'https://updates.example.com:443/releases/latest.json',
            'https://user@updates.example.com/releases/latest.json',
            'https://updates.example.com/releases/latest.json#frag',
        ] as $url) {
            $lookups = 0;
            $this->expectNoPrivateTraffic($url, static function () use (&$lookups): array {
                $lookups++;
                return [['type' => 'A', 'ip' => '8.8.8.8']];
            });
            self::assertSame(0, $lookups, $url);
        }
    }

    public function test_curl_transport_options_pin_ip_disable_proxies_and_preserve_hostname(): void
    {
        if (! extension_loaded('curl')) {
            self::markTestSkipped('Requires the actual PHP cURL transport extension.');
        }
        $options = TrustedReleasePublicNetwork::curlPinOptions(
            'https://updates.example.com/releases/latest.json',
            $this->records(['8.8.8.8']),
        );
        self::assertSame(['updates.example.com:443:8.8.8.8'], $options[CURLOPT_RESOLVE]);
        self::assertSame('', $options[CURLOPT_PROXY]);
        self::assertSame('*', $options[CURLOPT_NOPROXY]);
    }

    private function expectNoPrivateTraffic(string $url, Closure $lookup): void
    {
        try {
            TrustedReleasePublicNetwork::resolvePin($url, $lookup);
            self::fail('Untrusted DNS result was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

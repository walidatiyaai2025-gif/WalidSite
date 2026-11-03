<?php

namespace App\Domain\Deployment;

use Closure;
use DomainException;
use RuntimeException;

/**
 * Read-only discovery from exactly one configured publisher origin.
 *
 * The downloaded document is untrusted until Ed25519 verification. No
 * arbitrary release URL, key replacement, HTTP redirect, or HTML response can
 * become an executable code update. Owner authorization is a separate gate.
 */
final class TrustedReleaseCatalog
{
    private const MAX_BYTES = 16384;

    /** @param ?Closure(string,Closure):int $transport Isolated HTTP fixture for tests only. */
    public function __construct(private readonly ?Closure $transport = null) {}

    /**
     * @return array{envelope:string,release:array<string,int|string>}
     */
    public function check(
        string $approvedOrigin,
        string $publisherPublicKeyBase64,
        string $installedVersion,
    ): array {
        // Reject invalid destinations BEFORE making a network request.
        $pattern = '~\Ahttps://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})\z~iD';
        if (! preg_match($pattern, $approvedOrigin, $matches)
            || strlen($matches[1]) > 253
            || filter_var($matches[1], FILTER_VALIDATE_IP) !== false) {
            throw new DomainException('Trusted release catalog requires a pinned public HTTPS hostname.');
        }

        $url = $approvedOrigin.'/releases/latest.json';
        $content = '';
        $overflow = false;
        $write = static function (string $bytes) use (&$content, &$overflow): int {
            $count = strlen($bytes);
            if ($count > self::MAX_BYTES - strlen($content)) {
                $overflow = true;
                return 0;
            }
            $content .= $bytes;
            return $count;
        };

        $status = $this->transport !== null
            ? ($this->transport)($url, $write)
            : $this->fetchHttps($url, $write);
        if (! is_int($status) || $status !== 200 || $overflow
            || $content === '' || strlen($content) > self::MAX_BYTES) {
            throw new DomainException('Publisher release catalog returned an incomplete or unexpected response.');
        }

        $release = (new TrustedReleaseMetadata())->verify(
            $content, $publisherPublicKeyBase64, $approvedOrigin, $installedVersion,
        );

        return ['envelope' => $content, 'release' => $release];
    }

    /** @param Closure(string):int $write */
    private function fetchHttps(string $url, Closure $write): int
    {
        if (! function_exists('curl_init')) {
            throw new DomainException('cURL is required for publisher release checks.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize publisher release check.');
        }

        try {
            $options = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPGET => true,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => false,
                CURLOPT_FAILONERROR => false,
                CURLOPT_USERAGENT => 'WalidSite-TrustedReleaseCatalog/1',
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => $write($chunk),
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
                $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
            } else {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            if (! curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
                throw new DomainException('Publisher release HTTPS check failed.');
            }
            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
    }
}

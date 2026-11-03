<?php

namespace App\Domain\Deployment;

use Closure;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Publisher-authenticated, bounded HTTPS artifact acquisition.
 *
 * The URL is derived ONLY from verified signed release metadata, not a web
 * request or a release server redirect. Files are quarantined until checksum
 * and safe ZIP inventory have both passed.
 */
final class TrustedReleaseDownloader
{
    /** @var ?Closure(string, Closure):int */
    private ?Closure $transport;

    /**
     * Transport injection is for isolated acceptance tests; normal production
     * callers always use TLS-verifying native cURL.
     */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @return array{path:string,version:string,artifact_sha256:string,artifact_size:int}
     */
    public function download(
        string $signedEnvelope,
        string $publisherPublicKeyBase64,
        string $pinnedHttpsOrigin,
        string $installedVersion,
        string $privateDownloadDirectory,
        string $publicDocumentRoot,
    ): array {
        $release = (new TrustedReleaseMetadata())->verify(
            $signedEnvelope, $publisherPublicKeyBase64, $pinnedHttpsOrigin,
            $installedVersion,
        );

        $private = realpath($privateDownloadDirectory);
        $public = realpath($publicDocumentRoot);
        if ($private === false || $public === false
            || ! is_dir($private) || ! is_dir($public)
            || is_link($privateDownloadDirectory)
            || $private === $public
            || $this->within($private, $public)
            || $this->within($public, $private)
            || ! is_writable($private)) {
            throw new DomainException('Trusted artifact downloads require isolated private storage.');
        }

        $name = basename((string) parse_url($release['artifact_url'], PHP_URL_PATH));
        $final = $private.'/'.$name;
        if (file_exists($final) || is_link($final)) {
            throw new DomainException('Release artifact already exists; refusing to overwrite it.');
        }

        $temporary = $private.'/.release-'.bin2hex(random_bytes(16)).'.partial';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create private release download file.');
        }

        $written = 0;
        $limit = $release['artifact_size'];
        $overflow = false;
        $writer = static function (string $bytes) use ($stream, &$written, &$overflow, $limit): int {
            $length = strlen($bytes);
            if ($length > $limit - $written) {
                $overflow = true;
                return 0;
            }

            $offset = 0;
            while ($offset < $length) {
                $count = fwrite($stream, substr($bytes, $offset));
                if ($count === false || $count === 0) {
                    return 0;
                }
                $offset += $count;
            }
            $written += $length;
            return $length;
        };

        try {
            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot restrict release download permissions.');
            }

            $httpStatus = $this->transport === null
                ? $this->fetchHttps($release['artifact_url'], $writer)
                : ($this->transport)($release['artifact_url'], $writer);

            if (! is_int($httpStatus) || $httpStatus !== 200 || $overflow) {
                throw new DomainException('Trusted release download did not return a complete HTTPS 200 response.');
            }
            if (! fflush($stream)) {
                throw new RuntimeException('Cannot flush private release download.');
            }
            fclose($stream);
            $stream = null;

            if ($written !== $limit || filesize($temporary) !== $limit) {
                throw new DomainException('Downloaded release artifact length differs from signed metadata.');
            }

            $digest = hash_file('sha256', $temporary);
            if (! is_string($digest) || ! hash_equals($release['artifact_sha256'], $digest)) {
                throw new DomainException('Downloaded release artifact SHA256 differs from publisher signature.');
            }

            // The inspector requires the expected filename; retain a private
            // temporary file until signature and byte checks have succeeded.
            if (file_exists($final) || is_link($final) || ! @rename($temporary, $final)) {
                throw new DomainException('Cannot publish verified private release archive.');
            }
            try {
                (new TrustedReleaseArchive())->inspect($release, $private, $final);
            } catch (Throwable $failedInventory) {
                @unlink($final);
                throw $failedInventory;
            }

            return [
                'path' => $final,
                'version' => $release['version'],
                'artifact_sha256' => $digest,
                'artifact_size' => $written,
            ];
        } catch (Throwable $failure) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);
            throw $failure;
        }
    }

    /** @param Closure(string):int $writer */
    private function fetchHttps(string $url, Closure $writer): int
    {
        if (! function_exists('curl_init')) {
            throw new DomainException('cURL is required for trusted HTTPS updates.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize trusted release transfer.');
        }

        try {
            $options = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR => false,
                CURLOPT_HTTPGET => true,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => false,
                CURLOPT_USERAGENT => 'WalidSite-TrustedRelease/1',
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $data): int => $writer($data),
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
                $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
            } else {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if (! curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
                throw new DomainException('Pinned HTTPS release download failed.');
            }

            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
    }

    private function within(string $path, string $parent): bool
    {
        return str_starts_with($path, rtrim($parent, '/').'/');
    }
}

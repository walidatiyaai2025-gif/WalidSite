<?php

namespace App\Domain\Deployment;

use Closure;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Streams ONLY a catalog-authenticated publisher artifact to one newly
 * created private file. Does not inspect, extract or execute application code;
 * TrustedOwnerUpdateInbox must recheck ZIP structure and the signed envelope.
 */
final class TrustedReleaseArtifactDownloader
{
    private const MAX_BYTES = 1073741824;

    /** @param ?Closure(string,Closure):int $transport Isolated HTTP fixture only. */
    public function __construct(private readonly ?Closure $transport = null) {}

    /**
     * @param array{version:string,artifact_url:string,artifact_size:int,artifact_sha256:string} $release
     */
    public function download(array $release, string $approvedOrigin, string $destination): void
    {
        TrustedReleaseMetadata::assertPinnedHttpsOrigin($approvedOrigin);
        $version = $release['version'] ?? null;
        $size = $release['artifact_size'] ?? null;
        $digest = $release['artifact_sha256'] ?? null;
        if (! is_string($version) || ! preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $version)
            || ! is_int($size) || $size < 1 || $size > self::MAX_BYTES
            || ! is_string($digest) || ! preg_match('/\A[a-f0-9]{64}\z/D', $digest)) {
            throw new DomainException('Signed publisher download identity is invalid.');
        }
        $url = $approvedOrigin.'/releases/WalidSite-update-v'.$version.'.zip';
        $parent = dirname($destination);
        $real = realpath($parent);
        if (($release['artifact_url'] ?? null) !== $url
            || $destination !== $parent.'/WalidSite-update-v'.$version.'.zip'
            || $real === false || $real !== $parent || is_link($parent)
            || ! is_dir($real) || ! is_writable($real)
            || (fileperms($real) & 0777) !== 0700
            || is_link($destination) || file_exists($destination)) {
            throw new DomainException('Trusted release needs an unused private destination.');
        }
        $free = @disk_free_space($real);
        if ($free === false || $free < $size + 33554432) {
            throw new DomainException('Insufficient private space to download an authenticated update.');
        }

        $file = @fopen($destination, 'xb');
        if ($file === false) {
            throw new RuntimeException('Cannot create exclusive private release archive.');
        }
        $accepted = false;
        try {
            if (! @chmod($destination, 0600)) {
                throw new DomainException('Downloaded release archive cannot be secured.');
            }
            $original = @fstat($file);
            if (! is_array($original)
                || ($original['mode'] & 0170000) !== 0100000
                || ($original['nlink'] ?? 0) !== 1) {
                throw new DomainException('Downloaded release archive inode is unsafe.');
            }
            $this->assertSameFile($file, $destination, $original);
            $bytes = 0;
            $hasher = hash_init('sha256');
            $overflow = false;
            $write = static function (string $chunk) use ($file, $size, &$bytes, &$overflow, $hasher): int {
                $length = strlen($chunk);
                if ($length > $size - $bytes) {
                    $overflow = true;
                    return 0;
                }
                $offset = 0;
                while ($offset < $length) {
                    $written = @fwrite($file, substr($chunk, $offset));
                    if (! is_int($written) || $written < 1) {
                        return 0;
                    }
                    $offset += $written;
                }
                $bytes += $length;
                hash_update($hasher, $chunk);
                return $length;
            };
            $status = $this->transport !== null
                ? ($this->transport)($url, $write)
                : $this->fetchHttps($url, $write);
            if ($status !== 200 || $overflow || $bytes !== $size
                || ! hash_equals($digest, hash_final($hasher))
                || ! @fflush($file)
                || (function_exists('fsync') && ! @fsync($file))) {
                throw new DomainException('Publisher archive download failed size, HTTPS or SHA-256 verification.');
            }
            $this->assertSameFile($file, $destination, $original, $size);
            $accepted = true;
        } finally {
            // Never leave a partial or wrong-signature archive in the inbox.
            // Only remove the pathname if it still designates our opened inode.
            if (! $accepted) {
                clearstatcache(true, $destination);
                $named = @lstat($destination);
                $opened = @fstat($file);
                if (is_array($named) && is_array($opened)
                    && $named['dev'] === $opened['dev'] && $named['ino'] === $opened['ino']
                    && ! is_link($destination)) {
                    @unlink($destination);
                }
            }
            fclose($file);
        }
    }

    /** @param resource $file @param array<string|int,mixed> $original */
    private function assertSameFile($file, string $path, array $original, ?int $size = null): void
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = @fstat($file);
        if (! is_array($named) || ! is_array($opened) || is_link($path)
            || ($named['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0777) !== 0600
            || ($opened['mode'] & 0777) !== 0600
            || $named['nlink'] !== 1 || $opened['nlink'] !== 1
            || $named['dev'] !== $original['dev']
            || $named['ino'] !== $original['ino']
            || $opened['dev'] !== $original['dev']
            || $opened['ino'] !== $original['ino']
            || ($size !== null && ($named['size'] !== $size || $opened['size'] !== $size))) {
            throw new DomainException('Publisher archive inode or permissions changed while downloading.');
        }
    }

    /** @param Closure(string):int $write */
    private function fetchHttps(string $url, Closure $write): int
    {
        if (! function_exists('curl_init')) {
            throw new DomainException('Trusted publisher downloads require cURL.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new DomainException('Cannot initialize trusted release transport.');
        }
        try {
            $options = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPGET => true,
                CURLOPT_NOBODY => false,
                CURLOPT_HEADER => false,
                CURLOPT_FAILONERROR => false,
                CURLOPT_USERAGENT => 'WalidSite-SignedUpdateDownloader/1',
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => $write($chunk),
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'https';
                $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
            } else {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if (! curl_setopt_array($curl, TrustedReleasePublicNetwork::curlPinOptions($url) + $options)
                || curl_exec($curl) === false) {
                throw new DomainException('Pinned publisher HTTPS artifact download failed.');
            }
            return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
    }
}

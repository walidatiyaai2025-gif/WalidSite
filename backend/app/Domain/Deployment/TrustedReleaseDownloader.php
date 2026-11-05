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

        // Keep the publisher-approved basename inside an owned, unguessable
        // 0700 staging directory. Inspect that exact ZIP BEFORE publishing its
        // final name; even a failed inspection never exposes a partial release.
        $stageDirectory = $private.'/.release-'.bin2hex(random_bytes(16)).'.partial';
        if (! @mkdir($stageDirectory, 0700)) {
            throw new RuntimeException('Cannot create private release staging directory.');
        }
        $temporary = $stageDirectory.'/'.$name;
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            @rmdir($stageDirectory);
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
            if ($written !== $limit) {
                throw new DomainException('Downloaded release artifact length differs from signed metadata.');
            }
            // Hash the exact opened inode that received cURL chunks. A second
            // path-based fopen/hash would permit a byte-identical name swap.
            $baseline = $this->assertStableFile($stream, $temporary, $limit, 1);
            $digest = $this->hashStream($stream, $limit);
            $this->assertStableFile($stream, $temporary, $limit, 1, $baseline);
            if (! hash_equals($release['artifact_sha256'], $digest)) {
                throw new DomainException('Downloaded release artifact SHA256 differs from publisher signature.');
            }

            // Full signed metadata and ZIP inventory validation happen in the
            // private staging directory with the exact canonical filename.
            (new TrustedReleaseArchive())->inspect($release, $stageDirectory, $temporary);
            $this->assertStableFile($stream, $temporary, $limit, 1, $baseline);
            if (! hash_equals($release['artifact_sha256'], $this->hashStream($stream, $limit))) {
                throw new DomainException('Staged archive changed during ZIP inspection.');
            }
            $this->assertStableFile($stream, $temporary, $limit, 1, $baseline);

            // No-replace hard-link publication: rename() could overwrite a ZIP
            // created by another publisher after our initial exists() test.
            if (! @link($temporary, $final)) {
                throw new DomainException('Cannot publish verified private release archive without replacement.');
            }
            // POSIX link()/unlink() legitimately change ctime on the
            // SAME inode. Preserve dev/ino/size/mtime identity across these
            // operations, while checking current ctime against both named
            // paths on every step. The pre-publication checks above still
            // enforce the full original ctime against the staged source.
            $linked = $this->assertStableFile($stream, $temporary, $limit, 2);
            $this->assertCoreArtifactIdentity($baseline, $linked);
            $linkedFinal = $this->assertStableFile($stream, $final, $limit, 2, $linked);
            $this->assertCoreArtifactIdentity($baseline, $linkedFinal);
            if (! @unlink($temporary)) {
                throw new RuntimeException('Unable to retire private release staging file.');
            }
            $published = $this->assertStableFile($stream, $final, $limit, 1);
            $this->assertCoreArtifactIdentity($baseline, $published);

            return [
                'path' => $final,
                'version' => $release['version'],
                'artifact_sha256' => $digest,
                'artifact_size' => $written,
            ];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // Only clean up the random private staging path we created.
            // Never unlink the shared final path on failure: another writer
            // may own it if a collision or pathname swap occurred.
            @unlink($temporary);
            @rmdir($stageDirectory);
        }
    }

    /** @param resource $stream @return array<string,int> */
    private function assertStableFile($stream, string $path, int $bytes, int $links, ?array $baseline = null): array
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named)
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== $links || $named['nlink'] !== $links
            || $opened['size'] !== $bytes || $named['size'] !== $bytes
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            || is_link($path) || realpath($path) !== $path) {
            throw new DomainException('Private release artifact pathname or inode changed.');
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if ($opened[$field] !== $named[$field]
                || ($baseline !== null && $opened[$field] !== $baseline[$field])) {
                throw new DomainException('Private release artifact identity changed.');
            }
        }
        return $opened;
    }

    /**
     * ctime tracks link-count changes; it is not a stable content identity
     * across intentional hard-link creation/removal. Check ctime in each
     * same-instant opened/named comparison, while pinning all stable fields.
     *
     * @param array<string,int> $original
     * @param array<string,int> $current
     */
    private function assertCoreArtifactIdentity(array $original, array $current): void
    {
        foreach (['dev', 'ino', 'size', 'mtime'] as $field) {
            if ($original[$field] !== $current[$field]) {
                throw new DomainException('Published release artifact identity changed.');
            }
        }
    }

    /** @param resource $stream */
    private function hashStream($stream, int $expectedBytes): string
    {
        if (! @rewind($stream)) {
            throw new DomainException('Cannot inspect private release download bytes.');
        }
        $context = hash_init('sha256');
        $count = hash_update_stream($context, $stream, $expectedBytes + 1);
        if ($count !== $expectedBytes) {
            throw new DomainException('Incomplete private release download stream.');
        }
        return hash_final($context);
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
            $networkOptions = TrustedReleasePublicNetwork::curlPinOptions($url);
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
            if (! curl_setopt_array($handle, $networkOptions + $options)
                || curl_exec($handle) === false) {
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

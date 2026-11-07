<?php

namespace App\Domain\Deployment;

use DomainException;
use JsonException;

/**
 * A pure trust boundary: verifies the publisher's signed immutable release
 * descriptor. Never downloads, extracts or executes an artifact.
 */
final class TrustedReleaseMetadata
{
    private const MAX_ENVELOPE_BYTES = 16384;
    private const MAX_PAYLOAD_BYTES = 8192;
    private const MAX_ARTIFACT_BYTES = 1073741824;

    /**
     * @return array{product:string,version:string,source_sha:string,artifact_sha256:string,artifact_size:int,min_php:string,artifact_url:string,release_notes?:string}
     */
    public function verify(
        string $envelopeJson,
        string $publisherPublicKeyBase64,
        string $approvedOrigin,
        string $installedVersion,
        string $expectedProduct = 'walidsite',
        bool $allowSameVersionForReadOnlyStatus = false,
    ): array {
        if (strlen($envelopeJson) > self::MAX_ENVELOPE_BYTES || ! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new DomainException('Release metadata cannot be verified.');
        }

        $envelope = $this->decodeObject($envelopeJson);
        $this->requireKeys($envelope, ['payload', 'signature']);
        $payload = $this->decodeBase64($envelope['payload'], self::MAX_PAYLOAD_BYTES);
        $signature = $this->decodeBase64($envelope['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        $publicKey = $this->decodeBase64($publisherPublicKeyBase64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new DomainException('Untrusted release signature.');
        }

        $metadata = $this->decodeObject($payload);
        $required = [
            'product', 'version', 'source_sha', 'artifact_sha256', 'artifact_size', 'min_php', 'artifact_file',
        ];
        // Older publisher envelopes without notes remain valid. New notes
        // MUST be part of the exact Ed25519-signed payload, never a sidecar.
        $hasNotes = array_key_exists('release_notes', $metadata);
        if ($hasNotes) {
            $required[] = 'release_notes';
        }
        $this->requireKeys($metadata, $required);
        if ($hasNotes && ! self::validReleaseNotes($metadata['release_notes'])) {
            throw new DomainException('Invalid publisher-signed release notes.');
        }

        foreach (['product', 'version', 'source_sha', 'artifact_sha256', 'min_php', 'artifact_file'] as $field) {
            if (! is_string($metadata[$field])) {
                throw new DomainException('Invalid signed release metadata.');
            }
        }

        if ($metadata['product'] !== $expectedProduct || ! preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $expectedProduct)) {
            throw new DomainException('Release product mismatch.');
        }

        $stableVersion = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D';
        if (! preg_match($stableVersion, $metadata['version'])
            || ! preg_match($stableVersion, $installedVersion)
            || version_compare(
                $metadata['version'], $installedVersion,
                $allowSameVersionForReadOnlyStatus ? '<' : '<='
            )) {
            throw new DomainException('Release version is invalid or not newer.');
        }

        if (! preg_match('/^[a-f0-9]{40}$/D', $metadata['source_sha'])
            || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['artifact_sha256'])
            || ! is_int($metadata['artifact_size'])
            || $metadata['artifact_size'] < 1
            || $metadata['artifact_size'] > self::MAX_ARTIFACT_BYTES) {
            throw new DomainException('Release artifact integrity metadata is invalid.');
        }

        if (! preg_match($stableVersion, $metadata['min_php'])
            || version_compare(PHP_VERSION, $metadata['min_php'], '<')) {
            throw new DomainException('Incompatible release PHP requirement.');
        }

        $filename = 'WalidSite-update-v'.$metadata['version'].'.zip';
        if ($metadata['artifact_file'] !== $filename) {
            throw new DomainException('Release artifact path is not approved.');
        }

        // The catalog and direct archive downloader must reject the same
        // unsafe hostnames, even if an envelope has a valid signature.
        self::assertPinnedHttpsOrigin($approvedOrigin);

        $verified = [
            'product' => $metadata['product'],
            'version' => $metadata['version'],
            'source_sha' => $metadata['source_sha'],
            'artifact_sha256' => $metadata['artifact_sha256'],
            'artifact_size' => $metadata['artifact_size'],
            'min_php' => $metadata['min_php'],
            'artifact_url' => $approvedOrigin.'/releases/'.$filename,
        ];
        if ($hasNotes) {
            $verified['release_notes'] = $metadata['release_notes'];
        }

        return $verified;
    }

    /**
     * Plain UTF-8 text only: newline is allowed for changelogs, but terminal
     * controls and bidi overrides/isolates must never be rendered as signed
     * metadata. Views still HTML-escape every character, including markup.
     */
    public static function validReleaseNotes(mixed $notes): bool
    {
        return is_string($notes) && strlen($notes) >= 1 && strlen($notes) <= 2048
            && preg_match('//u', $notes) === 1
            && preg_match('/[\\x00-\\x09\\x0B-\\x1F\\x7F\\x{202A}-\\x{202E}\\x{2066}-\\x{2069}]/u', $notes) === 0;
    }

    /**
     * Shared pinned publisher origin rule for both signed metadata and catalog
     * verification. Kept in this file because stand-alone PHP release
     * verifiers load TrustedReleaseMetadata directly without Composer.
     */
    public static function assertPinnedHttpsOrigin(string $origin): void
    {
        $pattern = '~\Ahttps://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})\z~iD';
        if (! preg_match($pattern, $origin, $matches)
            || strlen($matches[1]) > 253
            || filter_var($matches[1], FILTER_VALIDATE_IP) !== false) {
            throw new DomainException('Trusted release origin requires a pinned public HTTPS hostname.');
        }

        // A syntactically valid FQDN is not necessarily a public publisher.
        // Reject special-use/private DNS suffixes before catalog/download I/O.
        // Match whole labels only: a real public suffix such as example.com
        // remains valid, while deep.publisher.localhost cannot become trusted.
        $host = strtolower($matches[1]);
        foreach (['arpa', 'example', 'home', 'internal', 'invalid', 'lan', 'local',
                  'localdomain', 'localhost', 'onion', 'test'] as $reservedSuffix) {
            if ($host === $reservedSuffix || str_ends_with($host, '.'.$reservedSuffix)) {
                throw new DomainException('Trusted release origin requires a pinned public HTTPS hostname.');
            }
        }
    }

    /**
     * Verify downloaded archive bytes in a private, flat staging directory.
     * The caller must keep the verified file immutable until deployment; this
     * method deliberately does not extract or execute it.
     *
     * @param array{artifact_url:string,artifact_size:int,artifact_sha256:string} $verifiedRelease
     */
    public function verifyStagedArchive(array $verifiedRelease, string $privateStagingDirectory, string $archiveFile): void
    {
        $directory = realpath($privateStagingDirectory);
        $file = realpath($archiveFile);
        $name = basename((string) parse_url($verifiedRelease['artifact_url'], PHP_URL_PATH));
        if ($directory === false || ! is_dir($directory) || $file === false
            || ! is_file($file) || is_link($archiveFile) || dirname($file) !== $directory
            || basename($file) !== $name || ! preg_match('/^WalidSite-update-v[0-9]+\.[0-9]+\.[0-9]+\.zip$/D', $name)) {
            throw new DomainException('Untrusted staged release archive location.');
        }

        // Hash exactly the file descriptor whose identity was checked.
        // Hashing the pathname again after realpath()/is_file() permits an
        // intervening rename/hard-link swap of the proposed release bytes.
        clearstatcache(true, $archiveFile);
        $stream = @fopen($archiveFile, 'rb');
        if ($stream === false) {
            throw new DomainException('Release archive cannot be opened safely.');
        }
        try {
            $baseline = @fstat($stream);
            if (! is_array($baseline) || ($baseline['mode'] & 0170000) !== 0100000
                || $baseline['nlink'] !== 1
                || $baseline['size'] !== $verifiedRelease['artifact_size']) {
                throw new DomainException('Release archive file identity is unsafe.');
            }
            $this->assertStagedArchiveIdentity($archiveFile, $stream, $baseline);
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) !== $baseline['size']) {
                throw new DomainException('Release archive length changed while hashing.');
            }
            $digest = hash_final($hash);
            $this->assertStagedArchiveIdentity($archiveFile, $stream, $baseline);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($verifiedRelease['artifact_sha256'], $digest)) {
            throw new DomainException('Release archive SHA256 mismatch.');
        }
    }

    /**
     * @param resource $stream
     * @param array<string|int,mixed> $baseline
     */
    private function assertStagedArchiveIdentity(string $path, $stream, array $baseline): void
    {
        clearstatcache(true, $path);
        $opened = @fstat($stream);
        $named = @lstat($path);
        if (! is_array($opened) || ! is_array($named) || is_link($path)
            || ($named['mode'] & 0170000) !== 0100000) {
            throw new DomainException('Release archive pathname changed.');
        }
        foreach (['dev', 'ino', 'size', 'mode', 'nlink', 'mtime', 'ctime'] as $field) {
            if ($opened[$field] !== $baseline[$field]
                || $named[$field] !== $baseline[$field]) {
                throw new DomainException('Release archive identity changed.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Malformed release metadata.');
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new DomainException('Release metadata must be an object.');
        }

        return $value;
    }

    /** @param array<string,mixed> $actual @param list<string> $keys */
    private function requireKeys(array $actual, array $keys): void
    {
        $actualKeys = array_keys($actual);
        sort($actualKeys);
        sort($keys);
        if ($actualKeys !== $keys) {
            throw new DomainException('Unexpected release metadata fields.');
        }
    }

    private function decodeBase64(mixed $encoded, int $limit): string
    {
        if (! is_string($encoded) || strlen($encoded) > 4 * ($limit + 2) / 3 + 4) {
            throw new DomainException('Malformed encoded release metadata.');
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) > $limit || base64_encode($decoded) !== $encoded) {
            throw new DomainException('Malformed encoded release metadata.');
        }

        return $decoded;
    }
}

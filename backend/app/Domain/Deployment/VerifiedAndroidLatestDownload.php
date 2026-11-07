<?php

namespace App\Domain\Deployment;

use DomainException;
use JsonException;
use ZipArchive;

/**
 * Stable public Android download trust boundary (never a Release publisher).
 *
 * The only supported input is a privately staged, immutable Android APK with
 * an Ed25519-signed POST-DEPLOYMENT Web↔APK pairing receipt. There is no GitHub
 * token, arbitrary redirect, HEAD-to-URL fallback, or debug/QA artifact path.
 *
 * This is intentionally unavailable on an un-attested FIRST cPanel install:
 * only the active, verified versioned code-pointer lineage supplies a Git SHA.
 * A separate, authorized host publisher must actually deploy and verify the
 * Web and production signer before it can write the required private files.
 */
final class VerifiedAndroidLatestDownload
{
    private const MAX_APK_BYTES = 300000000;
    private const MAX_RECEIPT_BYTES = 8192;

    /**
     * @return array{meta:array<string,int|string>,stream:resource}
     * @throws DomainException on every unverified, stale, missing or unsafe input
     */
    public function resolve(
        string $runningApplication,
        string $publicDocumentRoot,
        string $privateDirectory,
        string $firstInstalledVersion,
        string $approvedSiteOrigin,
        string $publisherPublicKeyBase64,
        string $pinnedAndroidSignerSha256,
    ): array {
        if (! function_exists('sodium_crypto_sign_verify_detached')
            || ! class_exists(ZipArchive::class)
            || preg_match('/\A[a-f0-9]{64}\z/D', $pinnedAndroidSignerSha256) !== 1) {
            throw new DomainException('Production Android verification is not configured.');
        }
        TrustedReleaseMetadata::assertPinnedHttpsOrigin($approvedSiteOrigin);
        if (strtolower($approvedSiteOrigin) !== $approvedSiteOrigin
            || preg_match('~\Ahttps://(?:example\.(?:com|net|org)|.+\.example\.(?:com|net|org))\z~D', $approvedSiteOrigin)) {
            throw new DomainException('Production Android origin is not approved.');
        }
        $public = realpath($publicDocumentRoot);
        $home = $public === false ? false : dirname($public);
        $live = realpath($runningApplication);
        if ($public === false || $home === false || $live === false
            || $public !== $publicDocumentRoot || $live !== $runningApplication
            || $public !== $home.'/public_html' || is_link($public)
            || is_link($home) || is_link($live)) {
            throw new DomainException('Actual cPanel website release is not established.');
        }
        $prefix = preg_quote($home, '#');
        if (preg_match('#\A'.$prefix.'/\.walidsite-releases/v((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))-([a-f0-9]{40})/application\z#D',
            $live, $releaseMatch) !== 1) {
            // No unproven first-install source-SHA assumptions.
            throw new DomainException('Live website Git SHA is not attested.');
        }
        $webVersion = $releaseMatch[1];
        $webSha = $releaseMatch[2];
        if ((new TrustedInstalledReleasePin())->read(
            $live, $public, $firstInstalledVersion
        ) !== $webVersion) {
            throw new DomainException('Website active code pointer is not verified.');
        }

        $expectedPrivate = $home.'/application/storage/app/private/android-release';
        if ($privateDirectory !== $expectedPrivate) {
            throw new DomainException('Android downloads must use stable private storage.');
        }
        $this->assertPrivateTree($home, $privateDirectory);
        $receipt = $this->readFixedPrivateFile($privateDirectory.'/latest-pairing.json', 2, self::MAX_RECEIPT_BYTES);
        try {
            $envelope = json_decode($receipt, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Signed Android pairing is invalid.');
        }
        if (! is_array($envelope) || array_keys($envelope) !== ['payload', 'signature']) {
            throw new DomainException('Signed Android pairing envelope is invalid.');
        }
        $payload = $this->base64($envelope['payload'], 4096);
        $signature = $this->base64($envelope['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        $publicKey = $this->base64($publisherPublicKeyBase64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new DomainException('Android pairing publisher signature is untrusted.');
        }
        try {
            $pair = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Signed Android pairing payload is malformed.');
        }
        $keys = [
            'schema', 'product', 'web_version', 'web_source_sha', 'api_origin',
            'apk_version', 'apk_source_sha', 'apk_sha256', 'apk_size',
            'apk_signer_sha256', 'apk_file', 'verified_at', 'release_notes',
            'live_https_verified', 'apk_signature_verified',
            'web_apk_compatible', 'production_ready',
        ];
        if (! is_array($pair) || array_keys($pair) !== $keys
            || json_encode($pair, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $payload
            || $pair['schema'] !== 'walidsite.android-live-pairing.v1'
            || $pair['product'] !== 'walidsite'
            || ! is_string($pair['web_version']) || $pair['web_version'] !== $webVersion
            || ! is_string($pair['web_source_sha']) || ! hash_equals($webSha, $pair['web_source_sha'])
            || ! is_string($pair['api_origin']) || ! hash_equals($approvedSiteOrigin, $pair['api_origin'])
            || ! is_string($pair['apk_version']) || $pair['apk_version'] !== $webVersion
            || ! is_string($pair['apk_source_sha']) || ! hash_equals($webSha, $pair['apk_source_sha'])
            || ! is_string($pair['apk_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $pair['apk_sha256']) !== 1
            || ! is_int($pair['apk_size']) || $pair['apk_size'] < 128
            || $pair['apk_size'] > self::MAX_APK_BYTES
            || ! is_string($pair['apk_signer_sha256'])
            || ! hash_equals($pinnedAndroidSignerSha256, $pair['apk_signer_sha256'])
            || ! is_string($pair['apk_file'])
            || $pair['apk_file'] !== 'WalidSite-Android-v'.$webVersion.'.apk'
            || ! is_int($pair['verified_at']) || $pair['verified_at'] < 1
            || $pair['verified_at'] > time()
            || ! TrustedReleaseMetadata::validReleaseNotes($pair['release_notes'])
            || $pair['live_https_verified'] !== true
            || $pair['apk_signature_verified'] !== true
            || $pair['web_apk_compatible'] !== true
            || $pair['production_ready'] !== true) {
            throw new DomainException('Android/Web compatibility or production evidence is missing.');
        }

        $apk = $privateDirectory.'/'.$pair['apk_file'];
        $stream = $this->openPrivateFile($apk, $pair['apk_size'], $pair['apk_size']);
        try {
            if (! @flock($stream, LOCK_SH | LOCK_NB)) {
                throw new DomainException('Verified Android archive is being changed.');
            }
            $this->assertFileIdentity($stream, $apk);
            if (@fread($stream, 4) !== "PK\x03\x04" || @rewind($stream) !== true) {
                throw new DomainException('Android archive header is invalid.');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) !== $pair['apk_size']
                || ! hash_equals($pair['apk_sha256'], hash_final($hash))) {
                throw new DomainException('Android download bytes differ from signed publisher pairing.');
            }
            $this->assertFileIdentity($stream, $apk);

            // The ZIP inspector must not accept a random PK-prefixed file.
            // The publisher has already independently attested aapt/apksigner
            // under its Ed25519 key; this extra check only tests APK structure.
            $archive = new ZipArchive();
            if ($archive->open($apk, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new DomainException('Android archive ZIP structure is invalid.');
            }
            try {
                if ($archive->locateName('AndroidManifest.xml') === false
                    || $archive->locateName('classes.dex') === false) {
                    throw new DomainException('Android executable/manifest structure is incomplete.');
                }
            } finally {
                $archive->close();
            }
            $this->assertFileIdentity($stream, $apk);
            if (! @rewind($stream)) {
                throw new DomainException('Verified Android archive cannot be streamed.');
            }
            return [
                'meta' => [
                    'web_version' => $webVersion,
                    'web_source_sha' => $webSha,
                    'api_origin' => $approvedSiteOrigin,
                    'apk_version' => $pair['apk_version'],
                    'apk_source_sha' => $pair['apk_source_sha'],
                    'apk_sha256' => $pair['apk_sha256'],
                    'apk_size' => $pair['apk_size'],
                    'apk_signer_sha256' => $pair['apk_signer_sha256'],
                    'verified_at' => $pair['verified_at'],
                    'release_notes' => $pair['release_notes'],
                    'filename' => $pair['apk_file'],
                ],
                'stream' => $stream,
            ];
        } catch (\Throwable $error) {
            @flock($stream, LOCK_UN);
            fclose($stream);
            throw $error;
        }
    }

    private function assertPrivateTree(string $home, string $directory): void
    {
        foreach ([
            $home.'/application', $home.'/application/storage',
            $home.'/application/storage/app', $home.'/application/storage/app/private',
            $directory,
        ] as $path) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (! is_array($stat) || is_link($path)
                || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['mode'] & 0022) !== 0
                || realpath($path) !== $path) {
                throw new DomainException('Android private download tree is unsafe.');
            }
        }
        $mode = @fileperms($directory);
        if ($mode === false || ($mode & 0777) !== 0700) {
            throw new DomainException('Android production archive directory must be owner-only.');
        }
    }

    private function readFixedPrivateFile(string $path, int $min, int $max): string
    {
        $stream = $this->openPrivateFile($path, $min, $max);
        try {
            $size = fstat($stream)['size'];
            $data = @stream_get_contents($stream, $max + 1);
            if (! is_string($data) || strlen($data) !== $size) {
                throw new DomainException('Private signed pairing bytes are incomplete.');
            }
            $this->assertFileIdentity($stream, $path);
            return $data;
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    private function openPrivateFile(string $path, int $min, int $max)
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || is_link($path)
            || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0777) !== 0600
            || ($stat['nlink'] ?? 0) !== 1
            || ! is_int($stat['size']) || $stat['size'] < $min || $stat['size'] > $max) {
            throw new DomainException('Private verified Android artifact is unavailable.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new DomainException('Private verified Android artifact cannot be read.');
        }
        try {
            $this->assertFileIdentity($stream, $path);
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
        return $stream;
    }

    /** @param resource $stream */
    private function assertFileIdentity($stream, string $path): void
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $held = @fstat($stream);
        if (! is_array($held) || ! is_array($named) || is_link($path)
            || ($named['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0777) !== 0600
            || ($named['nlink'] ?? 0) !== 1) {
            throw new DomainException('Private Android artifact inode changed.');
        }
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime', 'nlink'] as $field) {
            if (($held[$field] ?? null) !== ($named[$field] ?? null)) {
                throw new DomainException('Private Android artifact identity mismatch.');
            }
        }
    }

    private function base64(mixed $value, int $maxBytes): string
    {
        if (! is_string($value) || strlen($value) > (int) ceil($maxBytes / 3) * 4 + 4
            || ($raw = base64_decode($value, true)) === false
            || strlen($raw) > $maxBytes || base64_encode($raw) !== $value) {
            throw new DomainException('Noncanonical Android pairing signature encoding.');
        }
        return $raw;
    }
}

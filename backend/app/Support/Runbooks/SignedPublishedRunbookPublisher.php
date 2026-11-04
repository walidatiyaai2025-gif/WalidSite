<?php

declare(strict_types=1);

namespace App\Support\Runbooks;

use App\Http\Controllers\Api\PublicRunbookController;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Publisher-side ONLY: build an immutable Ed25519 offline pack from exactly
 * the same current, verified review gate used by public Mobile API reads.
 *
 * Does not publish files to the web, invent translations, or ship a secret.
 * The signed artifact must be promoted by a separately authorized publisher.
 */
final class SignedPublishedRunbookPublisher
{
    private const MAX_GUIDES = 20;
    private const MAX_MARKDOWN_BYTES = 65536;
    private const MAX_PAYLOAD_BYTES = 400000;

    public function __construct(private readonly PublicRunbookController $runbooks)
    {
    }

    /** @param array<int, string> $ids */
    public function prepare(array $ids, string $secretBase64, ?DateTimeImmutable $clock = null): string
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('Ed25519 signing unavailable.');
        }

        if ($ids === [] || count($ids) > self::MAX_GUIDES || ! array_is_list($ids)) {
            throw new RuntimeException('Invalid bounded reviewed runbook selection.');
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Duplicate reviewed runbook identifier.');
        }
        foreach ($ids as $id) {
            if (! is_string($id) || preg_match('/\A[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/', $id) !== 1) {
                throw new RuntimeException('Invalid reviewed runbook UUID.');
            }
        }

        if (strlen($secretBase64) > 200) {
            throw new RuntimeException('Invalid publisher key.');
        }
        $secret = base64_decode($secretBase64, true);
        if (! is_string($secret)
            || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || base64_encode($secret) !== $secretBase64) {
            throw new RuntimeException('Invalid publisher key.');
        }

        $now = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        if ($now->getTimestamp() > time() + 60) {
            sodium_memzero($secret);
            throw new RuntimeException('Publisher clock is in the future.');
        }
        $stamp = $now->format('Y-m-d\TH:i:s\Z');
        $versionPath = base_path('VERSION');
        if (! is_file($versionPath) || is_link($versionPath)) {
            sodium_memzero($secret);
            throw new RuntimeException('Canonical application version missing.');
        }
        $version = trim((string) file_get_contents($versionPath));
        if (preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) !== 1) {
            sodium_memzero($secret);
            throw new RuntimeException('Invalid canonical application version.');
        }

        sort($ids, SORT_STRING);
        try {
            $items = [];
            foreach ($ids as $id) {
                $live = $this->runbooks->verifiedData($id);
                if ($live === null) {
                    throw new RuntimeException('Current reviewed publication unavailable.');
                }
                $markdown = $live['markdown'];
                $title = $live['title'];
                $revision = $live['version'];
                $approvedAt = $live['validated_at'];

                if (! is_string($title) || trim($title) === '' || strlen($title) > 250
                    || ! is_string($markdown) || trim($markdown) === ''
                    || strlen($markdown) > self::MAX_MARKDOWN_BYTES
                    || ! is_int($revision) || $revision < 1
                    || ! is_string($approvedAt) || strlen($approvedAt) > 64) {
                    throw new RuntimeException('Reviewed publication exceeds signed pack limits.');
                }

                $items[] = [
                    'id' => $id,
                    // No synthetic translation. Both locale labels use the
                    // exact reviewed title; the signed text is byte-identical.
                    'title_en' => $title,
                    'title_ar' => $title,
                    'steps_en' => ['Read the exact signed reviewed text shown above.'],
                    'steps_ar' => ['اقرأ النص المنشور المراجع والموقّع المعروض أعلاه.'],
                    'published_version' => $revision,
                    'published_markdown' => $markdown,
                    'published_validated_at' => $approvedAt,
                    'published_body_sha256' => hash('sha256', $markdown),
                ];
            }
            $payload = json_encode([
                'schema' => 'walidsite.offline-runbook-pack.v1',
                'version' => $version,
                'source' => 'WalidSite reviewed public runbooks (no inferred translations)',
                'last_synced_at' => $stamp,
                'verified_at' => $stamp,
                'items' => $items,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                throw new RuntimeException('Reviewed pack exceeds mobile trust limits.');
            }
            $signature = sodium_crypto_sign_detached($payload, $secret);
            return json_encode([
                'schema' => 'walidsite.signed-runbook-pack.v1',
                'payload_base64' => base64_encode($payload),
                'signature_base64' => base64_encode($signature),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Could not encode reviewed runbook publication.', 0, $exception);
        } finally {
            sodium_memzero($secret);
        }
    }

    /**
     * Persist an immutable candidate in a private publisher-only directory.
     * No symlink traversal, existing overwrite or public web-root publication.
     * @return array{file: string, sha256: string}
     */
    public function storePrivate(string $envelope, string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($directory)) {
            throw new RuntimeException('Private publisher output directory unavailable.');
        }
        if (strlen($envelope) > 800 * 1024 || strlen($envelope) < 1) {
            throw new RuntimeException('Invalid signed publisher output size.');
        }
        $sha = hash('sha256', $envelope);
        $filename = 'reviewed-runbooks-'.$sha.'.json';
        $target = $root.DIRECTORY_SEPARATOR.$filename;
        $lockPath = $root.DIRECTORY_SEPARATOR.'.publisher.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Unsafe publisher output lock.');
        }
        $lock = @fopen($lockPath, 'cb');
        if ($lock === false) {
            throw new RuntimeException('Could not secure publisher output lock.');
        }
        $temp = null;
        try {
            if (! flock($lock, LOCK_EX) || ! chmod($lockPath, 0600)
                || file_exists($target) || is_link($target)) {
                throw new RuntimeException('Signed publication already exists or publisher locked.');
            }
            $temp = tempnam($root, '.signed-runbook-');
            if ($temp === false || is_link($temp) || ! chmod($temp, 0600)
                || file_put_contents($temp, $envelope, LOCK_EX) !== strlen($envelope)
                || ! rename($temp, $target)) {
                throw new RuntimeException('Could not atomically write signed runbook candidate.');
            }
            $temp = null;
            return ['file' => $filename, 'sha256' => $sha];
        } finally {
            if (is_string($temp) && is_file($temp)) {
                @unlink($temp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

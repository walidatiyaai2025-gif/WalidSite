<?php

namespace Tests\Unit;

use App\Domain\Deployment\VerifiedAndroidLatestDownload;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * All signatures use a throwaway Ed25519 key. This does NOT approve any
 * production identity or imply a live host/API connection.
 */
final class VerifiedAndroidLatestDownloadTest extends TestCase
{
    private string $home;
    private string $running;
    private string $public;
    private string $private;
    private string $apk;
    private string $key;
    private string $secret;
    private string $sourceSha;
    private string $signer;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_keypair') || ! class_exists(ZipArchive::class)) {
            self::markTestSkipped('Sodium and ZIP extensions required.');
        }
        $this->home = sys_get_temp_dir().'/walidsite-verified-android-'.bin2hex(random_bytes(10));
        $this->public = $this->home.'/public_html';
        $this->private = $this->home.'/application/storage/app/private/android-release';
        $this->sourceSha = str_repeat('a', 40);
        $this->signer = str_repeat('c', 64);
        $this->running = $this->home.'/.walidsite-releases/v1.2.4-'.$this->sourceSha.'/application';
        self::assertTrue(mkdir($this->public, 0700, true));
        self::assertTrue(mkdir($this->private, 0700, true));
        self::assertTrue(mkdir($this->running, 0700, true));
        file_put_contents($this->home.'/application/VERSION', "1.2.3\n");
        file_put_contents($this->running.'/VERSION', "1.2.4\n");
        file_put_contents($this->home.'/.walidsite-active-release.json', json_encode([
            'schema' => 1, 'version' => '1.2.4',
            'root' => '.walidsite-releases/v1.2.4-'.$this->sourceSha,
        ], JSON_THROW_ON_ERROR));
        chmod($this->home.'/.walidsite-active-release.json', 0600);
        $this->apk = $this->private.'/WalidSite-Android-v1.2.4.apk';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->apk, ZipArchive::CREATE | ZipArchive::EXCL) === true);
        self::assertTrue($zip->addFromString('AndroidManifest.xml', 'android-manifest-fixture'));
        self::assertTrue($zip->addFromString('classes.dex', str_repeat('DEX', 200)));
        self::assertTrue($zip->close());
        chmod($this->apk, 0600);
        $kp = sodium_crypto_sign_keypair();
        $this->key = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secret = sodium_crypto_sign_secretkey($kp);
        $this->signReceipt();
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function pairing(array $change = []): array
    {
        return array_replace([
            'schema' => 'walidsite.android-live-pairing.v1',
            'product' => 'walidsite',
            'web_version' => '1.2.4',
            'web_source_sha' => $this->sourceSha,
            'api_origin' => 'https://walidatiya.com',
            'apk_version' => '1.2.4',
            'apk_source_sha' => $this->sourceSha,
            'apk_sha256' => hash_file('sha256', $this->apk),
            'apk_size' => filesize($this->apk),
            'apk_signer_sha256' => $this->signer,
            'apk_file' => 'WalidSite-Android-v1.2.4.apk',
            'verified_at' => time() - 20,
            'release_notes' => 'Validated after real live Web and Android release acceptance.',
            'live_https_verified' => true,
            'apk_signature_verified' => true,
            'web_apk_compatible' => true,
            'production_ready' => true,
        ], $change);
    }

    private function signReceipt(array $change = [], bool $tamper = false): void
    {
        $data = json_encode($this->pairing($change), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($data, $this->secret);
        if ($tamper) {
            $signature[0] = chr(ord($signature[0]) ^ 1);
        }
        $envelope = json_encode([
            'payload' => base64_encode($data),
            'signature' => base64_encode($signature),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->private.'/latest-pairing.json', $envelope);
        chmod($this->private.'/latest-pairing.json', 0600);
    }

    private function resolve(
        ?string $running = null, ?string $origin = null, ?string $signer = null,
        ?string $private = null, ?string $publisher = null,
    ): array {
        return (new VerifiedAndroidLatestDownload())->resolve(
            $running ?? $this->running,
            $this->public,
            $private ?? $this->private,
            '1.2.3',
            $origin ?? 'https://walidatiya.com',
            $publisher ?? $this->key,
            $signer ?? $this->signer,
        );
    }

    public function test_signed_live_web_pairing_and_real_private_zip_bytes_allow_exact_download(): void
    {
        $result = $this->resolve();
        try {
            self::assertSame('1.2.4', $result['meta']['web_version']);
            self::assertSame('1.2.4', $result['meta']['apk_version']);
            self::assertSame($this->sourceSha, $result['meta']['apk_source_sha']);
            self::assertSame(hash_file('sha256', $this->apk), $result['meta']['apk_sha256']);
            self::assertSame(file_get_contents($this->apk), stream_get_contents($result['stream']));
        } finally {
            fclose($result['stream']);
        }
    }

    public function test_unattested_first_install_and_wrong_active_pointer_are_blocked(): void
    {
        $this->expectException(DomainException::class);
        $this->resolve($this->home.'/application');
    }

    public function test_rollback_to_older_web_release_never_exposes_stale_apk(): void
    {
        file_put_contents($this->running.'/VERSION', "1.2.5\n");
        $this->expectException(DomainException::class);
        $this->resolve();
    }

    public function test_signed_wrong_origin_web_sha_signer_or_version_is_rejected(): void
    {
        foreach ([
            ['api_origin' => 'https://other.example.com'],
            ['web_source_sha' => str_repeat('b', 40)],
            ['apk_source_sha' => str_repeat('b', 40)],
            ['apk_signer_sha256' => str_repeat('d', 64)],
            ['apk_version' => '1.2.3'],
            ['web_version' => '1.2.3'],
            ['verified_at' => time() + 3600],
            ['product' => 'other'],
            ['live_https_verified' => false],
            ['apk_signature_verified' => false],
            ['web_apk_compatible' => false],
            ['production_ready' => false],
        ] as $case) {
            $this->signReceipt($case);
            try {
                $this->resolve();
                self::fail('Unverified production pairing was accepted: '.implode(',', array_keys($case)));
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_wrong_publisher_key_and_tampered_signature_are_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->resolve(publisher: base64_encode(str_repeat('x', 32)));
    }

    public function test_corrupt_signature_cannot_override_a_correct_archive_hash(): void
    {
        $this->signReceipt([], true);
        $this->expectException(DomainException::class);
        $this->resolve();
    }

    public function test_changed_apk_bytes_rejected_even_with_genuine_prior_publisher_signature(): void
    {
        file_put_contents($this->apk, 'TAMPERED', FILE_APPEND);
        $this->expectException(DomainException::class);
        $this->resolve();
    }

    public function test_symlink_hardlink_relaxed_mode_and_public_storage_are_rejected(): void
    {
        $receipt = $this->private.'/latest-pairing.json';
        $original = file_get_contents($receipt);
        chmod($receipt, 0644);
        try {
            $this->resolve();
            self::fail('World-readable receipt accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        chmod($receipt, 0600);
        $alias = $this->home.'/alias-pairing';
        self::assertTrue(link($receipt, $alias));
        try {
            $this->resolve();
            self::fail('Hardlinked signed receipt accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        } finally {
            unlink($alias);
        }
        unlink($receipt);
        file_put_contents($this->home.'/pairing-target', $original);
        symlink($this->home.'/pairing-target', $receipt);
        try {
            $this->resolve();
            self::fail('Symlinked signed receipt accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        unlink($receipt);
        $this->signReceipt();
        $this->expectException(DomainException::class);
        $this->resolve(private: $this->public);
    }

    public function test_missing_production_private_evidence_returns_no_apk(): void
    {
        unlink($this->private.'/latest-pairing.json');
        $this->expectException(DomainException::class);
        $this->resolve();
    }

    public function test_maintenance_marker_refuses_verified_apk_when_web_could_be_switching(): void
    {
        file_put_contents($this->home.'/.walidsite-update-maintenance.json', '{"expires_at":0}');
        $this->expectException(DomainException::class);
        $this->resolve();
    }
}

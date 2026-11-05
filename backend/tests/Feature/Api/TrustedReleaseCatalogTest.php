<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseCatalog;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TrustedReleaseCatalogTest extends TestCase
{
    private string $publisherPublicKey;
    private string $publisherSecretKey;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('Ed25519 sodium extension required.');
        }
        $pair = sodium_crypto_sign_keypair();
        $this->publisherPublicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->publisherSecretKey = sodium_crypto_sign_secretkey($pair);
    }

    private function envelope(string $version = '1.2.0'): string
    {
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => $version,
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 4096,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v'.$version.'.zip',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $this->publisherSecretKey)),
        ], JSON_THROW_ON_ERROR);
    }

    public function test_exact_trusted_catalog_url_yields_only_publisher_signed_release(): void
    {
        $envelope = $this->envelope();
        $calls = 0;
        $result = (new TrustedReleaseCatalog(
            static function (string $url, \Closure $write) use ($envelope, &$calls): int {
                $calls++;
                self::assertSame('https://updates.example.test/releases/latest.json', $url);
                for ($offset = 0; $offset < strlen($envelope); $offset += 7) {
                    $chunk = substr($envelope, $offset, 7);
                    self::assertSame(strlen($chunk), $write($chunk));
                }
                return 200;
            },
        ))->check('https://updates.example.test', $this->publisherPublicKey, '1.0.0');

        self::assertSame(1, $calls);
        self::assertSame($envelope, $result['envelope']);
        self::assertSame('1.2.0', $result['release']['version']);
        self::assertSame('https://updates.example.test/releases/WalidSite-update-v1.2.0.zip',
            $result['release']['artifact_url']);
    }

    public function test_invalid_origin_cannot_call_transport(): void
    {
        foreach ([
            'http://updates.example.test',
            'https://127.0.0.1',
            'https://localhost',
            'https://updates.example.test:8443',
            'https://updates.example.test/anything',
            'https://user:pass@updates.example.test',
            'https://updates.example.test?redirect=1',
            'https://updates..example.test',
        ] as $origin) {
            $calls = 0;
            try {
                (new TrustedReleaseCatalog(static function () use (&$calls): int {
                    $calls++;
                    return 200;
                }))->check($origin, $this->publisherPublicKey, '1.0.0');
                self::fail('Unapproved catalog origin accepted: '.$origin);
            } catch (DomainException) {
                self::assertSame(0, $calls, $origin);
            }
        }
    }

    public function test_catalog_and_signed_metadata_share_exact_publisher_origin_policy(): void
    {
        $envelope = $this->envelope();
        foreach ([
            'https://internal',
            'https://10.0.0.9',
            'https://updates.example.test.',
            'https://-updates.example.test',
            'https://updates.example.test:443',
        ] as $origin) {
            $called = false;
            try {
                (new TrustedReleaseCatalog(
                    static function () use (&$called): int {
                        $called = true;
                        return 200;
                    },
                ))->check($origin, $this->publisherPublicKey, '1.0.0');
                self::fail('Unsafe publisher origin accepted: '.$origin);
            } catch (DomainException) {
                self::assertFalse($called, $origin);
            }
        }
    }

    public function test_redirect_http_error_and_oversize_catalog_are_rejected(): void
    {
        $envelope = $this->envelope();
        foreach ([301, 302, 307, 404, 500] as $status) {
            $this->reject($this->publisherPublicKey,
                static function (string $url, \Closure $write) use ($envelope, $status): int {
                    $write($envelope);
                    return $status;
                });
        }

        $this->reject($this->publisherPublicKey,
            static function (string $url, \Closure $write): int {
                self::assertSame(0, $write(str_repeat('X', 16385)));
                return 200;
            });
        $this->reject($this->publisherPublicKey,
            static function (string $url, \Closure $write): int {
                $write('not-json');
                return 200;
            });
    }

    public function test_untrusted_signature_is_rejected_after_fetch(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $wrong = base64_encode(sodium_crypto_sign_publickey($otherPair));
        $envelope = $this->envelope();
        $this->reject($wrong,
            static function (string $url, \Closure $write) use ($envelope): int {
                $write($envelope);
                return 200;
            });
    }

    public function test_replayed_or_downgraded_signed_catalog_is_rejected(): void
    {
        foreach (['1.0.0', '0.9.9'] as $version) {
            $envelope = $this->envelope($version);
            $this->reject($this->publisherPublicKey,
                static function (string $url, \Closure $write) use ($envelope): int {
                    $write($envelope);
                    return 200;
                });
        }
    }

    private function reject(string $key, \Closure $transport): void
    {
        try {
            (new TrustedReleaseCatalog($transport))->check(
                'https://updates.example.test', $key, '1.0.0',
            );
            self::fail('Invalid publisher release catalog was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseMetadata;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TrustedReleaseMetadataTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $metadata;

    private string $secretKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->metadata = [
            'product' => 'walidsite',
            'version' => '1.2.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 450000,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v1.2.0.zip',
        ];
    }

    private function signed(?array $metadata = null, ?string $secretKey = null): string
    {
        $payload = json_encode($metadata ?? $this->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secretKey ?? $this->secretKey)),
        ], JSON_THROW_ON_ERROR);
    }

    private function verify(string $envelope, ?string $origin = null, ?string $installed = null): array
    {
        return (new TrustedReleaseMetadata())->verify(
            $envelope, $this->publicKey, $origin ?? 'https://updates.example.test', $installed ?? '1.1.9',
        );
    }

    public function test_valid_signed_upgrade_produces_pinned_artifact_url_without_downloading_code(): void
    {
        $result = $this->verify($this->signed());
        self::assertSame('1.2.0', $result['version']);
        self::assertSame('https://updates.example.test/releases/WalidSite-update-v1.2.0.zip', $result['artifact_url']);
        self::assertSame(str_repeat('b', 64), $result['artifact_sha256']);
    }

    public function test_modified_or_unknown_signatures_are_rejected(): void
    {
        $envelope = json_decode($this->signed(), true, 16, JSON_THROW_ON_ERROR);
        $envelope['payload'] = base64_encode(str_replace('1.2.0', '1.3.0', base64_decode($envelope['payload'], true)));
        $this->assertRejected(json_encode($envelope, JSON_THROW_ON_ERROR));

        $otherPair = sodium_crypto_sign_keypair();
        $this->assertRejected($this->signed($this->metadata, sodium_crypto_sign_secretkey($otherPair)));
    }

    public function test_wrong_product_equal_version_downgrade_and_bad_hash_fail_closed(): void
    {
        foreach ([
            ['product' => 'other-site'],
            ['source_sha' => str_repeat('z', 40)],
            ['artifact_sha256' => 'not-a-hash'],
            ['artifact_size' => 0],
            ['artifact_size' => 1073741825],
            ['artifact_size' => '450000'],
            ['artifact_file' => '../WalidSite-update-v1.2.0.zip'],
            ['artifact_file' => 'WalidSite-update-v1.2.0.zip/../evil.php'],
            ['min_php' => '999.0.0'],
            ['version' => '1.1.9'],
            ['version' => '0.9.9'],
        ] as $changes) {
            $this->assertRejected($this->signed(array_replace($this->metadata, $changes)));
        }
        $this->assertRejected($this->signed(), 'https://updates.example.test/rewrite');
        $this->assertRejected($this->signed(), 'http://updates.example.test');
        $this->assertRejected($this->signed(), 'https://user:password@updates.example.test');
        $this->assertRejected($this->signed(), 'https://updates.example.test:8443');
        $this->assertRejected($this->signed(), 'https://updates.example.test?redirect=evil');
        $this->assertRejected($this->signed(), 'https://updates.example.test#frag');
        $this->assertRejected($this->signed(), 'https://updates.example.test', '1.2.0');
    }

    public function test_invalid_and_oversized_envelopes_and_bad_key_fail_closed(): void
    {
        foreach (['', '[]', '{', '{}', str_repeat('x', 16385)] as $bad) {
            $this->assertRejected($bad);
        }
        $envelope = json_decode($this->signed(), true, 16, JSON_THROW_ON_ERROR);
        $envelope['unexpected'] = 'extra';
        $this->assertRejected(json_encode($envelope, JSON_THROW_ON_ERROR));

        $this->expectException(DomainException::class);
        (new TrustedReleaseMetadata())->verify($this->signed(), 'invalid-base64', 'https://updates.example.test', '1.1.9');
    }

    private function assertRejected(string $signed, ?string $origin = null, ?string $installed = null): void
    {
        try {
            $this->verify($signed, $origin, $installed);
            self::fail('Untrusted release metadata was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

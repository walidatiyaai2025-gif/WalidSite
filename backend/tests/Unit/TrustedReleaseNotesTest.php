<?php

namespace Tests\Unit;

use App\Domain\Deployment\TrustedReleaseMetadata;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TrustedReleaseNotesTest extends TestCase
{
    /** @return array<string,int|string> */
    private function payload(): array
    {
        return [
            'product' => 'walidsite',
            'version' => '0.1.3',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 125,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v0.1.3.zip',
        ];
    }

    private function signedEnvelope(array $meta, string $secret): string
    {
        $payload = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function test_legacy_catalog_and_authenticated_safe_notes_are_both_supported(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $key = base64_encode(sodium_crypto_sign_publickey($pair));
        $verifier = new TrustedReleaseMetadata();
        $legacy = $verifier->verify(
            $this->signedEnvelope($this->payload(), $secret),
            $key, 'https://updates.example.com', '0.1.2'
        );
        self::assertArrayNotHasKey('release_notes', $legacy);

        $meta = $this->payload();
        $meta['release_notes'] = "Security fixes and recovery checks\nAdmin <script>alert(1)</script>";
        $verified = $verifier->verify(
            $this->signedEnvelope($meta, $secret),
            $key, 'https://updates.example.com', '0.1.2'
        );
        self::assertSame($meta['release_notes'], $verified['release_notes']);
        self::assertSame($legacy['artifact_url'], $verified['artifact_url']);
        self::assertSame($legacy['artifact_sha256'], $verified['artifact_sha256']);
    }

    public function test_notes_outside_signed_bytes_cannot_be_changed_or_appended(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $key = base64_encode(sodium_crypto_sign_publickey($pair));
        $meta = $this->payload();
        $meta['release_notes'] = 'Publisher approved text';
        $envelope = json_decode($this->signedEnvelope($meta, $secret), true, 8, JSON_THROW_ON_ERROR);
        $meta['release_notes'] = 'Attacker modified text';
        $envelope['payload'] = base64_encode(json_encode($meta, JSON_THROW_ON_ERROR));
        try {
            (new TrustedReleaseMetadata())->verify(
                json_encode($envelope, JSON_THROW_ON_ERROR),
                $key, 'https://updates.example.com', '0.1.2'
            );
            self::fail('Tampered notes bypassed Ed25519.');
        } catch (DomainException $e) {
            self::assertSame('Untrusted release signature.', $e->getMessage());
        }

        // Unrecognized unsigned metadata must never be silently ignored.
        $meta = $this->payload();
        $meta['notes_url'] = 'https://untrusted.example.org/notes';
        $this->expectException(DomainException::class);
        (new TrustedReleaseMetadata())->verify(
            $this->signedEnvelope($meta, $secret),
            $key, 'https://updates.example.com', '0.1.2'
        );
    }

    public function test_signed_notes_reject_controls_bidi_invalid_utf8_empty_and_oversize(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $key = base64_encode(sodium_crypto_sign_publickey($pair));
        foreach ([
            '',
            "Hidden\x00field",
            "Overwritten\rline",
            "Tab\tname",
            "Bidi \u{202E} override",
            "Isolate \u{2066}evil",
            "\xff",
            str_repeat('A', 2049),
            null,
            ['unexpected'],
        ] as $value) {
            self::assertFalse(TrustedReleaseMetadata::validReleaseNotes($value));
            // Invalid UTF-8 cannot be encoded as JSON with JSON_THROW_ON_ERROR;
            // the serializer rejects it before any signed-envelope verification.
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                continue;
            }
            $meta = $this->payload();
            $meta['release_notes'] = $value;
            try {
                (new TrustedReleaseMetadata())->verify(
                    $this->signedEnvelope($meta, $secret),
                    $key, 'https://updates.example.com', '0.1.2'
                );
                self::fail('Unsafe publisher notes were accepted.');
            } catch (DomainException $e) {
                self::assertSame('Invalid publisher-signed release notes.', $e->getMessage());
            }
        }
        self::assertTrue(TrustedReleaseMetadata::validReleaseNotes("Summary\nDetails"));
        self::assertTrue(TrustedReleaseMetadata::validReleaseNotes(str_repeat('a', 2048)));
    }
}

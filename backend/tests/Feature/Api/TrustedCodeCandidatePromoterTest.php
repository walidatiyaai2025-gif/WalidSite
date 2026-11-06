<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\AtomicReleaseSwitcher;
use App\Domain\Deployment\TrustedCodeCandidatePromoter;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class TrustedCodeCandidatePromoterTest extends TestCase
{
    private string $home;
    private string $quarantine;
    private string $incoming;
    private string $releases;
    private string $current;
    private string $publicRoot;
    private string $old;
    private string $secret;
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('Real ZIP extraction requires ext-zip.');
        }
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->home = sys_get_temp_dir().'/walidsite-promotion-'.bin2hex(random_bytes(8));
        $this->quarantine = $this->home.'/quarantine';
        $this->incoming = $this->quarantine.'/incoming';
        $this->releases = $this->home.'/releases';
        $this->current = $this->home.'/current';
        $this->publicRoot = $this->home.'/public_html';
        $this->old = $this->releases.'/v1';
        foreach ([$this->incoming, $this->old.'/application/bootstrap', $this->old.'/application/vendor', $this->old.'/public_html'] as $path) {
            self::assertTrue(mkdir($path, 0700, true));
        }
        foreach ([
            'application/bootstrap/app.php',
            'application/vendor/autoload.php',
            'public_html/index.php',
            'public_html/.htaccess',
        ] as $file) {
            file_put_contents($this->old.'/'.$file, 'LIVE-v1');
        }
        self::assertTrue(symlink($this->old, $this->current));
        self::assertTrue(symlink($this->current.'/public_html', $this->publicRoot));
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                if ($item->isDir() && ! $item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    /** @return array{0:string,1:string} */
    private function signedArchive(bool $unsafeZip = false, ?string $signingSecret = null): array
    {
        $file = $this->incoming.'/WalidSite-update-v1.2.0.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => 'NEXT-v2',
            'application/vendor/autoload.php' => 'NEXT-v2',
            'public_html/index.php' => 'NEXT-v2',
            'public_html/.htaccess' => 'NEXT-v2',
            'contracts/product.json' => '{"product":"walidsite"}',
        ] as $path => $bytes) {
            self::assertTrue($zip->addFromString($path, $bytes));
        }
        if ($unsafeZip) {
            self::assertTrue($zip->addFromString('application/../../escape.php', '<?php evil();'));
        }
        self::assertTrue($zip->close());

        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '1.2.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash_file('sha256', $file),
            'artifact_size' => filesize($file),
            'min_php' => '8.3.0',
            'artifact_file' => basename($file),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $envelope = json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $signingSecret ?? $this->secret)),
        ], JSON_THROW_ON_ERROR);

        return [$file, $envelope];
    }

    private function promote(string $file, string $signed, ?string $quarantine = null): array
    {
        return (new TrustedCodeCandidatePromoter())->promote(
            $signed, $this->publicKey, 'https://updates.example.com', '1.0.0',
            $quarantine ?? $this->quarantine, $this->incoming, $file,
            $this->releases, $this->publicRoot,
        );
    }

    public function test_real_signed_zip_promotes_complete_version_without_touching_live_code(): void
    {
        [$zip, $signed] = $this->signedArchive();
        $result = $this->promote($zip, $signed);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame(5, $result['files']);
        self::assertSame('NEXT-v2', file_get_contents($result['directory'].'/public_html/index.php'));
        self::assertSame('LIVE-v1', file_get_contents($this->publicRoot.'/index.php'));
        self::assertSame($this->old, realpath($this->current));

        $cutover = (new AtomicReleaseSwitcher())->switch(
            $this->releases, $this->current, $this->publicRoot, $result['directory'],
            static fn (): bool => true,
            fn (): bool => file_get_contents($this->publicRoot.'/index.php') === 'NEXT-v2',
        );
        self::assertSame('switched', $cutover['state']);
        self::assertSame('NEXT-v2', file_get_contents($this->publicRoot.'/index.php'));
        self::assertSame('LIVE-v1', file_get_contents($this->old.'/public_html/index.php'));
    }

    public function test_modified_zip_and_wrong_publisher_are_rejected_before_promotion(): void
    {
        [$file, $signed] = $this->signedArchive();
        file_put_contents($file, 'tampered');
        $this->reject(fn () => $this->promote($file, $signed));

        [$file, $signed] = $this->signedArchive(false, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->reject(fn () => $this->promote($file, $signed));
        self::assertSame($this->old, realpath($this->current));
    }

    public function test_signed_zip_containing_traversal_is_rejected(): void
    {
        [$file, $signed] = $this->signedArchive(true);
        $this->reject(fn () => $this->promote($file, $signed));
        self::assertSame('LIVE-v1', file_get_contents($this->publicRoot.'/index.php'));
    }

    public function test_quarantine_cannot_be_the_release_root_or_ancestor_of_public_site(): void
    {
        [$file, $signed] = $this->signedArchive();
        $this->reject(fn () => $this->promote($file, $signed, $this->releases));
        self::assertSame($this->old, realpath($this->current));
    }

    public function test_duplicate_version_directory_refuses_replacement(): void
    {
        [$file, $signed] = $this->signedArchive();
        $result = $this->promote($file, $signed);
        $this->reject(fn () => $this->promote($file, $signed));
        self::assertSame('NEXT-v2', file_get_contents($result['directory'].'/public_html/index.php'));
    }

    public function test_physical_cpanel_docroot_requires_explicit_exact_private_sibling_layout(): void
    {
        [$file, $signed] = $this->signedArchive();
        $home = $this->home.'/physical-host';
        $releases = $home.'/.walidsite-releases';
        $public = $home.'/public_html';
        self::assertTrue(mkdir($releases, 0700, true));
        self::assertTrue(mkdir($public, 0700));

        $invoke = fn (bool $approved): array => (new TrustedCodeCandidatePromoter())->promote(
            $signed, $this->publicKey, 'https://updates.example.com', '1.0.0',
            $this->quarantine, $this->incoming, $file,
            $releases, $public, verifiedPhysicalCpanelLayout: $approved,
        );

        $this->reject(fn () => $invoke(false));
        self::assertSame([], glob($releases.'/v1.2*') ?: []);
        $result = $invoke(true);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame('NEXT-v2', file_get_contents($result['directory'].'/public_html/index.php'));
        // The physical web document root was NEVER switched by staging.
        self::assertSame([], glob($public.'/*') ?: []);

        // An unrelated physical document root cannot obtain the opt-in.
        $wrongPublic = $this->home.'/other-public';
        self::assertTrue(mkdir($wrongPublic, 0700));
        $this->reject(fn () => (new TrustedCodeCandidatePromoter())->promote(
            $signed, $this->publicKey, 'https://updates.example.com', '1.0.0',
            $this->quarantine, $this->incoming, $file,
            $releases, $wrongPublic, verifiedPhysicalCpanelLayout: true,
        ));
    }

    private function reject(callable $callback): void
    {
        try {
            $callback();
            self::fail('An unsafe signed code candidate was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

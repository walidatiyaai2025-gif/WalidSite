<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseDownloader;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class TrustedReleaseDownloaderTest extends TestCase
{
    private string $home;
    private string $private;
    private string $public;
    private string $publicKey;
    private string $secret;
    private string $zipBytes;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class) || ! function_exists('sodium_crypto_sign_detached')) {
            self::markTestSkipped('Real ZIP and Ed25519 extensions required.');
        }
        $this->home = sys_get_temp_dir().'/walidsite-trusted-download-'.bin2hex(random_bytes(8));
        $this->private = $this->home.'/private';
        $this->public = $this->home.'/public_html';
        self::assertTrue(mkdir($this->private, 0700, true));
        self::assertTrue(mkdir($this->public, 0700, true));
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));

        $fixture = $this->home.'/source.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($fixture, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => '<?php return "vB";',
            'application/vendor/autoload.php' => '<?php return [];',
            'public_html/index.php' => 'NEXT RELEASE',
            'public_html/.htaccess' => 'RewriteEngine On',
            'contracts/product.json' => '{"product":"walidsite"}',
        ] as $path => $bytes) {
            self::assertTrue($zip->addFromString($path, $bytes));
        }
        self::assertTrue($zip->close());
        $this->zipBytes = file_get_contents($fixture);
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $item) {
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

    private function signed(): string
    {
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '1.2.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => hash('sha256', $this->zipBytes),
            'artifact_size' => strlen($this->zipBytes),
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v1.2.0.zip',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $this->secret)),
        ], JSON_THROW_ON_ERROR);
    }

    private function download(\Closure $transport, ?string $directory = null): array
    {
        return (new TrustedReleaseDownloader($transport))->download(
            $this->signed(), $this->publicKey, 'https://updates.example.com',
            '1.0.0', $directory ?? $this->private, $this->public,
        );
    }

    public function test_signed_https_release_downloads_complete_real_zip_in_private_storage(): void
    {
        $called = 0;
        $bytes = $this->zipBytes;
        $result = $this->download(static function (string $url, \Closure $write) use (&$called, $bytes): int {
            $called++;
            self::assertSame(
                'https://updates.example.com/releases/WalidSite-update-v1.2.0.zip',
                $url,
            );
            for ($offset = 0; $offset < strlen($bytes); $offset += 11) {
                $part = substr($bytes, $offset, 11);
                self::assertSame(strlen($part), $write($part));
            }
            return 200;
        });

        self::assertSame(1, $called);
        self::assertSame('1.2.0', $result['version']);
        self::assertSame(hash('sha256', $bytes), $result['artifact_sha256']);
        self::assertSame(strlen($bytes), $result['artifact_size']);
        self::assertSame($this->private.'/WalidSite-update-v1.2.0.zip', $result['path']);
        self::assertSame($bytes, file_get_contents($result['path']));
        self::assertSame([], glob($this->private.'/.release-*.partial') ?: []);
    }

    public function test_valid_signature_never_authorizes_local_or_malformed_publisher_network(): void
    {
        foreach ([
            'https://127.0.0.1',
            'https://localhost',
            'https://internal',
            'https://publisher.localhost',
            'https://publisher.deep.localhost',
            'https://publisher.local',
            'https://publisher.internal',
            'https://publisher.invalid',
            'https://publisher.test',
            'https://publisher.example',
            'https://publisher.home.arpa',
            'https://updates..example.com',
            'https://updates.example.com.',
            'https://updates.example.com:443',
        ] as $origin) {
            $called = false;
            try {
                (new TrustedReleaseDownloader(
                    static function () use (&$called): int {
                        $called = true;
                        return 200;
                    },
                ))->download(
                    $this->signed(), $this->publicKey, $origin,
                    '1.0.0', $this->private, $this->public,
                );
                self::fail('Unsafe pinned publisher origin accepted: '.$origin);
            } catch (DomainException) {
                self::assertFalse($called, $origin);
                self::assertSame([], glob($this->private.'/.release-*.partial') ?: []);
                self::assertSame([], glob($this->private.'/WalidSite-update-*.zip') ?: []);
            }
        }
    }

    public function test_wrong_publisher_does_not_invoke_network_transport(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $called = false;
        try {
            (new TrustedReleaseDownloader(static function () use (&$called): int {
                $called = true;
                return 200;
            }))->download(
                $this->signed(),
                base64_encode(sodium_crypto_sign_publickey($otherPair)),
                'https://updates.example.com',
                '1.0.0', $this->private, $this->public,
            );
            self::fail('Unknown publisher cannot download a release.');
        } catch (DomainException) {
            self::assertFalse($called);
        }
    }

    public function test_redirect_and_wrong_http_status_fail_without_publishing_bytes(): void
    {
        foreach ([302, 401, 404, 500] as $status) {
            $this->reject(static function (string $url, \Closure $write) use ($status): int {
                $write('redirect-body');
                return $status;
            });
        }
    }

    public function test_tampered_download_and_short_body_fail_closed(): void
    {
        $bytes = $this->zipBytes;
        $this->reject(static function (string $url, \Closure $write) use ($bytes): int {
            $write(str_repeat('A', strlen($bytes)));
            return 200;
        });
        $this->reject(static function (string $url, \Closure $write) use ($bytes): int {
            $write(substr($bytes, 0, -1));
            return 200;
        });
    }

    public function test_streamed_oversize_response_is_rejected_without_partial_leaks(): void
    {
        $bytes = $this->zipBytes;
        $this->reject(static function (string $url, \Closure $write) use ($bytes): int {
            self::assertSame(0, $write($bytes.'EXTRA'));
            return 200;
        });
    }

    public function test_public_download_directory_is_rejected_before_transport(): void
    {
        $called = false;
        try {
            $this->download(static function () use (&$called): int {
                $called = true;
                return 200;
            }, $this->public);
            self::fail('Do not download private publisher releases into public_html.');
        } catch (DomainException) {
            self::assertFalse($called);
        }
    }

    public function test_existing_verified_artifact_is_never_replaced(): void
    {
        $bytes = $this->zipBytes;
        $transport = static function (string $url, \Closure $write) use ($bytes): int {
            $write($bytes);
            return 200;
        };
        $this->download($transport);
        try {
            $this->download($transport);
            self::fail('An already verified archive cannot be replaced.');
        } catch (DomainException) {
            self::assertSame($bytes, file_get_contents($this->private.'/WalidSite-update-v1.2.0.zip'));
        }
    }

    public function test_signed_zip_is_inspected_in_private_directory_before_publication(): void
    {
        $bytes = $this->zipBytes;
        $result = $this->download(function (string $url, \Closure $write) use ($bytes): int {
            self::assertSame(strlen($bytes), $write($bytes));
            $folders = glob($this->private.'/.release-*.partial', GLOB_ONLYDIR) ?: [];
            self::assertCount(1, $folders);
            self::assertSame(0700, fileperms($folders[0]) & 0777);
            $source = $folders[0].'/WalidSite-update-v1.2.0.zip';
            self::assertSame($bytes, file_get_contents($source));
            self::assertFileDoesNotExist($this->private.'/WalidSite-update-v1.2.0.zip');
            return 200;
        });
        self::assertSame($bytes, file_get_contents($result['path']));
        self::assertSame([], glob($this->private.'/.release-*.partial') ?: []);
    }

    public function test_byte_identical_staging_inode_swap_is_rejected_without_publication(): void
    {
        $bytes = $this->zipBytes;
        $moved = $this->private.'/original-moved.zip';
        try {
            $this->reject(function (string $url, \Closure $write) use ($bytes, $moved): int {
                self::assertSame(strlen($bytes), $write($bytes));
                $folders = glob($this->private.'/.release-*.partial', GLOB_ONLYDIR) ?: [];
                self::assertCount(1, $folders);
                $source = $folders[0].'/WalidSite-update-v1.2.0.zip';
                self::assertTrue(rename($source, $moved));
                self::assertSame(strlen($bytes), file_put_contents($source, $bytes));
                return 200;
            });
        } finally {
            @unlink($moved);
        }
    }

    public function test_staging_hardlink_alias_is_rejected_before_publication(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX single-link artifact identity required.');
        }
        $alias = $this->private.'/injected-hardlink.zip';
        $bytes = $this->zipBytes;
        try {
            $this->reject(function (string $url, \Closure $write) use ($bytes, $alias): int {
                self::assertSame(strlen($bytes), $write($bytes));
                $folders = glob($this->private.'/.release-*.partial', GLOB_ONLYDIR) ?: [];
                self::assertCount(1, $folders);
                $source = $folders[0].'/WalidSite-update-v1.2.0.zip';
                if (! @link($source, $alias)) {
                    self::markTestSkipped('Hardlink unsupported by this host.');
                }
                return 200;
            });
        } finally {
            @unlink($alias);
        }
    }

    public function test_concurrent_publisher_final_artifact_cannot_be_replaced_or_deleted(): void
    {
        $final = $this->private.'/WalidSite-update-v1.2.0.zip';
        $marker = 'another publisher immutable artifact';
        $bytes = $this->zipBytes;
        try {
            $this->download(function (string $url, \Closure $write) use ($bytes, $final, $marker): int {
                self::assertSame(strlen($bytes), $write($bytes));
                self::assertSame(strlen($marker), file_put_contents($final, $marker));
                return 200;
            });
            self::fail('Concurrent publisher release was replaced.');
        } catch (DomainException) {
            self::assertSame($marker, file_get_contents($final));
            self::assertSame([], glob($this->private.'/.release-*.partial') ?: []);
        }
    }

    public function test_signed_non_zip_bytes_are_rejected_before_publication(): void
    {
        // A valid Ed25519 checksum is not enough: inspect ZIP structure
        // inside quarantine before the final immutable name is published.
        $this->zipBytes = 'signed-but-malformed-zip-data';
        $bytes = $this->zipBytes;
        $this->reject(static function (string $url, \Closure $write) use ($bytes): int {
            $write($bytes);
            return 200;
        });
    }

    private function reject(\Closure $transport): void
    {
        try {
            $this->download($transport);
            self::fail('Unsafe or incomplete trusted release transfer was accepted.');
        } catch (DomainException) {
            self::assertSame([], glob($this->private.'/.release-*.partial') ?: []);
            self::assertSame([], glob($this->private.'/WalidSite-update-*.zip') ?: []);
        }
    }
}

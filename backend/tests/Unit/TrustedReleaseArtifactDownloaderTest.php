<?php

namespace Tests\Unit;

use App\Domain\Deployment\TrustedReleaseArtifactDownloader;
use DomainException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class TrustedReleaseArtifactDownloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/walidsite-publisher-fetch-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function release(string $bytes): array
    {
        return [
            'version' => '0.1.3',
            'artifact_url' => 'https://updates.example.com/releases/WalidSite-update-v0.1.3.zip',
            'artifact_size' => strlen($bytes),
            'artifact_sha256' => hash('sha256', $bytes),
        ];
    }

    private function target(): string
    {
        return $this->root.'/WalidSite-update-v0.1.3.zip';
    }

    private function reject(array $release, callable $transport, string $origin = 'https://updates.example.com'): void
    {
        try {
            (new TrustedReleaseArtifactDownloader($transport))->download($release, $origin, $this->target());
            self::fail('Untrusted or corrupted publisher archive was accepted.');
        } catch (DomainException) {
            self::assertFileDoesNotExist($this->target());
        }
    }

    public function test_exact_signed_bytes_are_streamed_privately_without_executing_or_extracting(): void
    {
        $bytes = str_repeat('signed-cpanel-update-', 32);
        $calls = 0;
        $transport = static function (string $url, \Closure $write) use ($bytes, &$calls): int {
            ++$calls;
            self::assertSame('https://updates.example.com/releases/WalidSite-update-v0.1.3.zip', $url);
            foreach (str_split($bytes, 53) as $chunk) {
                self::assertSame(strlen($chunk), $write($chunk));
            }
            return 200;
        };
        (new TrustedReleaseArtifactDownloader($transport))->download(
            $this->release($bytes), 'https://updates.example.com', $this->target(),
        );
        self::assertSame(1, $calls);
        self::assertSame($bytes, file_get_contents($this->target()));
        self::assertSame(0600, fileperms($this->target()) & 0777);
        self::assertSame(0700, fileperms($this->root) & 0777);
    }

    public function test_failed_partial_redirect_and_modified_bytes_leave_no_executable_archive(): void
    {
        $bytes = str_repeat('publisher-archive-', 32);
        foreach ([
            'redirect' => static function (string $url, \Closure $write) use ($bytes): int {
                $write($bytes);
                return 302;
            },
            'short' => static function (string $url, \Closure $write) use ($bytes): int {
                $write(substr($bytes, 0, -1));
                return 200;
            },
            'altered' => static function (string $url, \Closure $write) use ($bytes): int {
                $write(str_repeat('x', strlen($bytes)));
                return 200;
            },
            'overflow' => static function (string $url, \Closure $write) use ($bytes): int {
                self::assertSame(0, $write($bytes.'overflow'));
                return 200;
            },
            'transport_failure' => static function (string $url, \Closure $write) use ($bytes): int {
                $write(substr($bytes, 0, 16));
                throw new DomainException('Publisher connection interrupted.');
            },
        ] as $transport) {
            $this->reject($this->release($bytes), $transport);
        }
    }

    public function test_only_exact_pinned_publisher_url_private_directory_and_unused_filename_are_allowed(): void
    {
        $bytes = 'safe-archive-byte-fixture';
        $called = 0;
        $transport = static function () use (&$called): int { ++$called; return 200; };
        $release = $this->release($bytes);
        $wrong = $release;
        $wrong['artifact_url'] = 'https://other.example.com/releases/WalidSite-update-v0.1.3.zip';
        $this->reject($wrong, $transport);
        $this->reject($release, $transport, 'https://localhost');
        $this->reject($release, $transport, 'http://updates.example.com');

        file_put_contents($this->target(), 'KEEP_OWNER_DATA');
        try {
            (new TrustedReleaseArtifactDownloader($transport))->download(
                $release, 'https://updates.example.com', $this->target(),
            );
            self::fail('Existing private data cannot be overwritten by signed download.');
        } catch (DomainException) {
            self::assertSame('KEEP_OWNER_DATA', file_get_contents($this->target()));
        }
        unlink($this->target());
        self::assertSame(0, $called);
    }

    public function test_linked_or_world_writable_destination_is_refused_before_publisher_http(): void
    {
        $bytes = 'safe-archive-byte-fixture';
        $release = $this->release($bytes);
        $called = 0;
        $transport = static function () use (&$called): int { ++$called; return 200; };
        chmod($this->root, 0777);
        try {
            $this->reject($release, $transport);
        } finally {
            chmod($this->root, 0700);
        }
        if (function_exists('symlink')) {
            $private = $this->root.'/private-owner.txt';
            file_put_contents($private, 'PRESERVE_SECRET');
            self::assertTrue(symlink($private, $this->target()));
            try {
                (new TrustedReleaseArtifactDownloader($transport))->download(
                    $release, 'https://updates.example.com', $this->target(),
                );
                self::fail('Linked owner file cannot be overwritten.');
            } catch (DomainException) {
                self::assertSame('PRESERVE_SECRET', file_get_contents($private));
            }
        }
        self::assertSame(0, $called);
    }
}

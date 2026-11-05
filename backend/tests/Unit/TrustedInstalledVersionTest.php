<?php

namespace Tests\Unit;

use App\Domain\Deployment\TrustedInstalledVersion;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TrustedInstalledVersionTest extends TestCase
{
    private string $home;
    private string $versionPath;
    private TrustedInstalledVersion $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-version-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->home, 0700));
        $this->versionPath = $this->home.'/VERSION';
        $this->reader = new TrustedInstalledVersion();
        self::assertNotFalse(file_put_contents($this->versionPath, "1.2.3\n"));
        self::assertTrue(chmod($this->versionPath, 0600));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->home);
        parent::tearDown();
    }

    public function test_reads_only_an_unchanged_canonical_regular_release_version(): void
    {
        self::assertSame('1.2.3', $this->reader->read($this->versionPath));
        self::assertSame("1.2.3\n", file_get_contents($this->versionPath));
    }

    public function test_fails_closed_on_missing_oversized_or_noncanonical_versions(): void
    {
        foreach (["", "1.2.3.4\n", "01.2.3\n", "1.2.3-rc1\n",
            "not-a-version\n", str_repeat('9', 33)] as $invalid) {
            file_put_contents($this->versionPath, $invalid);
            self::assertNull($this->reader->read($this->versionPath));
        }
        unlink($this->versionPath);
        self::assertNull($this->reader->read($this->versionPath));
    }

    public function test_denies_public_alias_hardlinks_even_with_valid_bytes(): void
    {
        $alias = $this->home.'/public-version.txt';
        self::assertTrue(link($this->versionPath, $alias));
        self::assertNull($this->reader->read($this->versionPath));
        unlink($alias);
        self::assertSame('1.2.3', $this->reader->read($this->versionPath));
    }

    public function test_denies_symlink_and_group_or_world_writable_version(): void
    {
        $original = $this->home.'/original-version';
        self::assertTrue(rename($this->versionPath, $original));
        self::assertTrue(symlink($original, $this->versionPath));
        self::assertNull($this->reader->read($this->versionPath));
        unlink($this->versionPath);
        self::assertTrue(rename($original, $this->versionPath));
        foreach ([0660, 0602, 0666] as $mode) {
            self::assertTrue(chmod($this->versionPath, $mode));
            self::assertNull($this->reader->read($this->versionPath));
        }
        self::assertTrue(chmod($this->versionPath, 0600));
        self::assertSame('1.2.3', $this->reader->read($this->versionPath));
    }

    public function test_rejects_special_files_before_attempting_a_read(): void
    {
        if (! function_exists('posix_mkfifo')) {
            self::markTestSkipped('Named pipe construction unavailable.');
        }
        unlink($this->versionPath);
        self::assertTrue(posix_mkfifo($this->versionPath, 0600));
        // A mistaken fopen of this FIFO would block the test indefinitely.
        self::assertNull($this->reader->read($this->versionPath));
    }

    public function test_rejects_pathname_replacement_while_descriptor_is_held(): void
    {
        $stream = fopen($this->versionPath, 'rb');
        self::assertNotFalse($stream);
        try {
            $baseline = fstat($stream);
            self::assertIsArray($baseline);
            $moved = $this->home.'/old-version';
            self::assertTrue(rename($this->versionPath, $moved));
            self::assertNotFalse(file_put_contents($this->versionPath, "1.2.3\n"));
            self::assertTrue(chmod($this->versionPath, 0600));
            $method = new ReflectionMethod(TrustedInstalledVersion::class, 'stableIdentity');
            self::assertFalse($method->invoke($this->reader, $stream, $this->versionPath, $baseline));
            self::assertSame('1.2.3', $this->reader->read($this->versionPath));
        } finally {
            fclose($stream);
        }
    }

    public function test_invalid_path_and_symlinked_parent_are_denied(): void
    {
        self::assertNull($this->reader->read($this->home.'/missing'));
        $alias = $this->home.'/parent-link';
        self::assertTrue(symlink($this->home, $alias));
        self::assertNull($this->reader->read($alias.'/VERSION'));
    }
}

<?php

namespace Tests\Unit;

use App\Domain\Deployment\DurableRecoveryManifest;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DurableRecoveryManifestTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/walidsite-durable-manifest-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->root.'/'.$entry);
            }
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_large_manifest_is_exclusively_written_as_complete_private_single_linked_inode(): void
    {
        $path = $this->root.'/manifest.json';
        $json = json_encode(['schema' => 2, 'data' => str_repeat('a', 1000000)], JSON_THROW_ON_ERROR);

        (new DurableRecoveryManifest())->write($path, $json);

        clearstatcache(true, $path);
        self::assertSame($json, file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(1, stat($path)['nlink']);
        self::assertSame(strlen($json), filesize($path));
    }

    public function test_existing_manifest_cannot_be_overwritten_or_truncated(): void
    {
        $path = $this->root.'/manifest.json';
        file_put_contents($path, 'original recovery data');
        $this->expectException(RuntimeException::class);

        try {
            (new DurableRecoveryManifest())->write($path, '{"schema":99}');
        } finally {
            self::assertSame('original recovery data', file_get_contents($path));
        }
    }

    public function test_symlink_manifest_is_rejected_without_writing_to_its_target(): void
    {
        $target = $this->root.'/protected.json';
        $path = $this->root.'/manifest.json';
        file_put_contents($target, 'protected source bytes');
        self::assertTrue(symlink($target, $path));

        try {
            (new DurableRecoveryManifest())->write($path, '{"forged":true}');
            self::fail('A linked manifest must not be created or overwritten.');
        } catch (RuntimeException) {
            self::assertTrue(is_link($path));
            self::assertSame('protected source bytes', file_get_contents($target));
        }
    }

    public function test_empty_manifest_fails_closed_without_creating_a_file(): void
    {
        $path = $this->root.'/manifest.json';
        $this->expectException(DomainException::class);
        try {
            (new DurableRecoveryManifest())->write($path, '');
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }
}

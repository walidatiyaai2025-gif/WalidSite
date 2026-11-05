<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseUpdateJourney;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

final class TrustedReleaseCandidateSealTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/walidsite-candidate-seal-'.bin2hex(random_bytes(10));
        self::assertTrue(mkdir($this->root, 0700));
        foreach (['application', 'public_html', 'contracts'] as $dir) {
            self::assertTrue(mkdir($this->root.'/'.$dir, 0700));
        }
        self::assertSame(strlen('<?php true;'), file_put_contents($this->root.'/application/app.php', '<?php true;'));
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($walk as $item) {
                $path = $item->getPathname();
                $item->isDir() && ! $item->isLink() ? @rmdir($path) : @unlink($path);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function seal(): string
    {
        $method = new ReflectionMethod(TrustedReleaseUpdateJourney::class, 'seal');
        return $method->invoke(new TrustedReleaseUpdateJourney(), $this->root);
    }

    public function test_code_candidate_seal_binds_file_contents_names_and_release_paths(): void
    {
        $first = $this->seal();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        self::assertSame($first, $this->seal());

        // The content hash is not just a pathname/size inventory.
        self::assertSame(strlen('<?php exit;'), file_put_contents($this->root.'/application/app.php', '<?php exit;'));
        self::assertNotSame($first, $this->seal());
        self::assertSame(1, file_put_contents($this->root.'/contracts/a', 'a'));
        self::assertNotSame($first, $this->seal());
    }

    public function test_hostile_filesystem_record_delimiters_are_not_valid_signed_zip_paths(): void
    {
        foreach (["name\nrecord", "name:record", "name\trecord"] as $name) {
            $file = $this->root.'/application/'.$name;
            self::assertSame(3, file_put_contents($file, 'abc'));
            try {
                $this->seal();
                self::fail('Untrusted signed candidate pathname was accepted');
            } catch (DomainException $expected) {
                self::assertStringContainsString('inventory path', $expected->getMessage());
            } finally {
                @unlink($file);
            }
        }
    }

    public function test_hardlinked_candidate_file_is_rejected(): void
    {
        $alias = $this->root.'/contracts/alias.txt';
        if (! @link($this->root.'/application/app.php', $alias)) {
            self::markTestSkipped('POSIX hardlinks are unavailable');
        }
        $this->expectException(DomainException::class);
        $this->seal();
    }

    public function test_symlinked_candidate_file_and_directory_are_rejected(): void
    {
        $source = $this->root.'/application/app.php';
        $alias = $this->root.'/contracts/link.php';
        if (! @symlink($source, $alias)) {
            self::markTestSkipped('POSIX symlinks are unavailable');
        }
        try {
            $this->expectException(DomainException::class);
            $this->seal();
        } finally {
            @unlink($alias);
        }
    }

    public function test_byte_identical_pathname_replacement_does_not_match_open_descriptor(): void
    {
        $path = $this->root.'/application/app.php';
        $stream = fopen($path, 'rb');
        self::assertIsResource($stream);
        try {
            $baseline = fstat($stream);
            self::assertIsArray($baseline);
            $check = new ReflectionMethod(TrustedReleaseUpdateJourney::class, 'assertStableCandidateFile');
            $check->invoke(new TrustedReleaseUpdateJourney(), $stream, $path, $baseline);

            $other = $this->root.'/application/old.php';
            self::assertTrue(rename($path, $other));
            self::assertSame(strlen('<?php true;'), file_put_contents($path, '<?php true;'));
            $this->expectException(DomainException::class);
            $check->invoke(new TrustedReleaseUpdateJourney(), $stream, $path, $baseline);
        } finally {
            fclose($stream);
        }
    }
}

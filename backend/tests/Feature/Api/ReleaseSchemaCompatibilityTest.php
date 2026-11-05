<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\ReleaseSchemaCompatibility;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ReleaseSchemaCompatibilityTest extends TestCase
{
    private string $root;
    private string $old;
    private string $next;
    private string $current;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/walidsite-schema-'.bin2hex(random_bytes(8));
        $this->old = $this->root.'/vA';
        $this->next = $this->root.'/vB';
        $this->current = $this->root.'/current';
        self::assertTrue(mkdir($this->old.'/application/database/migrations', 0700, true));
        self::assertTrue(mkdir($this->next.'/application/database/migrations', 0700, true));
        self::assertTrue(symlink($this->old, $this->current));
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function migration(string $base, string $name, string $code): void
    {
        file_put_contents($base.'/application/database/migrations/'.$name, $code);
    }

    private function denies(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Unsafe schema change passed the code-only update gate.');
        } catch (DomainException) {
            self::assertSame($this->old, realpath($this->current));
        }
    }

    public function test_identical_migrations_are_compatible_for_code_only_update(): void
    {
        $file = '2026_11_03_000000_create_orders.php';
        $this->migration($this->old, $file, '<?php return "stable";');
        $this->migration($this->next, $file, '<?php return "stable";');
        $state = (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next);
        self::assertSame($state['previous'], $state['candidate']);
        self::assertCount(1, $state['previous']);
    }

    public function test_new_migration_is_rejected_without_schema_rollback(): void
    {
        $this->migration($this->next, '2026_11_03_000001_add_feature.php', '<?php return true;');
        $this->denies(fn () => (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next));
    }

    public function test_removed_migration_is_rejected(): void
    {
        $this->migration($this->old, '2026_11_03_000000_create_orders.php', '<?php return true;');
        $this->denies(fn () => (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next));
    }

    public function test_modified_migration_is_rejected(): void
    {
        $file = '2026_11_03_000000_create_orders.php';
        $this->migration($this->old, $file, '<?php return "old";');
        $this->migration($this->next, $file, '<?php return "new";');
        $this->denies(fn () => (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next));
    }

    public function test_linked_migration_file_and_directory_are_rejected(): void
    {
        $outside = $this->root.'/outside.php';
        file_put_contents($outside, '<?php return true;');
        self::assertTrue(symlink($outside, $this->next.'/application/database/migrations/linked.php'));
        $this->denies(fn () => (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next));
        self::assertTrue(unlink($this->next.'/application/database/migrations/linked.php'));
        self::assertTrue(rmdir($this->next.'/application/database/migrations'));
        self::assertTrue(symlink($this->root, $this->next.'/application/database/migrations'));
        $this->denies(fn () => (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next));
    }

    public function test_non_php_migration_content_is_rejected(): void
    {
        $this->migration($this->next, 'README.txt', 'unapproved changes');
        $this->denies(fn () => (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next));
    }

    public function test_hardlinked_migration_is_not_a_valid_code_only_update(): void
    {
        $filename = '2026_11_05_000000_create_restricted.php';
        $this->migration($this->old, $filename, '<?php return "stable";');
        $this->migration($this->next, $filename, '<?php return "stable";');
        $migration = $this->next.'/application/database/migrations/'.$filename;
        $alias = $this->root.'/outside-migration-alias.php';
        self::assertTrue(link($migration, $alias));
        $this->denies(fn () => (new ReleaseSchemaCompatibility())
            ->assertCompatible($this->current, $this->next));
        self::assertSame('<?php return "stable";', file_get_contents($alias));
    }

    public function test_migration_named_path_swap_is_rejected_even_with_identical_bytes(): void
    {
        $file = $this->next.'/application/database/migrations/2026_11_05_000001_verified.php';
        file_put_contents($file, '<?php return "same bytes";');
        $handle = fopen($file, 'rb');
        self::assertIsResource($handle);
        $baseline = fstat($handle);
        self::assertIsArray($baseline);
        $guard = new \ReflectionMethod(ReleaseSchemaCompatibility::class, 'assertStableMigration');
        try {
            $guard->invoke(new ReleaseSchemaCompatibility(), $handle, $file, $baseline);
            $old = $this->root.'/old-migration-inode.php';
            self::assertTrue(rename($file, $old));
            self::assertSame(strlen('<?php return "same bytes";'),
                file_put_contents($file, '<?php return "same bytes";'));
            self::assertNotSame(fstat($handle)['ino'], lstat($file)['ino']);
            try {
                $guard->invoke(new ReleaseSchemaCompatibility(), $handle, $file, $baseline);
                self::fail('A replaced migration pathname must not pass the signed release gate.');
            } catch (DomainException $error) {
                self::assertStringContainsString('inode changed', $error->getMessage());
            }
        } finally {
            fclose($handle);
        }
    }

    public function test_migration_symlink_swap_and_in_place_mutation_are_rejected(): void
    {
        $file = $this->next.'/application/database/migrations/2026_11_05_000002_verified.php';
        file_put_contents($file, '<?php return true;');
        $handle = fopen($file, 'rb');
        self::assertIsResource($handle);
        $baseline = fstat($handle);
        self::assertIsArray($baseline);
        $guard = new \ReflectionMethod(ReleaseSchemaCompatibility::class, 'assertStableMigration');
        try {
            // A same-inode timestamp change still invalidates the snapshot.
            self::assertTrue(touch($file, $baseline['mtime'] - 120));
            try {
                $guard->invoke(new ReleaseSchemaCompatibility(), $handle, $file, $baseline);
                self::fail('Changed migration metadata accepted.');
            } catch (DomainException $error) {
                self::assertStringContainsString('changed', $error->getMessage());
            }

            // Even an original descriptor still open after symlink replacement
            // cannot authenticate a different path or externally linked file.
            $original = $this->root.'/migration-original.php';
            self::assertTrue(rename($file, $original));
            self::assertTrue(symlink($original, $file));
            try {
                $guard->invoke(new ReleaseSchemaCompatibility(), $handle, $file, $baseline);
                self::fail('A symlink-swapped migration source was accepted.');
            } catch (DomainException $error) {
                self::assertStringContainsString('inode changed', $error->getMessage());
            }
        } finally {
            fclose($handle);
        }
    }

    public function test_missing_and_empty_migration_trees_are_equivalent(): void
    {
        self::assertTrue(rmdir($this->next.'/application/database/migrations'));
        $result = (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next);
        self::assertSame([], $result['previous']);
        self::assertSame([], $result['candidate']);
    }
}

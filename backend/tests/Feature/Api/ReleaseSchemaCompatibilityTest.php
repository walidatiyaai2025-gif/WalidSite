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

    public function test_missing_and_empty_migration_trees_are_equivalent(): void
    {
        self::assertTrue(rmdir($this->next.'/application/database/migrations'));
        $result = (new ReleaseSchemaCompatibility())->assertCompatible($this->current, $this->next);
        self::assertSame([], $result['previous']);
        self::assertSame([], $result['candidate']);
    }
}

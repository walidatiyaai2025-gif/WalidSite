<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\MariaDbRecoverySnapshot;
use DomainException;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MariaDbRecoverySnapshotTest extends TestCase
{
    private PDO $root;
    private PDO $source;
    private PDO $target;
    private string $sourceName;
    private string $targetName;
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            self::markTestSkipped('Disposable MariaDB fixture runs in GitHub CI service.');
        }

        // These are publicly defined, disposable CI service credentials in
        // .github/workflows/ci.yml. Never production credentials.
        $dsn = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
        $this->root = new PDO($dsn, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $suffix = bin2hex(random_bytes(5));
        $this->sourceName = 'walidsite_backup_src_'.$suffix;
        $this->targetName = 'walidsite_restore_dst_'.$suffix;
        $this->root->exec('CREATE DATABASE '.$this->ident($this->sourceName).' CHARACTER SET utf8mb4');
        $this->root->exec('CREATE DATABASE '.$this->ident($this->targetName).' CHARACTER SET utf8mb4');
        $this->source = new PDO($dsn.';dbname='.$this->sourceName, 'root', 'walidsite-root');
        $this->target = new PDO($dsn.';dbname='.$this->targetName, 'root', 'walidsite-root');
        $this->source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->source->exec('CREATE TABLE recovery_parent (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            caption VARCHAR(100) NULL,
            payload VARBINARY(64) NULL
        ) ENGINE=InnoDB');
        $this->source->exec('CREATE TABLE recovery_child (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            parent_id BIGINT UNSIGNED NOT NULL,
            FOREIGN KEY (parent_id) REFERENCES recovery_parent(id)
        ) ENGINE=InnoDB');
        $insert = $this->source->prepare('INSERT INTO recovery_parent (id,caption,payload) VALUES (?,?,?)');
        self::assertTrue($insert->execute([1, "Arabic عربي", "\x00\xff\x20BINARY"]));
        self::assertTrue($insert->execute([2, null, null]));
        $this->source->exec('INSERT INTO recovery_child (id,parent_id) VALUES (7,1)');
        $this->home = sys_get_temp_dir().'/walidsite-mariadb-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->home, 0700));
        self::assertTrue(mkdir($this->home.'/private', 0700));
        self::assertTrue(mkdir($this->home.'/public_html', 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->source)) {
            unset($this->source, $this->target);
        }
        if (isset($this->root)) {
            foreach ([$this->sourceName ?? '', $this->targetName ?? ''] as $database) {
                if ($database !== '') {
                    $this->root->exec('DROP DATABASE IF EXISTS '.$this->ident($database));
                }
            }
        }
        if (isset($this->home) && is_dir($this->home)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function ident(string $name): string
    {
        return chr(96).$name.chr(96);
    }

    private function capture(): array
    {
        return (new MariaDbRecoverySnapshot())->create(
            $this->source, $this->home.'/private', $this->home.'/public_html',
        );
    }

    public function test_actual_innodb_schema_and_binary_rows_restore_to_distinct_empty_database(): void
    {
        $checkpoint = $this->capture();
        self::assertSame(2, $checkpoint['tables']);
        self::assertSame(3, $checkpoint['rows']);

        $result = (new MariaDbRecoverySnapshot())->restoreAndVerify(
            $this->target, $checkpoint['directory'], $checkpoint['manifest_sha256'],
        );
        self::assertSame(['tables' => 2, 'rows' => 3], $result);
        $restored = $this->target->query('SELECT id,caption,payload FROM recovery_parent ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame("Arabic عربي", $restored[0]['caption']);
        self::assertSame("\x00\xff\x20BINARY", $restored[0]['payload']);
        self::assertNull($restored[1]['caption']);
        self::assertSame('1', (string) $this->target->query('SELECT parent_id FROM recovery_child WHERE id=7')->fetchColumn());
        self::assertSame(2, (int) $this->source->query('SELECT COUNT(*) FROM recovery_parent')->fetchColumn());
    }

    public function test_snapshot_manifest_capacity_matches_restore_contract(): void
    {
        $service = new MariaDbRecoverySnapshot();
        $check = new \\ReflectionMethod(MariaDbRecoverySnapshot::class, 'assertManifestCapacity');
        $check->invoke($service, str_repeat('x', 8388608));
        foreach (['', str_repeat('x', 8388609)] as $bad) {
            try {
                $check->invoke($service, $bad);
                self::fail('Snapshot builder accepted a manifest that restore would reject.');
            } catch (DomainException $error) {
                self::assertStringContainsString('manifest exceeds safe size', $error->getMessage());
            }
        }
        // Normal snapshot remains readable by the exact restore service.
        $checkpoint = $this->capture();
        self::assertSame(['tables' => 2, 'rows' => 3],
            (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $this->target, $checkpoint['directory'], $checkpoint['manifest_sha256'],
            ));
    }

    public function test_hardlinked_manifest_is_rejected_without_creating_tables(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('link')) {
            self::markTestSkipped('POSIX hard-link support required.');
        }
        $backup = $this->capture();
        $manifest = $backup['directory'].'/manifest.json';
        $alias = $this->home.'/private/manifest-alias.json';
        if (! @link($manifest, $alias)) {
            self::markTestSkipped('Hard links unavailable on this filesystem.');
        }
        try {
            $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $this->target, $backup['directory'], $backup['manifest_sha256'],
            ));
            self::assertSame([], $this->target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            unlink($alias);
        }
        self::assertSame(['tables' => 2, 'rows' => 3],
            (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $this->target, $backup['directory'], $backup['manifest_sha256'],
            ));
    }

    public function test_manifest_symlink_and_oversize_fail_without_target_ddl(): void
    {
        $backup = $this->capture();
        $manifest = $backup['directory'].'/manifest.json';
        $original = file_get_contents($manifest);
        self::assertIsString($original);
        if (PHP_OS_FAMILY !== 'Windows') {
            $external = $this->home.'/private/manifest-external.json';
            self::assertSame(strlen($original), file_put_contents($external, $original));
            self::assertTrue(unlink($manifest));
            self::assertTrue(symlink($external, $manifest));
            $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $this->target, $backup['directory'], $backup['manifest_sha256'],
            ));
            self::assertTrue(unlink($manifest));
        }
        $large = str_repeat('x', 8388609);
        self::assertSame(strlen($large), file_put_contents($manifest, $large));
        $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
            $this->target, $backup['directory'], $backup['manifest_sha256'],
        ));
        self::assertSame([], $this->target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_hardlinked_restore_row_rejects_before_any_ddl(): void
    {
        $backup = $this->capture();
        $file = $backup['directory'].'/recovery_parent.jsonl';
        $alias = $this->home.'/private/alias.jsonl';
        if (! @link($file, $alias)) {
            self::markTestSkipped('Hardlinks not supported by this host.');
        }
        try {
            $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $this->target, $backup['directory'], $backup['manifest_sha256'],
            ));
            self::assertSame([], $this->target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            unlink($alias);
        }
        self::assertSame(['tables' => 2, 'rows' => 3],
            (new MariaDbRecoverySnapshot())->restoreAndVerify(
                $this->target, $backup['directory'], $backup['manifest_sha256'],
            ));
    }

    public function test_same_bytes_path_replacement_cannot_reuse_preflight_inode(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Requires POSIX rename of opened files.');
        }
        $backup = $this->capture();
        $file = $backup['directory'].'/recovery_child.jsonl';
        $stream = fopen($file, 'rb');
        self::assertIsResource($stream);
        $method = new \ReflectionMethod(MariaDbRecoverySnapshot::class, 'assertStableRowFile');
        $service = new MariaDbRecoverySnapshot();
        try {
            $before = $method->invoke($service, $stream, $file);
            $same = file_get_contents($file);
            $previous = $this->home.'/private/previous-row.jsonl';
            self::assertTrue(rename($file, $previous));
            self::assertSame(strlen($same), file_put_contents($file, $same));
            self::assertNotSame(fstat($stream)['ino'], lstat($file)['ino']);
            $this->reject(fn () => $method->invoke($service, $stream, $file, $before));
        } finally {
            fclose($stream);
        }
        self::assertSame([], $this->target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_mutated_or_symlinked_row_identity_is_rejected(): void
    {
        $backup = $this->capture();
        $file = $backup['directory'].'/recovery_parent.jsonl';
        $stream = fopen($file, 'rb');
        self::assertIsResource($stream);
        $method = new \ReflectionMethod(MariaDbRecoverySnapshot::class, 'assertStableRowFile');
        $service = new MariaDbRecoverySnapshot();
        try {
            $before = $method->invoke($service, $stream, $file);
            self::assertTrue(touch($file, $before['mtime'] - 120));
            $this->reject(fn () => $method->invoke($service, $stream, $file, $before));
            if (PHP_OS_FAMILY !== 'Windows') {
                $previous = $this->home.'/private/previous-parent.jsonl';
                self::assertTrue(rename($file, $previous));
                self::assertTrue(symlink($previous, $file));
                $this->reject(fn () => $method->invoke($service, $stream, $file, $before));
            }
        } finally {
            fclose($stream);
        }
        self::assertSame([], $this->target->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_corrupt_manifest_and_row_bytes_are_rejected_before_restore(): void
    {
        $checkpoint = $this->capture();
        file_put_contents($checkpoint['directory'].'/recovery_parent.jsonl', 'corrupted!');
        $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
            $this->target, $checkpoint['directory'], $checkpoint['manifest_sha256'],
        ));
        self::assertSame(0, (int) $this->target->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn());
    }

    public function test_same_database_and_nonempty_target_fail_before_ddl_execution(): void
    {
        $checkpoint = $this->capture();
        $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
            $this->source, $checkpoint['directory'], $checkpoint['manifest_sha256'],
        ));
        $this->target->exec('CREATE TABLE preexisting (id INT) ENGINE=InnoDB');
        $this->reject(fn () => (new MariaDbRecoverySnapshot())->restoreAndVerify(
            $this->target, $checkpoint['directory'], $checkpoint['manifest_sha256'],
        ));
    }

    public function test_unsupported_view_fails_closed_without_committed_backup(): void
    {
        $this->source->exec('CREATE VIEW recovery_view AS SELECT id FROM recovery_parent');
        $this->reject(fn () => $this->capture());
        self::assertSame([], glob($this->home.'/private/mariadb-*') ?: []);
    }

    public function test_backup_must_not_be_written_under_public_html(): void
    {
        $this->reject(fn () => (new MariaDbRecoverySnapshot())->create(
            $this->source, $this->home.'/public_html', $this->home.'/public_html',
        ));
    }

    private function reject(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Unsafe MariaDB recovery was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

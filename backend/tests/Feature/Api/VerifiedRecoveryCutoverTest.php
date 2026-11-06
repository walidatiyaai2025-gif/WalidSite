<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\VerifiedRecoveryCutover;
use DomainException;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class VerifiedRecoveryCutoverTest extends TestCase
{
    private PDO $rootDb;
    private PDO $liveDb;
    private PDO $recoveryDb;
    private string $liveSchema;
    private string $restoreSchema;
    private string $home;
    private string $releases;
    private string $current;
    private string $publicRoot;
    private string $old;
    private string $candidate;
    private string $runtime;
    private string $uploads;
    private string $vault;
    private string $drill;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            self::markTestSkipped('Real MariaDB integration fixture executes in GitHub Actions.');
        }
        $dsn = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
        $this->rootDb = new PDO($dsn, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $nonce = bin2hex(random_bytes(5));
        $this->liveSchema = 'cutover_live_'.$nonce;
        $this->restoreSchema = 'cutover_restore_'.$nonce;
        $this->rootDb->exec('CREATE DATABASE '.$this->ident($this->liveSchema).' CHARACTER SET utf8mb4');
        $this->rootDb->exec('CREATE DATABASE '.$this->ident($this->restoreSchema).' CHARACTER SET utf8mb4');
        $this->liveDb = new PDO($dsn.';dbname='.$this->liveSchema, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->recoveryDb = new PDO($dsn.';dbname='.$this->restoreSchema, 'root', 'walidsite-root', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->liveDb->exec('CREATE TABLE orders (
            id INT PRIMARY KEY,
            memo VARCHAR(100) NULL,
            attachment VARBINARY(64)
        ) ENGINE=InnoDB');
        $stmt = $this->liveDb->prepare('INSERT INTO orders (id,memo,attachment) VALUES (?,?,?)');
        self::assertTrue($stmt->execute([1, 'محفوظ', "\x00\xff\x20"]));

        $this->home = sys_get_temp_dir().'/walidsite-integrated-'.bin2hex(random_bytes(8));
        $this->releases = $this->home.'/releases';
        $this->current = $this->home.'/current';
        $this->publicRoot = $this->home.'/public_html';
        $this->old = $this->releases.'/vA';
        $this->candidate = $this->releases.'/vB';
        $this->runtime = $this->home.'/runtime';
        $this->uploads = $this->home.'/user-public/uploads';
        $this->vault = $this->home.'/vault';
        $this->drill = $this->home.'/file-restore-drill';

        foreach ([$this->releases, $this->runtime.'/storage', $this->uploads, $this->vault, $this->drill] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }
        $this->makeRelease($this->old, 'vA');
        $this->makeRelease($this->candidate, 'vB');
        self::assertTrue(symlink($this->old, $this->current));
        self::assertTrue(symlink($this->current.'/public_html', $this->publicRoot));
        file_put_contents($this->runtime.'/.env', 'APP_KEY=preserved');
        file_put_contents($this->runtime.'/storage/private.txt', 'unchanged-private');
        file_put_contents($this->uploads.'/user.png', "\x00\xffDATA");
    }

    private function makeRelease(string $root, string $version): void
    {
        foreach (['application/bootstrap', 'application/vendor', 'public_html'] as $directory) {
            self::assertTrue(mkdir($root.'/'.$directory, 0700, true));
        }
        foreach (['application/bootstrap/app.php', 'application/vendor/autoload.php', 'public_html/index.php', 'public_html/.htaccess'] as $file) {
            file_put_contents($root.'/'.$file, $version);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->liveDb)) {
            unset($this->liveDb, $this->recoveryDb);
        }
        if (isset($this->rootDb)) {
            foreach ([$this->liveSchema ?? '', $this->restoreSchema ?? ''] as $name) {
                if ($name !== '') {
                    $this->rootDb->exec('DROP DATABASE IF EXISTS '.$this->ident($name));
                }
            }
        }
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                if ($file->isDir() && ! $file->isLink()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function ident(string $name): string
    {
        return chr(96).$name.chr(96);
    }

    private function execute(?callable $quiesced = null, ?callable $approved = null, ?callable $health = null): array
    {
        return (new VerifiedRecoveryCutover())->execute(
            $this->liveDb,
            $this->recoveryDb,
            $this->runtime.'/.env',
            $this->runtime.'/storage',
            $this->uploads,
            $this->vault,
            $this->drill,
            $this->releases,
            $this->current,
            $this->publicRoot,
            $this->candidate,
            $quiesced ?? static fn (): bool => true,
            $approved ?? static fn (): bool => true,
            $health ?? fn (): bool => file_get_contents($this->publicRoot.'/index.php') === 'vB',
        );
    }

    public function test_complete_files_and_real_mariadb_restore_precede_successful_code_switch(): void
    {
        $result = $this->execute();
        self::assertSame('switched', $result['state']);
        self::assertSame($this->old, $result['previous']);
        self::assertSame($this->candidate, realpath($this->current));
        self::assertSame(3, $result['files']);
        self::assertSame(1, $result['database_tables']);
        self::assertSame(1, $result['database_rows']);
        self::assertSame('vB', file_get_contents($this->publicRoot.'/index.php'));
        self::assertSame('APP_KEY=preserved', file_get_contents($this->runtime.'/.env'));
        self::assertSame("\x00\xffDATA", file_get_contents($this->uploads.'/user.png'));
        self::assertSame('محفوظ', $this->recoveryDb->query('SELECT memo FROM orders WHERE id=1')->fetchColumn());
        self::assertSame("\x00\xff\x20", $this->recoveryDb->query('SELECT attachment FROM orders WHERE id=1')->fetchColumn());
        self::assertCount(1, glob($this->drill.'/restored-*') ?: []);
    }

    public function test_failed_health_restores_old_code_without_changing_live_user_data(): void
    {
        try {
            $this->execute(null, null, static fn (): bool => false);
            self::fail('A failed health check must roll back.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('previous release restored', $failure->getMessage());
        }
        self::assertSame($this->old, realpath($this->current));
        self::assertSame('vA', file_get_contents($this->publicRoot.'/index.php'));
        self::assertSame('APP_KEY=preserved', file_get_contents($this->runtime.'/.env'));
        self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function test_denied_maintenance_or_unverified_candidate_never_creates_backup_or_switches(): void
    {
        $this->assertDenied(static fn (): bool => false, static fn (): bool => true);
        $this->assertDenied(static fn (): bool => true, static fn (): bool => false);
        self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
        self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
        self::assertSame($this->old, realpath($this->current));
    }

    public function test_real_mariadb_restore_failure_prevents_any_code_cutover(): void
    {
        $this->recoveryDb->exec('CREATE TABLE already_present (id INT) ENGINE=InnoDB');
        try {
            $this->execute();
            self::fail('A nonempty restore database cannot authorize cutover.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('distinct empty', $failure->getMessage());
        }
        self::assertSame($this->old, realpath($this->current));
        self::assertSame('vA', file_get_contents($this->publicRoot.'/index.php'));
        self::assertSame('APP_KEY=preserved', file_get_contents($this->runtime.'/.env'));
    }

    public function test_unsafe_private_file_sources_prevent_database_and_code_mutation(): void
    {
        $outside = $this->home.'/outside';
        file_put_contents($outside, 'private');
        self::assertTrue(symlink($outside, $this->runtime.'/storage/unsafe-link'));
        try {
            $this->execute();
            self::fail('Unsafe user data must not be considered backed up.');
        } catch (DomainException) {
            self::assertSame($this->old, realpath($this->current));
        }
        self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
        self::assertSame(0, (int) $this->recoveryDb
            ->query('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
    }

    public function test_group_writable_recovery_vault_blocks_snapshot_and_cutover(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory permission fixture required.');
        }
        self::assertTrue(chmod($this->vault, 0770));
        try {
            $this->execute();
            self::fail('Group-writable private vault cannot protect recovery evidence.');
        } catch (DomainException $error) {
            self::assertStringContainsString('private locations', $error->getMessage());
            self::assertSame($this->old, realpath($this->current));
            self::assertSame('vA', file_get_contents($this->publicRoot.'/index.php'));
            self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
            self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        } finally {
            self::assertTrue(chmod($this->vault, 0700));
        }
    }

    public function test_group_writable_recovery_lock_blocks_all_backup_mutations(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX file permission fixture required.');
        }
        $lockPath = $this->vault.'/.verified-cutover.lock';
        self::assertSame(0, file_put_contents($lockPath, ''));
        self::assertTrue(chmod($lockPath, 0660));
        try {
            $this->execute();
            self::fail('Group-writable recovery lock cannot authorize a backup.');
        } catch (DomainException $error) {
            self::assertStringContainsString('recovery lock identity', $error->getMessage());
            self::assertSame($this->old, realpath($this->current));
            self::assertSame('vA', file_get_contents($this->publicRoot.'/index.php'));
            self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
            self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
        } finally {
            self::assertTrue(chmod($lockPath, 0600));
        }
    }

    public function test_recovery_vault_chmod_during_maintenance_revokes_the_lease(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory permission fixture required.');
        }
        $calls = 0;
        $quiescence = function () use (&$calls): bool {
            $calls++;
            if ($calls === 2 && ! chmod($this->vault, 0777)) {
                throw new RuntimeException('Cannot simulate unsafe recovery vault mode.');
            }
            return true;
        };
        try {
            $this->execute($quiescence);
            self::fail('Backup vault chmod after flock must stop the update.');
        } catch (DomainException $error) {
            self::assertStringContainsString('vault identity or permissions', $error->getMessage());
            self::assertSame(2, $calls);
            self::assertSame($this->old, realpath($this->current));
            self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
            self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
            self::assertSame('APP_KEY=preserved', file_get_contents($this->runtime.'/.env'));
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        } finally {
            self::assertTrue(chmod($this->vault, 0700));
        }
    }

    public function test_replaced_recovery_vault_during_maintenance_cannot_reuse_old_lock(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory rename fixture required.');
        }
        $oldVault = $this->vault.'-displaced';
        $calls = 0;
        $quiescence = function () use (&$calls, $oldVault): bool {
            $calls++;
            if ($calls === 2
                && (! rename($this->vault, $oldVault)
                    || ! mkdir($this->vault, 0700))) {
                throw new RuntimeException('Cannot simulate recovery vault replacement.');
            }
            return true;
        };
        try {
            $this->execute($quiescence);
            self::fail('A replaced backup vault must never inherit the old lock.');
        } catch (DomainException $error) {
            self::assertStringContainsString('vault identity or permissions', $error->getMessage());
            self::assertSame(2, $calls);
            self::assertSame($this->old, realpath($this->current));
            self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
            self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
            self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        } finally {
            if (is_dir($oldVault)) {
                self::assertTrue(rmdir($this->vault));
                self::assertTrue(rename($oldVault, $this->vault));
            }
        }
    }

    public function test_hard_linked_recovery_lock_rejects_backup_and_code_cutover(): void
    {
        $unrelated = $this->home.'/shared-recovery-lock';
        file_put_contents($unrelated, 'do-not-modify');
        self::assertTrue(link($unrelated, $this->vault.'/.verified-cutover.lock'));

        try {
            $this->execute();
            self::fail('A hard-linked recovery lock must fail before backups.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('lock identity', $failure->getMessage());
        }

        self::assertSame('do-not-modify', file_get_contents($unrelated));
        self::assertSame($this->old, realpath($this->current));
        self::assertSame('vA', file_get_contents($this->publicRoot.'/index.php'));
        self::assertSame([], glob($this->vault.'/.mariadb-*') ?: []);
        self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
        self::assertSame(0, (int) $this->recoveryDb
            ->query('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
        self::assertSame(1, (int) $this->liveDb->query('SELECT COUNT(*) FROM orders')->fetchColumn());
    }

    public function test_swapped_recovery_lock_during_maintenance_stops_before_any_backup(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX file rename required.');
        }
        $lockPath = $this->vault.'/.verified-cutover.lock';
        $orphaned = $this->vault.'/.orphaned-verified-cutover.lock';
        $calls = 0;
        $quiescence = static function () use (&$calls, $lockPath, $orphaned): bool {
            $calls++;
            // The first call is the preflight before flock; the second is
            // invoked under the lock immediately before private backups.
            if ($calls === 2) {
                if (! rename($lockPath, $orphaned)
                    || file_put_contents($lockPath, 'replacement') === false) {
                    throw new RuntimeException('Cannot arrange recovery lock swap.');
                }
            }
            return true;
        };

        try {
            $this->execute($quiescence);
            self::fail('Recovery accepted the replaced lock pathname.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock identity', $error->getMessage());
        }
        self::assertSame(2, $calls);
        self::assertSame('replacement', file_get_contents($lockPath));
        self::assertSame([], glob($this->vault.'/mariadb-*') ?: []);
        self::assertSame([], glob($this->vault.'/recovery-*') ?: []);
        self::assertSame($this->old, realpath($this->current));
        self::assertSame('APP_KEY=preserved', file_get_contents($this->runtime.'/.env'));
        self::assertSame(chr(0).chr(255).'DATA', file_get_contents($this->uploads.'/user.png'));
        self::assertSame(0, (int) $this->recoveryDb
            ->query('SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
    }

    private function assertDenied(callable $quiesced, callable $approved): void
    {
        try {
            $this->execute($quiesced, $approved);
            self::fail('Unverified or writable production state must deny cutover.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

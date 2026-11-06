<?php

namespace Tests\Unit;

use App\Domain\Deployment\OwnerUpdateRecoveryBackup;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class OwnerUpdateRecoveryBackupSafetyTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-backup-safety-'.bin2hex(random_bytes(8));
        mkdir($this->home, 0700);
        mkdir($this->home.'/application/storage/app', 0700, true);
        mkdir($this->home.'/public_html/uploads', 0700, true);
        file_put_contents($this->home.'/application/.env', "APP_KEY=base64:PRIVATE_TEST_ONLY\n");
        file_put_contents($this->home.'/application/storage/app/file.txt', 'private user data');
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function args(): array
    {
        $app = $this->home.'/application';
        return [
            new PDO('sqlite::memory:'), new PDO('sqlite::memory:'),
            $app.'/.env',
            $app.'/storage/app',
            $this->home.'/public_html/uploads',
            $app,
            $this->home.'/public_html',
            $this->home.'/.walidsite-backups',
            $this->home.'/.walidsite-restore-drills',
            '0.1.1',
        ];
    }

    public function test_downgrade_and_unverified_maintenance_are_rejected_before_any_backup(): void
    {
        $args = $this->args();
        $args[9] = 'invalid-version';
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn () => true]);
            $this->fail('Invalid installed version accepted.');
        } catch (DomainException) {
            $this->assertDirectoryDoesNotExist($args[7]);
        }

        $args[9] = '0.1.1';
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn () => false]);
            $this->fail('Active writes accepted as a recovery proof.');
        } catch (DomainException) {
            $this->assertDirectoryDoesNotExist($args[7]);
            $this->assertSame('private user data', file_get_contents($args[3].'/file.txt'));
        }
    }

    public function test_hard_linked_owner_backup_lock_denies_snapshot_before_any_data_copy(): void
    {
        if (! function_exists('link')) {
            self::markTestSkipped('Hard links unavailable on this host.');
        }
        $args = $this->args();
        self::assertTrue(mkdir($args[7], 0700));
        $external = $this->home.'/unrelated-lock-data';
        file_put_contents($external, 'preserve owner data');
        if (! @link($external, $args[7].'/.owner-update-backup.lock')) {
            self::markTestSkipped('Cannot create hard-link fixture.');
        }

        $maintenanceCalls = 0;
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[
                ...$args, static function () use (&$maintenanceCalls): bool {
                    ++$maintenanceCalls;
                    return true;
                },
            ]);
            self::fail('A hard-linked owner backup lock must be rejected.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock identity', $error->getMessage());
        }

        self::assertSame(1, $maintenanceCalls);
        self::assertSame('preserve owner data', file_get_contents($external));
        self::assertSame('private user data', file_get_contents($args[3].'/file.txt'));
        self::assertSame([], glob($args[7].'/receipt-*.json'));
        self::assertSame([], glob($args[7].'/recovery-*'));
    }

    public function test_owner_backup_lock_path_swapped_during_maintenance_is_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('cPanel POSIX lock pathname swap is unavailable on Windows.');
        }
        $args = $this->args();
        $lockPath = $args[7].'/.owner-update-backup.lock';
        $orphan = $args[7].'/.old-backup-lock';
        $calls = 0;
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[
                ...$args, static function () use (&$calls, $lockPath, $orphan): bool {
                    ++$calls;
                    if ($calls === 2) {
                        if (! rename($lockPath, $orphan)
                            || file_put_contents($lockPath, 'replacement') === false) {
                            throw new \RuntimeException('Could not simulate lock pathname replacement.');
                        }
                    }
                    return true;
                },
            ]);
            self::fail('A replaced owner backup lock must be rejected.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock identity', $error->getMessage());
        }

        self::assertSame(2, $calls);
        self::assertSame('replacement', file_get_contents($lockPath));
        self::assertSame('private user data', file_get_contents($args[3].'/file.txt'));
        self::assertSame([], glob($args[7].'/receipt-*.json'));
        self::assertSame([], glob($args[7].'/recovery-*'));
    }

    public function test_public_symlink_or_exposed_backup_vault_is_rejected(): void
    {
        $args = $this->args();
        $link = $this->home.'/linked-public';
        if (! function_exists('symlink') || ! @symlink($args[6], $link)) {
            $this->markTestSkipped('Symlinks not available.');
        }
        $args[6] = $link;

        $this->expectException(DomainException::class);
        (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn () => true]);
    }

    public function test_attacker_cannot_place_recovery_root_in_public_web_files(): void
    {
        $args = $this->args();
        $args[7] = $this->home.'/public_html/.walidsite-backups';
        $this->expectException(DomainException::class);
        (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn () => true]);
    }

    public function test_preexisting_world_writable_vault_is_rejected_without_silent_chmod(): void
    {
        $args = $this->args();
        self::assertTrue(mkdir($args[7], 0700));
        self::assertTrue(chmod($args[7], 0777));
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn (): bool => true]);
            self::fail('An exposed backup vault must never be silently chmodded into a trusted location.');
        } catch (DomainException $error) {
            self::assertStringContainsString('directory identity', $error->getMessage());
        }
        self::assertSame(0777, fileperms($args[7]) & 0777);
        self::assertSame([], glob($args[7].'/receipt-*.json') ?: []);
        self::assertSame('private user data', file_get_contents($args[3].'/file.txt'));
    }

    public function test_preexisting_group_writable_backup_lock_is_rejected_unchanged(): void
    {
        $args = $this->args();
        self::assertTrue(mkdir($args[7], 0700));
        $lock = $args[7].'/.owner-update-backup.lock';
        file_put_contents($lock, 'PRESERVE_UNTRUSTED_LOCK');
        self::assertTrue(chmod($lock, 0660));
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn (): bool => true]);
            self::fail('A writable preexisting lock must not authorize owner recovery.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock identity', $error->getMessage());
        }
        self::assertSame('PRESERVE_UNTRUSTED_LOCK', file_get_contents($lock));
        self::assertSame(0660, fileperms($lock) & 0777);
        self::assertSame([], glob($args[7].'/receipt-*.json') ?: []);
        self::assertSame([], glob($args[7].'/recovery-*') ?: []);
    }

    public function test_backup_vault_chmod_after_acquiring_lock_aborts_before_copy(): void
    {
        $args = $this->args();
        $calls = 0;
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[
                ...$args,
                static function () use (&$calls, $args): bool {
                    if (++$calls === 2) {
                        chmod($args[7], 0777);
                    }
                    return true;
                },
            ]);
            self::fail('A vault made world writable during quiescence cannot issue a backup receipt.');
        } catch (DomainException $error) {
            self::assertStringContainsString('directory identity', $error->getMessage());
        } finally {
            if (is_dir($args[7])) {
                chmod($args[7], 0700);
            }
        }
        self::assertSame(2, $calls);
        self::assertSame([], glob($args[7].'/receipt-*.json') ?: []);
        self::assertSame([], glob($args[7].'/recovery-*') ?: []);
        self::assertSame('private user data', file_get_contents($args[3].'/file.txt'));
    }

    public function test_restore_drill_root_swap_during_quiescence_aborts_before_copy(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('cPanel POSIX directory rename is required.');
        }
        $args = $this->args();
        $orphan = $this->home.'/.walidsite-renamed-restore-root';
        $calls = 0;
        try {
            (new OwnerUpdateRecoveryBackup())->createAndProve(...[
                ...$args,
                static function () use (&$calls, $args, $orphan): bool {
                    if (++$calls === 2) {
                        if (! rename($args[8], $orphan) || ! mkdir($args[8], 0700)) {
                            throw new \RuntimeException('Could not simulate owner recovery root swap.');
                        }
                    }
                    return true;
                },
            ]);
            self::fail('A replaced restore root cannot authorize a recovery proof.');
        } catch (DomainException $error) {
            self::assertStringContainsString('directory', $error->getMessage());
        }
        self::assertSame(2, $calls);
        self::assertSame([], glob($args[7].'/receipt-*.json') ?: []);
        self::assertSame([], glob($args[7].'/recovery-*') ?: []);
        self::assertSame('private user data', file_get_contents($args[3].'/file.txt'));
    }

    public function test_recovery_receipt_is_complete_private_and_existing_receipt_cannot_be_replaced(): void
    {
        $path = $this->home.'/recovery-receipt.json';
        $receipt = [
            'schema' => 'walidsite.backup-restore-receipt.v1',
            'id' => str_repeat('a', 32),
            'code_update_performed' => false,
        ];

        $method = new \\ReflectionMethod(OwnerUpdateRecoveryBackup::class, 'saveReceipt');
        $method->invoke(new OwnerUpdateRecoveryBackup(), $path, $receipt);

        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(
            json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            file_get_contents($path),
        );
        self::assertSame(1, stat($path)['nlink']);

        try {
            $method->invoke(new OwnerUpdateRecoveryBackup(), $path, ['id' => 'REPLACEMENT']);
            self::fail('An existing verified receipt must never be overwritten.');
        } catch (\\RuntimeException $error) {
            self::assertStringContainsString('Cannot create private recovery receipt', $error->getMessage());
        }
        self::assertSame(
            json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            file_get_contents($path),
        );
    }

    public function test_private_sources_must_be_outside_document_root(): void
    {
        $args = $this->args();
        $args[3] = $this->home.'/public_html/uploads';
        $this->expectException(DomainException::class);
        (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn () => true]);
    }
}

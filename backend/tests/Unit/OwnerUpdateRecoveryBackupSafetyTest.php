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

    public function test_private_sources_must_be_outside_document_root(): void
    {
        $args = $this->args();
        $args[3] = $this->home.'/public_html/uploads';
        $this->expectException(DomainException::class);
        (new OwnerUpdateRecoveryBackup())->createAndProve(...[...$args, fn () => true]);
    }
}

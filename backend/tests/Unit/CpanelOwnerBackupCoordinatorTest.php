<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelOwnerBackupCoordinator;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class CpanelOwnerBackupCoordinatorTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-owner-coordinator-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->home.'/application/storage/app', 0700, true));
        self::assertTrue(mkdir($this->home.'/public_html/uploads', 0700, true));
        file_put_contents($this->home.'/application/.env', 'KEEP_PRIVATE_CONFIGURATION');
        file_put_contents($this->home.'/application/storage/app/user.txt', 'KEEP_PRIVATE_USER_DATA');
        file_put_contents($this->home.'/public_html/uploads/user.jpg', 'KEEP_PUBLIC_UPLOAD');
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $tree = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($tree as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function attempt(callable $hostWritersStopped, ?CpanelConsoleWriterLease $ownerLease = null): array
    {
        $app = $this->home.'/application';
        return (new CpanelOwnerBackupCoordinator())->prove(
            new PDO('sqlite::memory:'),
            new PDO('sqlite::memory:'),
            $app.'/.env',
            $app.'/storage/app',
            $this->home.'/public_html/uploads',
            $app,
            $this->home.'/public_html',
            $this->home.'/.walidsite-backups',
            $this->home.'/.walidsite-restore-drills',
            '0.1.2', 'legacy', $hostWritersStopped, $ownerLease,
        );
    }

    public function test_unverified_foreign_writer_freeze_denies_backup_without_lease_or_mutation(): void
    {
        try {
            $this->attempt(static fn (): bool => false);
            self::fail('Unverified host-level isolation must never authorize a backup.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Independent host writer isolation', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        $this->assertOwnerDataPreserved();
    }

    public function test_active_http_or_cli_writer_prevents_owner_backup_before_any_snapshot(): void
    {
        $http = new CpanelConsoleWriterLease();
        $http->beginForConsole($this->home.'/application');
        try {
            try {
                $this->attempt(static fn (): bool => true);
                self::fail('Backup must not run concurrently with an HTTP or CLI shared writer.');
            } catch (DomainException $error) {
                self::assertStringContainsString('Writer drain is busy', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
            $this->assertOwnerDataPreserved();
        } finally {
            $http->end();
        }
    }

    public function test_host_freeze_revoked_after_exclusive_lock_does_not_issue_a_barrier(): void
    {
        $calls = 0;
        try {
            $this->attempt(static function () use (&$calls): bool {
                return ++$calls === 1;
            });
            self::fail('A revoked host isolation claim must abort.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Host writer isolation changed', $error->getMessage());
        }
        self::assertSame(2, $calls);
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        $this->assertOwnerDataPreserved();
    }

    public function test_failed_disposable_database_restore_clears_unchanged_code_barrier(): void
    {
        // SQLite is deliberately NOT an acceptable MariaDB backup source.
        // The file snapshot may run, but no success receipt may be issued.
        $failed = false;
        try {
            $this->attempt(static fn (): bool => true);
        } catch (Throwable) {
            $failed = true;
        }
        self::assertTrue($failed, 'An unsupported database must not issue a recovery success receipt.');
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertSame([], glob($this->home.'/.walidsite-backups/receipt-*.json') ?: []);
        $this->assertOwnerDataPreserved();

        // After safely unwinding a backup-only failure, a later honest
        // reader may acquire a shared lock; no stale maintenance remains.
        $reader = new CpanelConsoleWriterLease();
        $reader->beginForConsole($this->home.'/application');
        $reader->end();
    }

    public function test_owner_http_backup_handoff_fails_closed_if_other_request_is_still_active(): void
    {
        $owner = new CpanelConsoleWriterLease();
        $peer = new CpanelConsoleWriterLease();
        $owner->beginForConsole($this->home.'/application');
        $peer->beginForConsole($this->home.'/application');

        try {
            try {
                $this->attempt(static fn (): bool => true, $owner);
                self::fail('A competing HTTP reader was bypassed by privileged owner recovery.');
            } catch (DomainException $error) {
                self::assertStringContainsString('Active writers', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
            $this->assertOwnerDataPreserved();
        } finally {
            $owner->end();
            $peer->end();
        }

        $next = new CpanelConsoleWriterLease();
        $next->beginExclusiveForUpdate($this->home);
        $next->end();
    }

    public function test_owner_http_handoff_clears_barrier_even_when_disposable_restore_is_rejected(): void
    {
        $owner = new CpanelConsoleWriterLease();
        $owner->beginForConsole($this->home.'/application');
        try {
            $this->attempt(static fn (): bool => true, $owner);
            self::fail('SQLite must not be accepted as a MariaDB recovery receipt.');
        } catch (Throwable) {
            // Failure still proves the handoff went through private backup
            // recovery and returned to a safe, unmodified installed site.
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertSame([], glob($this->home.'/.walidsite-backups/receipt-*.json') ?: []);
            $this->assertOwnerDataPreserved();
        } finally {
            $owner->end(); // Idempotent after coordinator finally.
        }

        $reader = new CpanelConsoleWriterLease();
        $reader->beginForConsole($this->home.'/application');
        $reader->end();
    }

    private function assertOwnerDataPreserved(): void
    {
        self::assertSame('KEEP_PRIVATE_CONFIGURATION', file_get_contents($this->home.'/application/.env'));
        self::assertSame('KEEP_PRIVATE_USER_DATA', file_get_contents($this->home.'/application/storage/app/user.txt'));
        self::assertSame('KEEP_PUBLIC_UPLOAD', file_get_contents($this->home.'/public_html/uploads/user.jpg'));
    }
}

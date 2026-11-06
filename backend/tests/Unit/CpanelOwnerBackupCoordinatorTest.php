<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelOwnerBackupCoordinator;
use App\Domain\Deployment\TrustedInstalledReleasePin;
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
        file_put_contents($this->home.'/application/VERSION', "0.1.2\n");
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

    public function test_mismatched_or_missing_physical_version_rejects_false_backup_receipt(): void
    {
        foreach (['0.1.3', 'invalid-version'] as $forged) {
            file_put_contents($this->home.'/application/VERSION', $forged);
            try {
                $this->attempt(static fn (): bool => true);
                self::fail('A caller-provided version cannot forge the installed code.');
            } catch (DomainException $error) {
                self::assertStringContainsString('Installed code identity', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        }
        file_put_contents($this->home.'/application/VERSION', "0.1.2\n");

        // An active chained-release pointer cannot be reported as a legacy
        // physical backup without attesting the *running* code lineage.
        $pointer = $this->home.'/.walidsite-active-release.json';
        file_put_contents($pointer, '{"schema":1,"version":"0.1.3","root":"unverified"}');
        try {
            $this->attempt(static fn (): bool => true);
            self::fail('An active code pointer cannot be ignored in recovery.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Installed code identity', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        $this->assertOwnerDataPreserved();
    }

    public function test_original_install_cannot_claim_a_versioned_previous_release_root(): void
    {
        $app = $this->home.'/application';
        foreach ([
            '.walidsite-releases/v0.1.2-'.str_repeat('a', 40),
            'untrusted',
        ] as $forgedRoot) {
            try {
                (new CpanelOwnerBackupCoordinator())->prove(
                    new PDO('sqlite::memory:'),
                    new PDO('sqlite::memory:'),
                    $app.'/.env',
                    $app.'/storage/app',
                    $this->home.'/public_html/uploads',
                    $app,
                    $this->home.'/public_html',
                    $this->home.'/.walidsite-backups',
                    $this->home.'/.walidsite-restore-drills',
                    '0.1.2', $forgedRoot, static fn (): bool => true,
                );
                self::fail('First-install recovery accepted a forged previous code root.');
            } catch (DomainException $error) {
                self::assertStringContainsString('Installed code identity', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
            $this->assertOwnerDataPreserved();
        }
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

    public function test_backup_now_rejects_untrusted_disposable_pdo_before_maintenance_or_snapshot(): void
    {
        // Code install already validates the actual PDOs. The independent
        // Backup Now entrypoint must not create a maintenance marker or
        // recovery vault before rejecting identical/unsupported databases.
        $app = $this->home.'/application';
        $source = new PDO('sqlite::memory:');
        foreach ([
            ['target' => $source, 'message' => 'Production PDO cannot be used for recovery.'],
            ['target' => new PDO('sqlite::memory:'),
                'message' => 'MariaDB connections are required for recovery.'],
        ] as $scenario) {
            $hostChecks = 0;
            try {
                (new CpanelOwnerBackupCoordinator())->prove(
                    $source, $scenario['target'],
                    $app.'/.env',
                    $app.'/storage/app',
                    $this->home.'/public_html/uploads',
                    $app,
                    $this->home.'/public_html',
                    $this->home.'/.walidsite-backups',
                    $this->home.'/.walidsite-restore-drills',
                    '0.1.2', 'legacy',
                    static function () use (&$hostChecks): bool {
                        ++$hostChecks;
                        return true;
                    },
                );
                self::fail('Backup Now accepted an unsafe restore connection.');
            } catch (DomainException $error) {
                self::assertStringContainsString($scenario['message'], $error->getMessage());
            }
            self::assertSame(2, $hostChecks, 'PDO identity check must follow exclusive writer isolation.');
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-restore-drills');
            $this->assertOwnerDataPreserved();
        }
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

    /** @return array{string,string} */
    private function switchedRunningRelease(): array
    {
        $root = '.walidsite-releases/v0.1.3-'.str_repeat('a', 40);
        self::assertTrue(mkdir($this->home.'/'.$root.'/application', 0700, true));
        file_put_contents($this->home.'/'.$root.'/application/VERSION', "0.1.3\n");
        $pointer = $this->home.'/.walidsite-active-release.json';
        file_put_contents($pointer, json_encode([
            'schema' => 1, 'version' => '0.1.3', 'root' => $root,
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($pointer, 0600));

        return [$this->home.'/'.$root.'/application', $root];
    }

    private function attemptAfterSwitch(
        string $running, string $previousRoot, callable $hostStopped,
    ): array {
        $app = $this->home.'/application';

        return (new CpanelOwnerBackupCoordinator())->prove(
            new PDO('sqlite::memory:'), new PDO('sqlite::memory:'),
            $app.'/.env', $app.'/storage/app',
            $this->home.'/public_html/uploads', $app,
            $this->home.'/public_html',
            $this->home.'/.walidsite-backups',
            $this->home.'/.walidsite-restore-drills',
            '0.1.3', $previousRoot, $hostStopped, null, $running,
        );
    }

    public function test_chained_backup_attests_running_release_before_disposable_mariadb_guard(): void
    {
        [$running, $previousRoot] = $this->switchedRunningRelease();
        self::assertSame('0.1.3', (new TrustedInstalledReleasePin())
            ->read($running, $this->home.'/public_html', '0.1.2'));
        $hostChecks = 0;
        try {
            $this->attemptAfterSwitch(
                $running, $previousRoot,
                static function () use (&$hostChecks): bool {
                    ++$hostChecks;
                    return true;
                },
            );
            self::fail('SQLite cannot produce a real MariaDB backup receipt.');
        } catch (DomainException $error) {
            self::assertStringContainsString('MariaDB connections are required', $error->getMessage());
        }
        self::assertSame(2, $hostChecks, 'Exclusive lock precedes disposable database verification.');
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-restore-drills');
        $this->assertOwnerDataPreserved();
    }

    public function test_chained_backup_rejects_forged_running_root_and_legacy_root_before_lock(): void
    {
        [$running, $previousRoot] = $this->switchedRunningRelease();
        foreach ([
            [$this->home.'/application', $previousRoot],
            [$running, 'legacy'],
            [$running, '.walidsite-releases/v0.1.3-'.str_repeat('b', 40)],
        ] as [$claimedRunning, $claimedPrevious]) {
            try {
                $this->attemptAfterSwitch($claimedRunning, $claimedPrevious, static fn (): bool => true);
                self::fail('Forged previous release lineage must be rejected.');
            } catch (DomainException $error) {
                self::assertStringContainsString('Installed code identity', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
            $this->assertOwnerDataPreserved();
        }
    }

    public function test_chained_backup_fails_closed_when_pointer_changes_during_writer_lock_handoff(): void
    {
        [$running, $previousRoot] = $this->switchedRunningRelease();
        $hostChecks = 0;
        try {
            $this->attemptAfterSwitch(
                $running, $previousRoot,
                function () use (&$hostChecks): bool {
                    if (++$hostChecks === 2) {
                        $pointer = $this->home.'/.walidsite-active-release.json';
                        file_put_contents($pointer, '{"schema":1,"version":"0.1.3","root":"forged"}');
                    }
                    return true;
                },
            );
            self::fail('Swapped active pointer must not yield a recovery receipt.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lineage changed', $error->getMessage());
        }
        self::assertSame(2, $hostChecks);
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        $this->assertOwnerDataPreserved();
    }

    private function assertOwnerDataPreserved(): void
    {
        self::assertSame('KEEP_PRIVATE_CONFIGURATION', file_get_contents($this->home.'/application/.env'));
        self::assertSame('KEEP_PRIVATE_USER_DATA', file_get_contents($this->home.'/application/storage/app/user.txt'));
        self::assertSame('KEEP_PUBLIC_UPLOAD', file_get_contents($this->home.'/public_html/uploads/user.jpg'));
    }
}

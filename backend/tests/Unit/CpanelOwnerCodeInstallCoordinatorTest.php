<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelOwnerCodeInstallCoordinator;
use App\Domain\Deployment\CpanelOwnerCodeUpdateHost;
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;

final class CpanelOwnerCodeInstallCoordinatorTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-code-install-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->home.'/application', 0700, true));
        self::assertTrue(mkdir($this->home.'/public_html', 0700, true));
        file_put_contents($this->home.'/application/VERSION', "0.1.2\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $children = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($children as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function host(bool $stopped): CpanelOwnerCodeUpdateHost
    {
        return new class($stopped) implements CpanelOwnerCodeUpdateHost {
            public int $probes = 0;
            public function __construct(private readonly bool $stopped) {}

            public function externalWritersStopped(): bool
            {
                return $this->stopped;
            }

            public function disposableRestoreConnection(): PDO
            {
                throw new \RuntimeException('Untrusted host must not be asked for DB during early rejection.');
            }

            public function probeNewRelease(
                string $maintenanceToken, string $version, string $sourceSha,
            ): bool {
                ++$this->probes;
                return false;
            }
        };
    }

    private function attempt(
        CpanelOwnerCodeUpdateHost $host, string $version = '0.1.2',
        ?string $runningApplication = null,
    ): array {
        return (new CpanelOwnerCodeInstallCoordinator())->install(
            $host, new CpanelConsoleWriterLease(),
            new PDO('sqlite::memory:'), new PDO('sqlite::memory:'),
            '{"untrusted":true}', 'no-trust-key', 'https://updates.example.test',
            $version, $this->home, $this->home, $this->home.'/missing.zip',
            $runningApplication,
        );
    }

    public function test_unverified_host_writers_block_before_any_backup_or_pointer(): void
    {
        $host = $this->host(false);
        try {
            $this->attempt($host);
            self::fail('False foreign-writer isolation permitted code installation.');
        } catch (DomainException $e) {
            self::assertStringContainsString('writer isolation', $e->getMessage());
        }
        self::assertSame(0, $host->probes);
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
    }

    public function test_wrong_installed_code_identity_rejects_even_before_host_check(): void
    {
        $host = $this->host(true);
        try {
            $this->attempt($host, '9.9.9');
            self::fail('Forged installed code VERSION authorized a new release.');
        } catch (DomainException $e) {
            self::assertStringContainsString('running release identity', $e->getMessage());
        }
        self::assertSame(0, $host->probes);
        self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
        self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
    }

    /** @return array{string,string} */
    private function activatedReleaseFixture(): array
    {
        $root = '.walidsite-releases/v0.1.3-'.str_repeat('c', 40);
        self::assertTrue(mkdir($this->home.'/'.$root.'/application', 0700, true));
        file_put_contents($this->home.'/'.$root.'/application/VERSION', "0.1.3\n");
        $pointer = $this->home.'/.walidsite-active-release.json';
        file_put_contents($pointer, json_encode([
            'schema' => 1, 'version' => '0.1.3', 'root' => $root,
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($pointer, 0600));
        return [$this->home.'/'.$root.'/application', $root];
    }

    public function test_verified_active_code_release_passes_lineage_gate_before_disposable_db_rejection(): void
    {
        [$running, $root] = $this->activatedReleaseFixture();
        $host = $this->host(true);
        try {
            $this->attempt($host, '0.1.3', $running);
            self::fail('SQLite cannot authorize a chained signed release.');
        } catch (DomainException $error) {
            self::assertStringContainsString('MariaDB connections are required', $error->getMessage());
        }
        self::assertSame(0, $host->probes);
        self::assertFileExists($this->home.'/.walidsite-active-release.json');
        self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        self::assertSame($root, json_decode(
            (string) file_get_contents($this->home.'/.walidsite-active-release.json'),
            true, 8, JSON_THROW_ON_ERROR,
        )['root']);
    }

    public function test_chained_signed_install_refuses_untrusted_running_root_and_pointer_before_host(): void
    {
        [$running, $root] = $this->activatedReleaseFixture();
        foreach ([$this->home.'/application', $this->home.'/'.$root] as $fakeRunning) {
            try {
                $this->attempt($this->host(true), '0.1.3', $fakeRunning);
                self::fail('Forged running root must never authorize code installation.');
            } catch (DomainException $error) {
                self::assertStringContainsString('running release identity', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        }

        // A forged active pointer must be rejected before any host access
        // or private backup; the original first-installed VERSION stays fixed.
        file_put_contents(
            $this->home.'/.walidsite-active-release.json',
            '{"schema":1,"version":"0.1.3","root":"forged"}',
        );
        try {
            $this->attempt($this->host(true), '0.1.3', $running);
            self::fail('Forged active pointer must never authorize signed code install.');
        } catch (DomainException $error) {
            self::assertStringContainsString('running release identity', $error->getMessage());
        }
        self::assertSame("0.1.2\n", file_get_contents($this->home.'/application/VERSION'));
        self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
    }

    public function test_unsigned_envelope_never_creates_maintenance_or_backups(): void
    {
        $host = $this->host(true);
        $this->expectException(\Throwable::class);
        try {
            $this->attempt($host);
        } finally {
            self::assertSame(0, $host->probes);
            self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            self::assertDirectoryDoesNotExist($this->home.'/.walidsite-backups');
        }
    }
}

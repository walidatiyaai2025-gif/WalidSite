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
        @unlink($this->home.'/application/VERSION');
        @rmdir($this->home.'/application');
        @rmdir($this->home.'/public_html');
        @rmdir($this->home);
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

    private function attempt(CpanelOwnerCodeUpdateHost $host, string $version = '0.1.2'): array
    {
        return (new CpanelOwnerCodeInstallCoordinator())->install(
            $host, new CpanelConsoleWriterLease(),
            new PDO('sqlite::memory:'), new PDO('sqlite::memory:'),
            '{"untrusted":true}', 'no-trust-key', 'https://updates.example.test',
            $version, $this->home, $this->home, $this->home.'/missing.zip',
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
            self::assertStringContainsString('original physical cPanel install', $e->getMessage());
        }
        self::assertSame(0, $host->probes);
        self::assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
        self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
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

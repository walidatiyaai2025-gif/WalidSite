<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedUpdateHostPreflight;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TrustedUpdateHostPreflightTest extends TestCase
{
    private string $home;
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-host-preflight-'.bin2hex(random_bytes(8));
        $this->paths = [
            'quarantine' => $this->home.'/quarantine',
            'incoming' => $this->home.'/quarantine/incoming',
            'releases' => $this->home.'/releases',
            'current' => $this->home.'/current',
            'public' => $this->home.'/public_html',
            'environment' => $this->home.'/runtime/.env',
            'private_data' => $this->home.'/runtime/storage',
            'uploads' => $this->home.'/user-public/uploads',
            'backup_vault' => $this->home.'/backup-vault',
            'disposable_files' => $this->home.'/disposable-files',
        ];
        foreach ([
            $this->paths['incoming'], $this->paths['releases'].'/v1/public_html',
            $this->paths['private_data'], $this->paths['uploads'],
            $this->paths['backup_vault'], $this->paths['disposable_files'],
        ] as $path) {
            self::assertTrue(mkdir($path, 0700, true));
        }
        file_put_contents($this->paths['releases'].'/v1/public_html/index.php', 'live');
        file_put_contents($this->paths['releases'].'/v1/public_html/.htaccess', 'live');
        file_put_contents($this->paths['environment'], 'APP_KEY=protected');
        self::assertTrue(symlink($this->paths['releases'].'/v1', $this->paths['current']));
        self::assertTrue(symlink($this->paths['current'].'/public_html', $this->paths['public']));
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function preflight(?\Closure $space = null): void
    {
        (new TrustedUpdateHostPreflight($space))->assertReady($this->paths, 65536);
    }

    private function denied(string $expected, ?\Closure $disk = null): void
    {
        try {
            $this->preflight($disk);
            self::fail('Unsafe hosting environment was accepted.');
        } catch (DomainException $error) {
            self::assertStringContainsString($expected, $error->getMessage());
            self::assertSame('live', file_get_contents($this->paths['public'].'/index.php'));
            self::assertSame('APP_KEY=protected', file_get_contents($this->paths['environment']));
            self::assertSame($this->paths['releases'].'/v1', realpath($this->paths['current']));
        }
    }

    public function test_approved_private_atomic_host_passes_read_only_preflight(): void
    {
        $this->preflight(static fn (string $path): int => 8589934592);
        self::assertSame('live', file_get_contents($this->paths['public'].'/index.php'));
    }

    public function test_wrong_document_root_symlink_is_blocked(): void
    {
        self::assertTrue(unlink($this->paths['public']));
        self::assertTrue(symlink($this->paths['releases'].'/v1/public_html', $this->paths['public']));
        try {
            $this->preflight();
            self::fail('A non-atomic cPanel document root cannot update.');
        } catch (DomainException $e) {
            self::assertStringContainsString('symlink layout', $e->getMessage());
            self::assertSame('live', file_get_contents($this->paths['public'].'/index.php'));
        }
    }

    public function test_public_backup_vault_is_blocked(): void
    {
        $this->paths['backup_vault'] = $this->paths['public'].'/cache';
        self::assertTrue(mkdir($this->paths['backup_vault'], 0700));
        $this->denied('backup_vault');
    }

    public function test_linked_private_quarantine_is_blocked(): void
    {
        self::assertTrue(rename($this->paths['quarantine'], $this->home.'/quarantine-real'));
        self::assertTrue(symlink($this->home.'/quarantine-real', $this->paths['quarantine']));
        $this->denied('quarantine');
    }

    public function test_no_recovery_space_blocks_before_any_mutation(): void
    {
        $this->denied('insufficient available disk', static fn (string $path): int => 1024);
    }

    public function test_disk_probe_failure_blocks_host(): void
    {
        $this->denied('insufficient available disk', static fn (string $path): false => false);
    }

    public function test_missing_private_environment_blocks_host(): void
    {
        self::assertTrue(unlink($this->paths['environment']));
        try {
            $this->preflight();
            self::fail('Missing APP_KEY environment cannot update.');
        } catch (DomainException $e) {
            self::assertStringContainsString('private installed environment', $e->getMessage());
            self::assertSame('live', file_get_contents($this->paths['public'].'/index.php'));
        }
    }
}

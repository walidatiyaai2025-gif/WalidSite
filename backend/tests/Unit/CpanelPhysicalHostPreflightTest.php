<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelPhysicalHostPreflight;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CpanelPhysicalHostPreflightTest extends TestCase
{
    private string $home;
    private string $trustedFront;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['zip', 'sodium', 'pdo_mysql', 'curl', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                self::markTestSkipped('Physical updater requires PHP '.$extension);
            }
        }
        $this->home = sys_get_temp_dir().'/walidsite-physical-host-'.bin2hex(random_bytes(8));
        $this->trustedFront = dirname(__DIR__, 2).'/resources/deployment/cpanel-stable-index.php';
        foreach ([
            $this->home.'/application/storage/app',
            $this->home.'/public_html/uploads',
        ] as $dir) {
            self::assertTrue(mkdir($dir, 0700, true));
        }
        copy($this->trustedFront, $this->home.'/public_html/index.php');
        file_put_contents($this->home.'/public_html/.htaccess', 'RewriteEngine On');
        file_put_contents($this->home.'/application/.env', 'DO_NOT_READ_OR_REPORT_APP_KEY');
        file_put_contents($this->home.'/application/VERSION', "0.1.2\n");
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

    private function inspect(?CpanelPhysicalHostPreflight $preflight = null): array
    {
        return ($preflight ?? new CpanelPhysicalHostPreflight(
            static fn (string $path): int => 1024 * 1024 * 1024,
        ))->assertReady($this->home, $this->trustedFront, '0.1.2', 4096);
    }

    public function test_valid_physical_home_passes_without_backup_code_or_file_mutation(): void
    {
        $before = scandir($this->home);
        $result = $this->inspect();
        self::assertSame('hosting_layout_verified', $result['state']);
        self::assertSame('0.1.2', $result['installed_version']);
        self::assertFalse($result['backup_verified']);
        self::assertFalse($result['code_update_performed']);
        self::assertSame($before, scandir($this->home));
        self::assertSame('DO_NOT_READ_OR_REPORT_APP_KEY', file_get_contents($this->home.'/application/.env'));
    }

    public function test_foreign_writable_cpanel_home_is_rejected_before_owner_staging(): void
    {
        // The same account-home modes are unsafe for the later maintenance
        // barrier. Read-only hosting preflight must not advertise READY.
        foreach ([0777, 0770, 0702] as $mode) {
            self::assertTrue(chmod($this->home, $mode));
            clearstatcache(true, $this->home);
            try {
                $this->inspect();
                self::fail('Foreign-writable cPanel home passed owner update preflight.');
            } catch (DomainException) {
                self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
            } finally {
                chmod($this->home, 0700);
                clearstatcache(true, $this->home);
            }
        }

        self::assertSame('hosting_layout_verified', $this->inspect()['state']);
        self::assertSame('DO_NOT_READ_OR_REPORT_APP_KEY', file_get_contents($this->home.'/application/.env'));
    }

    public function test_existing_exposed_private_release_root_blocks_preflight_without_mutation(): void
    {
        $root = $this->home.'/.walidsite-releases';
        self::assertTrue(mkdir($root, 0700));
        foreach ([0755, 0750, 0777] as $mode) {
            self::assertTrue(chmod($root, $mode));
            clearstatcache(true, $root);
            try {
                $this->inspect();
                self::fail('A publicly accessible code release root must never pass hosting preflight.');
            } catch (DomainException) {
                self::assertSame($mode, fileperms($root) & 0777);
                self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
                self::assertSame('DO_NOT_READ_OR_REPORT_APP_KEY', file_get_contents($this->home.'/application/.env'));
            }
        }
        self::assertTrue(chmod($root, 0700));
        clearstatcache(true, $root);
        self::assertSame('hosting_layout_verified', $this->inspect()['state']);
    }

    public function test_existing_cli_writer_lock_must_be_private_unlinked_and_owner_controlled(): void
    {
        $lock = $this->home.'/.walidsite-writer-drain.lock';
        file_put_contents($lock, '');
        self::assertTrue(chmod($lock, 0600));
        self::assertSame('hosting_layout_verified', $this->inspect()['state']);

        foreach ([0644, 0660] as $mode) {
            self::assertTrue(chmod($lock, $mode));
            clearstatcache(true, $lock);
            try {
                $this->inspect();
                self::fail('An exposed existing writer lock passed physical host preflight.');
            } catch (DomainException) {
                self::assertSame($mode, fileperms($lock) & 0777);
            }
        }
        self::assertTrue(chmod($lock, 0600));

        $alias = $this->home.'/writer-lock-alias';
        if (@link($lock, $alias)) {
            try {
                $this->expectPreflightRejection();
                self::assertFileExists($alias);
            } finally {
                unlink($alias);
                clearstatcache(true, $lock);
            }
        }
        self::assertSame('hosting_layout_verified', $this->inspect()['state']);

        $target = $this->home.'/original-writer-lock';
        self::assertTrue(rename($lock, $target));
        if (@symlink($target, $lock)) {
            $this->expectPreflightRejection();
            self::assertSame('', file_get_contents($target));
        }
    }

    public function test_after_code_pointer_switch_uses_versioned_code_not_original_version(): void
    {
        $sha = str_repeat('a', 40);
        $release = $this->home.'/.walidsite-releases/v0.1.2-'.$sha;
        self::assertTrue(mkdir($release.'/application', 0700, true));
        file_put_contents($release.'/application/VERSION', "0.1.2\n");
        file_put_contents($this->home.'/application/VERSION', "0.1.1\n");
        $pointer = $this->home.'/.walidsite-active-release.json';
        file_put_contents($pointer, json_encode([
            'schema' => 1, 'version' => '0.1.2',
            'root' => '.walidsite-releases/v0.1.2-'.$sha,
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($pointer, 0600));
        self::assertSame('hosting_layout_verified', $this->inspect()['state']);
    }

    /** Legitimate private pointer and code VERSION must both be stable read-only identities. */
    private function validReleasePointer(): string
    {
        $root = '.walidsite-releases/v0.1.2-'.str_repeat('a', 40);
        self::assertTrue(mkdir($this->home.'/'.$root.'/application', 0700, true));
        file_put_contents($this->home.'/'.$root.'/application/VERSION', "0.1.2\n");
        $pointer = $this->home.'/.walidsite-active-release.json';
        file_put_contents($pointer, json_encode([
            'schema' => 1, 'version' => '0.1.2', 'root' => $root,
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($pointer, 0600));

        return $pointer;
    }

    public function test_exposed_or_hardlinked_private_pointer_fails_host_preflight(): void
    {
        $pointer = $this->validReleasePointer();
        self::assertTrue(chmod($pointer, 0644));
        try {
            $this->inspect();
            self::fail('An exposed code-switch pointer must never pass host preflight.');
        } catch (DomainException) {
            self::assertFileExists($pointer);
        }

        self::assertTrue(chmod($pointer, 0600));
        $hardlink = $this->home.'/unsafe-pointer-hardlink';
        if (! @link($pointer, $hardlink)) {
            self::markTestSkipped('Host does not support hard links.');
        }
        try {
            $this->inspect();
            self::fail('A hardlinked code-switch pointer must never pass host preflight.');
        } catch (DomainException) {
            self::assertFileExists($pointer);
        } finally {
            unlink($hardlink);
            clearstatcache(true, $pointer);
        }
        self::assertSame('hosting_layout_verified', $this->inspect()['state']);
    }

    public function test_hardlinked_or_foreign_writable_code_version_fails_preflight(): void
    {
        $version = $this->home.'/application/VERSION';
        self::assertTrue(chmod($version, 0666));
        try {
            $this->inspect();
            self::fail('Group/world writable code identity must fail closed.');
        } catch (DomainException) {
            self::assertFileExists($version);
        }
        self::assertTrue(chmod($version, 0644));
        $hardlink = $this->home.'/version-hardlink';
        if (! @link($version, $hardlink)) {
            self::markTestSkipped('Host does not support hard links.');
        }
        try {
            $this->inspect();
            self::fail('A hardlinked installed VERSION must fail closed.');
        } catch (DomainException) {
            self::assertFileExists($version);
        } finally {
            unlink($hardlink);
            clearstatcache(true, $version);
        }
        self::assertSame('hosting_layout_verified', $this->inspect()['state']);
    }

    public function test_wrong_or_missing_immutable_pointer_code_fails_closed(): void
    {
        file_put_contents($this->home.'/.walidsite-active-release.json', json_encode([
            'schema' => 1, 'version' => '0.1.2',
            'root' => '.walidsite-releases/v0.1.2-'.str_repeat('a', 40),
        ], JSON_THROW_ON_ERROR));
        $this->expectException(DomainException::class);
        $this->inspect();
    }

    public function test_tampered_or_replaced_public_entrypoint_is_rejected(): void
    {
        file_put_contents($this->home.'/public_html/index.php', '<?php echo "wrong";');
        $this->expectException(DomainException::class);
        $this->inspect();
    }

    public function test_hardlinked_or_writable_stable_front_controller_fails_closed(): void
    {
        $live = $this->home.'/public_html/index.php';
        $originalMode = fileperms($live) & 0777;
        try {
            self::assertTrue(chmod($live, 0666));
            $this->expectPreflightRejection();
        } finally {
            chmod($live, $originalMode);
            clearstatcache(true, $live);
        }

        $alias = $this->home.'/linked-stable-controller';
        if (! @link($live, $alias)) {
            self::markTestSkipped('Hard links are unsupported on this filesystem.');
        }
        try {
            $this->expectPreflightRejection();
        } finally {
            unlink($alias);
            clearstatcache(true, $live);
        }

        self::assertSame('hosting_layout_verified', $this->inspect()['state']);
    }

    private function expectPreflightRejection(): void
    {
        try {
            $this->inspect();
            self::fail('Untrusted stable front controller passed preflight.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    public function test_missing_private_env_and_runtime_storage_are_not_approved(): void
    {
        unlink($this->home.'/application/.env');
        try {
            $this->inspect();
            $this->fail('Missing .env accepted');
        } catch (DomainException) {
            self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }
        file_put_contents($this->home.'/application/.env', 'PRIVATE');
        rmdir($this->home.'/public_html/uploads');
        $this->expectException(DomainException::class);
        $this->inspect();
    }

    public function test_maintenance_or_expired_recovery_barrier_blocks_new_preflight(): void
    {
        file_put_contents($this->home.'/.walidsite-update-maintenance.json', '{"expired":true}');
        $this->expectException(DomainException::class);
        $this->inspect();
    }

    public function test_insufficient_disk_space_fails_before_backup_or_activation(): void
    {
        $preflight = new CpanelPhysicalHostPreflight(static fn (string $path): int => 1000);
        $this->expectException(DomainException::class);
        $this->inspect($preflight);
    }

    public function test_untrusted_symlinked_public_root_is_forbidden(): void
    {
        if (! function_exists('symlink')) {
            self::markTestSkipped('Symlink tests unsupported.');
        }
        $web = $this->home.'/public_html';
        $other = $this->home.'/web-original';
        self::assertTrue(rename($web, $other));
        if (! @symlink($other, $web)) {
            self::markTestSkipped('Symlinks unavailable.');
        }
        $this->expectException(DomainException::class);
        $this->inspect();
    }

    public function test_identity_mismatch_and_unsigned_size_cannot_pass(): void
    {
        $service = new CpanelPhysicalHostPreflight(static fn (): int => 1024 * 1024 * 1024);
        foreach (['0.1.1', '9.9.9', 'not-a-version'] as $version) {
            try {
                $service->assertReady($this->home, $this->trustedFront, $version, 4096);
                self::fail('Wrong installed release accepted.');
            } catch (DomainException) {
                self::assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            }
        }
        $this->expectException(DomainException::class);
        $service->assertReady($this->home, $this->trustedFront, '0.1.2', 0);
    }
}

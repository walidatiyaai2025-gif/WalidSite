<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelCronReleaseSelector;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CpanelCronReleaseSelectorTest extends TestCase
{
    private string $home;
    private string $original;
    private string $public;
    private string $pointer;
    private CpanelConsoleWriterLease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-cron-lineage-'.bin2hex(random_bytes(8));
        $this->original = $this->home.'/application';
        $this->public = $this->home.'/public_html';
        $this->pointer = $this->home.'/.walidsite-active-release.json';
        self::assertTrue(mkdir($this->original.'/storage/app', 0700, true));
        self::assertTrue(mkdir($this->original.'/bootstrap', 0700, true));
        self::assertTrue(mkdir($this->original.'/vendor', 0700, true));
        self::assertTrue(mkdir($this->public, 0700));
        file_put_contents($this->original.'/.env', "APP_KEY=private-keep\n");
        file_put_contents($this->original.'/storage/app/site-installed.lock', 'installed');
        file_put_contents($this->original.'/storage/app/owner-private-upload', 'KEEP_UPLOAD');
        file_put_contents($this->original.'/VERSION', "0.1.2\n");
        file_put_contents($this->original.'/bootstrap/app.php', "<?php // first install\n");
        file_put_contents($this->original.'/vendor/autoload.php', "<?php // first install\n");
        $this->lease = new CpanelConsoleWriterLease();
        $this->lease->beginForConsole($this->original);
    }

    protected function tearDown(): void
    {
        if (isset($this->lease)) {
            $this->lease->end();
        }
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function select(): string
    {
        return (new CpanelCronReleaseSelector())->select(
            $this->original, $this->public, $this->lease,
        );
    }

    private function activate(string $version, string $sha): string
    {
        $directory = $this->home.'/.walidsite-releases/v'.$version.'-'.$sha;
        self::assertTrue(mkdir($directory.'/application/bootstrap', 0700, true));
        self::assertTrue(mkdir($directory.'/application/vendor', 0700, true));
        file_put_contents($directory.'/application/VERSION', $version."\n");
        file_put_contents($directory.'/application/bootstrap/app.php', "<?php // $version\n");
        file_put_contents($directory.'/application/vendor/autoload.php', "<?php // $version\n");
        $this->writePointer([
            'schema' => 1,
            'version' => $version,
            'root' => '.walidsite-releases/v'.$version.'-'.$sha,
        ]);
        return $directory.'/application';
    }

    private function writePointer(array $pointer): void
    {
        file_put_contents($this->pointer, json_encode($pointer, JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($this->pointer, 0600));
    }

    public function test_first_install_then_two_chained_signed_code_pointers_select_actual_bootable_runtime(): void
    {
        self::assertSame($this->original, $this->select());
        $firstUpdate = $this->activate('0.1.3', str_repeat('a', 40));
        self::assertSame($firstUpdate, $this->select());
        $secondUpdate = $this->activate('0.1.4', str_repeat('b', 40));
        self::assertSame($secondUpdate, $this->select());

        // Even after two code upgrades the original first-install data root
        // is not rewritten or migrated, and the selector has no update API.
        self::assertSame("APP_KEY=private-keep\n", file_get_contents($this->original.'/.env'));
        self::assertSame('installed', file_get_contents($this->original.'/storage/app/site-installed.lock'));
        self::assertSame('KEEP_UPLOAD', file_get_contents($this->original.'/storage/app/owner-private-upload'));
        self::assertSame("0.1.2\n", file_get_contents($this->original.'/VERSION'));
        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
    }

    public function test_expired_or_new_maintenance_marker_refuses_cron_even_with_existing_active_release(): void
    {
        $this->activate('0.1.3', str_repeat('a', 40));
        $marker = $this->home.'/.walidsite-update-maintenance.json';
        file_put_contents($marker, '{"expires_at":0}');
        try {
            $this->expectException(DomainException::class);
            $this->select();
        } finally {
            unlink($marker);
        }
    }

    public function test_wrong_pin_stale_pointer_or_wrong_version_never_falls_back_to_original_code(): void
    {
        $candidate = $this->activate('0.1.3', str_repeat('a', 40));
        file_put_contents($candidate.'/VERSION', "0.1.2\n");
        try {
            $this->expectException(DomainException::class);
            $this->select();
        } finally {
            file_put_contents($candidate.'/VERSION', "0.1.3\n");
        }
    }

    public function test_invalid_pointer_schema_traversal_and_downgrade_are_refused(): void
    {
        $target = $this->activate('0.1.3', str_repeat('a', 40));
        self::assertSame($target, $this->select());
        foreach ([
            ['schema' => 2, 'version' => '0.1.3', 'root' => '.walidsite-releases/v0.1.3-'.str_repeat('a', 40)],
            ['schema' => 1, 'version' => '0.1.3', 'root' => '../../public_html'],
            ['schema' => 1, 'version' => '0.1.2', 'root' => '.walidsite-releases/v0.1.2-'.str_repeat('a', 40)],
            ['schema' => 1, 'version' => '0.1.4', 'root' => '.walidsite-releases/v0.1.3-'.str_repeat('a', 40)],
        ] as $row) {
            $this->writePointer($row);
            try {
                $this->select();
                self::fail('Unverified release pointer allowed Cron boot.');
            } catch (DomainException) {
                self::assertSame("0.1.2\n", file_get_contents($this->original.'/VERSION'));
            }
        }
    }

    public function test_symlink_hardlink_and_unprotected_pointer_cannot_select_executable_code(): void
    {
        $this->activate('0.1.3', str_repeat('a', 40));
        $raw = file_get_contents($this->pointer);
        chmod($this->pointer, 0666);
        try {
            $this->select();
            self::fail('Writable pointer accepted');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        chmod($this->pointer, 0600);
        $hardlink = $this->home.'/second-private-pointer';
        self::assertTrue(link($this->pointer, $hardlink));
        try {
            $this->select();
            self::fail('Hardlinked pointer accepted');
        } catch (DomainException) {
            self::assertTrue(true);
        } finally {
            unlink($hardlink);
        }
        unlink($this->pointer);
        $target = $this->home.'/pointer-target.json';
        file_put_contents($target, $raw);
        self::assertTrue(symlink($target, $this->pointer));
        $this->expectException(DomainException::class);
        $this->select();
    }

    public function test_nonbootable_or_symlinked_active_code_is_refused(): void
    {
        $target = $this->activate('0.1.3', str_repeat('a', 40));
        unlink($target.'/vendor/autoload.php');
        self::assertTrue(symlink($this->original.'/vendor/autoload.php', $target.'/vendor/autoload.php'));
        $this->expectException(DomainException::class);
        $this->select();
    }

    public function test_shared_writer_lease_blocks_another_exclusive_update_and_is_required_to_select(): void
    {
        $this->activate('0.1.3', str_repeat('a', 40));
        $other = new CpanelConsoleWriterLease();
        try {
            $other->beginExclusiveForUpdate($this->home);
            self::fail('Exclusive code switch acquired while Cron holds shared lease.');
        } catch (DomainException) {
            self::assertSame($this->home.'/.walidsite-releases/v0.1.3-'.str_repeat('a', 40).'/application', $this->select());
        } finally {
            $other->end();
        }
        $this->lease->end();
        $this->expectException(DomainException::class);
        $this->select();
    }
}

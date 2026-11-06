<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CpanelConsoleWriterLeaseTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-drain-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->home, 0700));
        $this->assertTrue(mkdir($this->home.'/application', 0700));
        $this->assertTrue(mkdir($this->home.'/public_html', 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $this->discard($this->home);
        }
        parent::tearDown();
    }

    private function discard(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $item = $path.'/'.$name;
            if (is_dir($item) && ! is_link($item)) {
                $this->discard($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($path);
    }

    public function test_console_writer_is_shared_and_prevents_exclusive_update_until_it_finishes(): void
    {
        $writer = new CpanelConsoleWriterLease();
        $writer->beginForConsole($this->home.'/application');
        $lock = $this->home.'/.walidsite-writer-drain.lock';
        $this->assertFileExists($lock);
        $this->assertSame(0600, fileperms($lock) & 0777);
        $this->assertSame(1, stat($lock)['nlink']);

        $update = new CpanelConsoleWriterLease();
        try {
            $update->beginExclusiveForUpdate($this->home);
            $this->fail('Exclusive update ran while Laravel console writer was active.');
        } catch (DomainException) {
            $this->assertFileExists($lock);
        }

        $writer->end();
        $update->beginExclusiveForUpdate($this->home);
        $update->assertExclusiveHeld();
        $update->end();
        $this->expectException(DomainException::class);
        $update->assertExclusiveHeld();
    }

    public function test_nested_console_commands_cannot_release_an_outer_writer_lease(): void
    {
        $writer = new CpanelConsoleWriterLease();
        $writer->beginForConsole($this->home.'/application');
        $writer->beginForConsole($this->home.'/application');
        $writer->end();

        $update = new CpanelConsoleWriterLease();
        try {
            $update->beginExclusiveForUpdate($this->home);
            $this->fail('Nested command completion released the outer console lease.');
        } catch (DomainException) {
            // Expected while the outer command remains alive.
        }
        $writer->end();
        $update->beginExclusiveForUpdate($this->home);
        $update->end();
    }

    public function test_exclusive_update_blocks_new_console_writers_until_released(): void
    {
        $update = new CpanelConsoleWriterLease();
        $update->beginExclusiveForUpdate($this->home);
        $writer = new CpanelConsoleWriterLease();
        try {
            $writer->beginForConsole($this->home.'/application');
            $this->fail('A new scheduler passed while an owner update held the writer drain.');
        } catch (DomainException) {
            // No partial CLI lease is retained.
        }
        $update->end();
        $writer->beginForConsole($this->home.'/application');
        $writer->end();
    }

    public function test_expired_or_unreadable_maintenance_marker_never_allows_a_writer(): void
    {
        $marker = $this->home.'/.walidsite-update-maintenance.json';
        file_put_contents($marker, '{"expires_at":0}');
        $writer = new CpanelConsoleWriterLease();
        try {
            $writer->beginForConsole($this->home.'/application');
            $this->fail('An expired maintenance marker reopened a scheduled writer.');
        } catch (DomainException) {
            $this->assertFileExists($marker);
        }
        unlink($marker);
        if (@symlink('/dev/null', $marker)) {
            try {
                $writer->beginForConsole($this->home.'/application');
                $this->fail('A symlinked private maintenance marker reopened a writer.');
            } catch (DomainException) {
                $this->assertTrue(is_link($marker));
            }
        }
    }

    public function test_lock_mode_and_hardlink_alias_must_never_be_silently_repaired(): void
    {
        $lease = new CpanelConsoleWriterLease();
        $lease->beginForConsole($this->home.'/application');
        $lease->end();
        $lock = $this->home.'/.walidsite-writer-drain.lock';

        $this->assertTrue(chmod($lock, 0666));
        clearstatcache(true, $lock);
        try {
            (new CpanelConsoleWriterLease())->beginForConsole($this->home.'/application');
            $this->fail('A group-writable existing lock was accepted.');
        } catch (DomainException) {
            $this->assertSame(0666, fileperms($lock) & 0777);
        }

        chmod($lock, 0600);
        $alias = $this->home.'/.linked-writer-lock';
        if (@link($lock, $alias)) {
            try {
                (new CpanelConsoleWriterLease())->beginForConsole($this->home.'/application');
                $this->fail('Multi-linked writer lock was accepted.');
            } catch (DomainException) {
                $this->assertFileExists($alias);
            } finally {
                unlink($alias);
            }
        }
        (new CpanelConsoleWriterLease())->beginForConsole($this->home.'/application');
    }

    public function test_unsafe_home_permissions_fail_closed_without_creating_writable_lock(): void
    {
        foreach ([0777, 0770, 0702] as $mode) {
            chmod($this->home, $mode);
            clearstatcache(true, $this->home);
            try {
                (new CpanelConsoleWriterLease())->beginForConsole($this->home.'/application');
                $this->fail('Foreign-writable account home was accepted for background writers.');
            } catch (DomainException) {
                $this->assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
            } finally {
                chmod($this->home, 0700);
                clearstatcache(true, $this->home);
            }
        }
    }

    public function test_versioned_cpanel_artisan_is_bound_to_original_account_home(): void
    {
        $release = $this->home.'/.walidsite-releases/v0.1.2-'.str_repeat('a', 40);
        $this->assertTrue(mkdir($release.'/application', 0700, true));
        $writer = new CpanelConsoleWriterLease();
        $writer->beginForConsole($release.'/application');
        $this->assertFileExists($this->home.'/.walidsite-writer-drain.lock');
        try {
            (new CpanelConsoleWriterLease())->beginExclusiveForUpdate($this->home);
            $this->fail('A versioned release worker bypassed original-home writer drain.');
        } catch (DomainException) {
            $writer->end();
        }
    }

    public function test_local_checkout_does_not_create_or_require_cpanel_writer_lock(): void
    {
        $checkout = $this->home.'/checkout';
        $this->assertTrue(mkdir($checkout, 0700));
        $writer = new CpanelConsoleWriterLease();
        $writer->beginForConsole($checkout);
        $writer->end();
        $this->assertFileDoesNotExist($this->home.'/.walidsite-writer-drain.lock');
    }
}

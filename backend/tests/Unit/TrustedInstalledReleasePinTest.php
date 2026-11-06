<?php

namespace Tests\Unit;

use App\Domain\Deployment\TrustedInstalledReleasePin;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TrustedInstalledReleasePinTest extends TestCase
{
    private string $home;
    private string $first;
    private string $public;
    private string $release;
    private string $active;
    private string $pointer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-pinned-lineage-'.bin2hex(random_bytes(8));
        $this->first = $this->home.'/application';
        $this->public = $this->home.'/public_html';
        $this->release = $this->home.'/.walidsite-releases/v0.1.3-'.str_repeat('a', 40);
        $this->active = $this->release.'/application';
        $this->pointer = $this->home.'/.walidsite-active-release.json';
        self::assertTrue(mkdir($this->first, 0700, true));
        self::assertTrue(mkdir($this->public, 0700));
        file_put_contents($this->first.'/VERSION', "0.1.2\n");
    }

    protected function tearDown(): void
    {
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

    private function read(string $runtime, string $pin = '0.1.2'): ?string
    {
        return (new TrustedInstalledReleasePin())->read($runtime, $this->public, $pin);
    }

    private function switched(): void
    {
        self::assertTrue(mkdir($this->active, 0700, true));
        file_put_contents($this->active.'/VERSION', "0.1.3\n");
        $this->writePointer([
            'schema' => 1,
            'version' => '0.1.3',
            'root' => '.walidsite-releases/v0.1.3-'.str_repeat('a', 40),
        ]);
    }

    private function writePointer(array $value): void
    {
        file_put_contents($this->pointer, json_encode($value, JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($this->pointer, 0600));
    }

    public function test_unversioned_install_remains_strictly_pinned_to_installed_version(): void
    {
        self::assertSame('0.1.2', $this->read($this->first));
        self::assertNull($this->read($this->first, '0.1.1'));
        self::assertNull($this->read($this->first, ''));
        self::assertNull($this->read($this->first, '9.9.9'));

        file_put_contents($this->first.'/VERSION', "0.1.3\n");
        self::assertNull($this->read($this->first));
    }

    public function test_second_update_uses_running_version_when_legacy_pin_matches_private_lineage(): void
    {
        $this->switched();
        self::assertSame('0.1.3', $this->read($this->active));
        self::assertSame('0.1.2', trim(file_get_contents($this->first.'/VERSION')));
        self::assertNull($this->read($this->first));
        self::assertNull($this->read($this->active, '0.1.3'));
        self::assertNull($this->read($this->active, '0.1.1'));
    }

    public function test_stale_replaced_version_and_invalid_private_pointer_are_fail_closed(): void
    {
        $this->switched();
        file_put_contents($this->active.'/VERSION', "0.1.4\n");
        self::assertNull($this->read($this->active));
        file_put_contents($this->active.'/VERSION', "0.1.3\n");

        foreach ([
            ['schema'=>1, 'version'=>'0.1.4', 'root'=>'.walidsite-releases/v0.1.3-'.str_repeat('a', 40)],
            ['schema'=>1, 'version'=>'0.1.3', 'root'=>'../../malicious'],
            ['schema'=>1, 'version'=>'0.1.3', 'root'=>'.walidsite-releases/v0.1.3-'.str_repeat('b', 40)],
            ['schema'=>2, 'version'=>'0.1.3', 'root'=>'.walidsite-releases/v0.1.3-'.str_repeat('a', 40)],
        ] as $invalid) {
            $this->writePointer($invalid);
            self::assertNull($this->read($this->active));
        }
    }

    public function test_linked_hardlinked_world_writable_or_missing_pointer_cannot_authorize_chained_update(): void
    {
        $this->switched();
        $bytes = file_get_contents($this->pointer);
        self::assertTrue(chmod($this->pointer, 0666));
        self::assertNull($this->read($this->active));
        self::assertTrue(chmod($this->pointer, 0600));
        if (function_exists('link')) {
            $alias = $this->home.'/alias-active-pointer';
            if (@link($this->pointer, $alias)) {
                self::assertNull($this->read($this->active));
                unlink($alias);
            }
        }
        if (function_exists('symlink')) {
            $safe = $this->home.'/pointer-bytes';
            file_put_contents($safe, $bytes);
            unlink($this->pointer);
            self::assertTrue(symlink($safe, $this->pointer));
            self::assertNull($this->read($this->active));
            unlink($this->pointer);
        } else {
            unlink($this->pointer);
        }
        self::assertNull($this->read($this->active));
    }

    public function test_active_release_root_and_maintenance_are_attested_independently_of_version_file(): void
    {
        $this->switched();
        $marker = $this->home.'/.walidsite-update-maintenance.json';
        file_put_contents($marker, '{}');
        self::assertNull($this->read($this->active));
        unlink($marker);

        self::assertTrue(chmod($this->release, 0777));
        self::assertNull($this->read($this->active));
        self::assertTrue(chmod($this->release, 0700));

        file_put_contents($this->first.'/VERSION', "0.1.4\n");
        self::assertNull($this->read($this->active));
        file_put_contents($this->first.'/VERSION', "0.1.2\n");

        self::assertSame('0.1.3', $this->read($this->active));
    }
}

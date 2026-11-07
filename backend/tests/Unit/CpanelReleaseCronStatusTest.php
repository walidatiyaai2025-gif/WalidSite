<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelReleaseCronStatus;
use PHPUnit\Framework\TestCase;

final class CpanelReleaseCronStatusTest extends TestCase
{
    private string $root;
    private string $directory;
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/walidsite-cron-view-'.bin2hex(random_bytes(8));
        $this->public = $this->root.'/public_html';
        $this->directory = $this->root.'/application/storage/app/private/release-automation';
        mkdir($this->public, 0700, true);
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() && ! $item->isLink()
                    ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function write(array $changes = []): string
    {
        $record = array_replace([
            'schema' => 1,
            'state' => 'BLOCKED',
            'checked_at' => time(),
            'installed_version' => '1.2.3',
            'available_version' => '1.2.4',
            'source_sha' => str_repeat('a', 40),
            'reason' => 'VERIFIED_HOST_UPDATE_GATE_REQUIRED',
            'code_applied' => false,
        ], $changes);
        $path = $this->directory.'/release-poll-status.json';
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR)."\n");
        chmod($path, 0600);
        return $path;
    }

    public function test_safe_recent_receipt_is_observational_only(): void
    {
        $this->write();
        $visible = (new CpanelReleaseCronStatus())->inspect($this->directory, $this->public);
        self::assertSame('BLOCKED', $visible['state']);
        self::assertSame('1.2.4', $visible['available_version']);
        self::assertSame('VERIFIED_HOST_UPDATE_GATE_REQUIRED', $visible['reason']);
        self::assertArrayNotHasKey('source_sha', $visible);
        self::assertArrayNotHasKey('code_applied', $visible);
    }

    public function test_missing_stale_corrupt_or_claiming_deployed_is_unverified(): void
    {
        $reader = new CpanelReleaseCronStatus();
        self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
        foreach ([
            ['checked_at' => time() - 3601],
            ['checked_at' => time() + 40],
            ['state' => 'DEPLOYED'],
            ['code_applied' => true],
            ['reason' => 'ANYTHING'],
            ['source_sha' => 'bad'],
            ['available_version' => '01.2.3'],
            ['schema' => 2],
        ] as $changes) {
            $this->write($changes);
            self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
        }
    }

    public function test_deployed_requires_consistent_code_applied_version_and_reason(): void
    {
        $reader = new CpanelReleaseCronStatus();
        $good = [
            'state' => 'DEPLOYED',
            'installed_version' => '1.2.4',
            'available_version' => '1.2.4',
            'reason' => 'VERIFIED_HOST_CODE_APPLIED',
            'code_applied' => true,
        ];
        $this->write($good);
        self::assertSame('DEPLOYED', $reader->inspect($this->directory, $this->public)['state']);
        foreach ([
            ['state' => 'BLOCKED'],
            ['code_applied' => false],
            ['reason' => 'NO_NEWER_SIGNED_VERSION'],
            ['installed_version' => '1.2.3'],
            ['source_sha' => null],
        ] as $tamper) {
            $this->write(array_replace($good, $tamper));
            self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
        }
    }

    public function test_symlink_hardlink_and_relaxed_permissions_do_not_display_status(): void
    {
        $reader = new CpanelReleaseCronStatus();
        $path = $this->write();
        chmod($path, 0644);
        self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
        chmod($path, 0600);
        $alias = $this->root.'/application/alias';
        link($path, $alias);
        self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
        unlink($alias);
        unlink($path);
        symlink($this->public.'/fake', $path);
        self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
    }

    public function test_public_directory_cannot_be_a_private_receipt_root(): void
    {
        $reader = new CpanelReleaseCronStatus();
        $this->write();
        self::assertSame('UNVERIFIED', $reader->inspect($this->public, $this->public)['state']);
        chmod($this->directory, 0777);
        self::assertSame('UNVERIFIED', $reader->inspect($this->directory, $this->public)['state']);
    }
}

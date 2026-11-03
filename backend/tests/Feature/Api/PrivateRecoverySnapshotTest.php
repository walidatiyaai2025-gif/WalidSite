<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\PrivateRecoverySnapshot;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PrivateRecoverySnapshotTest extends TestCase
{
    private string $home;
    private string $environment;
    private string $privateData;
    private string $uploads;
    private string $backupRoot;
    private string $drillRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-recovery-'.bin2hex(random_bytes(8));
        $this->privateData = $this->home.'/application/storage/app';
        $this->uploads = $this->home.'/public_html/uploads';
        $this->backupRoot = $this->home.'/recovery-vault';
        $this->drillRoot = $this->home.'/disposable-restore';
        $this->environment = $this->home.'/application/.env';
        foreach ([$this->privateData, $this->uploads, $this->backupRoot, $this->drillRoot] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }
        file_put_contents($this->environment, "APP_KEY=base64:secret-do-not-log\n");
        self::assertTrue(mkdir($this->privateData.'/user', 0700));
        file_put_contents($this->privateData.'/user/document.txt', 'private data');
        file_put_contents($this->uploads.'/avatar.png', "\0binary\xff");
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function snapshot(): array
    {
        return (new PrivateRecoverySnapshot())->create(
            $this->environment, $this->privateData, $this->uploads, $this->backupRoot,
        );
    }

    public function test_actual_bytes_can_be_backed_up_and_restored_into_isolated_directory(): void
    {
        $snapshot = $this->snapshot();
        self::assertSame(3, $snapshot['files']);
        self::assertGreaterThan(0, $snapshot['bytes']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['manifest_sha256']);
        self::assertSame("APP_KEY=base64:secret-do-not-log\n", file_get_contents($this->environment));
        $drill = (new PrivateRecoverySnapshot())->drill(
            $snapshot['directory'], $snapshot['manifest_sha256'], $this->drillRoot,
        );
        self::assertSame($snapshot['files'], $drill['files']);
        self::assertSame($snapshot['bytes'], $drill['bytes']);
        $restored = glob($this->drillRoot.'/restored-*');
        self::assertCount(1, $restored);
        self::assertSame(file_get_contents($this->environment), file_get_contents($restored[0].'/environment/.env'));
        self::assertSame('private data', file_get_contents($restored[0].'/private-data/user/document.txt'));
        self::assertSame("\0binary\xff", file_get_contents($restored[0].'/uploads/avatar.png'));
        self::assertSame('private data', file_get_contents($this->privateData.'/user/document.txt'));
    }

    public function test_recovery_tampering_is_detected_before_restoration(): void
    {
        $snapshot = $this->snapshot();
        file_put_contents($snapshot['directory'].'/uploads/avatar.png', 'altered');
        $this->assertRejected(fn () => (new PrivateRecoverySnapshot())->drill(
            $snapshot['directory'], $snapshot['manifest_sha256'], $this->drillRoot,
        ));
        self::assertSame([], glob($this->drillRoot.'/restored-*') ?: []);
    }

    public function test_inventory_tampering_is_detected(): void
    {
        $snapshot = $this->snapshot();
        file_put_contents($snapshot['directory'].'/manifest.json', '{}');
        $this->assertRejected(fn () => (new PrivateRecoverySnapshot())->drill(
            $snapshot['directory'], $snapshot['manifest_sha256'], $this->drillRoot,
        ));
    }

    public function test_linked_private_data_is_rejected_and_partial_snapshot_erased(): void
    {
        $external = $this->home.'/outside-data';
        file_put_contents($external, 'private');
        self::assertTrue(symlink($external, $this->privateData.'/user/link.txt'));
        $this->assertRejected(fn () => $this->snapshot());
        self::assertSame([], glob($this->backupRoot.'/recovery-*') ?: []);
        self::assertSame([], glob($this->backupRoot.'/.recovery-*.partial') ?: []);
    }

    public function test_backup_root_overlapping_live_sources_is_rejected(): void
    {
        $this->assertRejected(fn () => (new PrivateRecoverySnapshot())->create(
            $this->environment, $this->privateData, $this->uploads, $this->privateData,
        ));
    }

    public function test_isolated_recovery_drill_cannot_target_the_live_tree(): void
    {
        $snapshot = $this->snapshot();
        $this->assertRejected(fn () => (new PrivateRecoverySnapshot())->drill(
            $snapshot['directory'], $snapshot['manifest_sha256'], $snapshot['directory'],
        ));
    }

    public function test_public_html_backup_folder_must_never_receive_environment_secrets(): void
    {
        $unsafe = dirname($this->uploads).'/backups';
        self::assertTrue(mkdir($unsafe, 0700));
        $this->assertRejected(fn () => (new PrivateRecoverySnapshot())->create(
            $this->environment, $this->privateData, $this->uploads, $unsafe,
        ));
        self::assertSame([], glob($unsafe.'/*') ?: []);
    }

    public function test_hardlinked_user_data_is_rejected_and_partial_backup_is_removed(): void
    {
        $outside = $this->home.'/outside.txt';
        file_put_contents($outside, 'external secret');
        self::assertTrue(link($outside, $this->privateData.'/user/linked-secret.txt'));
        $this->assertRejected(fn () => $this->snapshot());
        self::assertSame([], glob($this->backupRoot.'/recovery-*') ?: []);
        self::assertSame([], glob($this->backupRoot.'/.recovery-*.partial') ?: []);
    }

    public function test_missing_environment_secret_is_not_silently_ignored(): void
    {
        unlink($this->environment);
        $this->assertRejected(fn () => $this->snapshot());
    }

    private function assertRejected(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('Unsafe recovery checkpoint was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

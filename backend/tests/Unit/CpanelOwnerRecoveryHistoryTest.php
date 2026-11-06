<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelOwnerRecoveryHistory;
use PHPUnit\Framework\TestCase;

final class CpanelOwnerRecoveryHistoryTest extends TestCase
{
    private string $home;
    private string $vault;
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-history-'.bin2hex(random_bytes(8));
        $this->vault = $this->home.'/.walidsite-backups';
        $this->id = str_repeat('a', 32);
        self::assertTrue(mkdir($this->home, 0700));
        self::assertTrue(mkdir($this->vault, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->vault.'/*') ?: [] as $path) {
            @unlink($path);
        }
        foreach (glob($this->vault.'/.*') ?: [] as $path) {
            if (! in_array(basename($path), ['.', '..'], true)) {
                @unlink($path);
            }
        }
        @rmdir($this->vault);
        @rmdir($this->home);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function receipt(): array
    {
        return [
            'schema' => 'walidsite.backup-restore-receipt.v1',
            'id' => $this->id,
            'created_at' => '2026-11-06T07:30:00+00:00',
            'installed_version' => '0.1.2',
            'files' => 3,
            'bytes' => 100,
            'tables' => 2,
            'rows' => 9,
            'file_sha256' => str_repeat('a', 64),
            'database_sha256' => str_repeat('b', 64),
            'code_update_performed' => false,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function save(array $receipt, ?string $id = null): string
    {
        $path = $this->vault.'/receipt-'.($id ?? $this->id).'.json';
        self::assertNotFalse(file_put_contents($path, json_encode($receipt, JSON_THROW_ON_ERROR)));
        self::assertTrue(chmod($path, 0600));
        return $path;
    }

    public function test_reports_only_whitelisted_history_metadata_and_never_authorizes_update(): void
    {
        $history = new CpanelOwnerRecoveryHistory();
        self::assertSame('none', $history->inspect($this->home)['state']);
        $this->save($this->receipt());
        $result = $history->inspect($this->home);
        self::assertSame('history', $result['state']);
        self::assertSame([[
            'id' => $this->id,
            'created_at' => '2026-11-06T07:30:00+00:00',
            'version' => '0.1.2',
            'files' => 3,
            'tables' => 2,
            'rows' => 9,
        ]], $result['receipts']);
        self::assertArrayNotHasKey('code_update_performed', $result['receipts'][0]);
        self::assertArrayNotHasKey('database_sha256', $result['receipts'][0]);
        self::assertArrayNotHasKey('bytes', $result['receipts'][0]);
    }

    public function test_tampered_or_unsafe_receipt_fails_closed_without_partial_history(): void
    {
        $path = $this->save($this->receipt());
        $history = new CpanelOwnerRecoveryHistory();
        foreach ([
            ['id' => str_repeat('b', 32)],
            ['code_update_performed' => true],
            ['file_sha256' => 'tampered'],
            ['installed_version' => '../0.1.2'],
            ['created_at' => 'yesterday'],
        ] as $change) {
            $this->save(array_replace($this->receipt(), $change));
            self::assertSame(['state' => 'blocked', 'receipts' => []], $history->inspect($this->home));
        }
        $this->save($this->receipt());
        self::assertTrue(chmod($path, 0644));
        self::assertSame('blocked', $history->inspect($this->home)['state']);
        self::assertTrue(chmod($path, 0600));
        self::assertTrue(chmod($this->vault, 0770));
        self::assertSame('blocked', $history->inspect($this->home)['state']);
    }

    public function test_receipt_symlink_and_hardlink_are_not_followed(): void
    {
        $path = $this->vault.'/receipt-'.$this->id.'.json';
        if (! function_exists('symlink') || ! @symlink('/etc/passwd', $path)) {
            $this->markTestSkipped('Symlink unavailable.');
        }
        $history = new CpanelOwnerRecoveryHistory();
        self::assertSame('blocked', $history->inspect($this->home)['state']);
        self::assertTrue(unlink($path));
        $this->save($this->receipt());
        if (@link($path, $this->vault.'/alias')) {
            self::assertSame('blocked', $history->inspect($this->home)['state']);
        }
    }

    public function test_unexpected_receipt_filename_fails_closed(): void
    {
        file_put_contents($this->vault.'/receipt-untrusted.php', '<?php');
        self::assertSame('blocked', (new CpanelOwnerRecoveryHistory())->inspect($this->home)['state']);
    }
}

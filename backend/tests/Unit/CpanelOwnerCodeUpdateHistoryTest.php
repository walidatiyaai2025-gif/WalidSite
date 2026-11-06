<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelOwnerCodeUpdateHistory;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CpanelOwnerCodeUpdateHistoryTest extends TestCase
{
    private string $home;
    private string $appKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-history-'.bin2hex(random_bytes(8));
        $this->appKey = 'base64:'.base64_encode(str_repeat('K', 32));
        self::assertTrue(mkdir($this->home, 0700));
        self::assertTrue(mkdir($this->home.'/application', 0700));
        self::assertTrue(mkdir($this->home.'/public_html', 0700));
        self::assertTrue(mkdir($this->home.'/.walidsite-releases', 0700));

        file_put_contents($this->home.'/application/VERSION', "0.1.2\n");
        chmod($this->home.'/application/VERSION', 0600);
        $root = $this->home.'/'.$this->release()['to'];
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($root.'/application', 0700));
        file_put_contents($root.'/application/VERSION', "0.1.3\n");
        chmod($root.'/application/VERSION', 0600);
        file_put_contents($this->home.'/.walidsite-active-release.json', json_encode([
            'schema' => 1, 'version' => '0.1.3',
            'root' => $this->release()['to'],
        ], JSON_THROW_ON_ERROR));
        chmod($this->home.'/.walidsite-active-release.json', 0600);
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($walk as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    /** @return array{state:string,version:string,source_sha:string,backup_id:string,from:string,to:string} */
    private function release(): array
    {
        return [
            'state' => 'installed_verified',
            'version' => '0.1.3',
            'source_sha' => str_repeat('a', 40),
            'backup_id' => str_repeat('b', 32),
            'from' => '0.1.2',
            'to' => '.walidsite-releases/v0.1.3-'.str_repeat('a', 40),
        ];
    }

    private function journal(): string
    {
        return $this->home.'/.walidsite-code-update-history';
    }

    public function test_current_pointer_is_required_and_only_successful_cutovers_enter_history(): void
    {
        $history = new CpanelOwnerCodeUpdateHistory();
        self::assertSame('none', $history->inspect($this->home, $this->appKey)['state']);
        $invalid = $this->release();
        $invalid['state'] = 'staged';
        try {
            $history->record($this->home, $invalid, $this->appKey);
            self::fail('Unverified staged ZIP must not create an installed code receipt.');
        } catch (DomainException) {
            self::assertDirectoryDoesNotExist($this->journal());
        }

        $invalid = $this->release();
        $invalid['to'] = '.walidsite-releases/v0.1.3-'.str_repeat('c', 40);
        try {
            $history->record($this->home, $invalid, $this->appKey);
            self::fail('Unverified release root must not enter history.');
        } catch (DomainException) {
            self::assertDirectoryDoesNotExist($this->journal());
        }

        unlink($this->home.'/.walidsite-active-release.json');
        try {
            $history->record($this->home, $this->release(), $this->appKey);
            self::fail('Missing active pointer must not produce a completion receipt.');
        } catch (DomainException) {
            self::assertDirectoryDoesNotExist($this->journal());
        }
    }

    public function test_private_authenticated_history_is_bounded_and_distinct_from_recovery_proof(): void
    {
        $history = new CpanelOwnerCodeUpdateHistory();
        $history->record($this->home, $this->release(), $this->appKey);
        $report = $history->inspect($this->home, $this->appKey);
        self::assertSame('history', $report['state']);
        self::assertCount(1, $report['receipts']);
        self::assertSame('0.1.2', $report['receipts'][0]['from']);
        self::assertSame('0.1.3', $report['receipts'][0]['version']);
        self::assertSame(str_repeat('a', 40), $report['receipts'][0]['source_sha']);
        self::assertSame(str_repeat('b', 32), $report['receipts'][0]['backup_id']);
        self::assertArrayNotHasKey('mac', $report['receipts'][0]);
        self::assertSame(0700, fileperms($this->journal()) & 0777);
        $files = glob($this->journal().'/receipt-*.json') ?: [];
        self::assertCount(1, $files);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        self::assertSame(1, stat($files[0])['nlink']);
        self::assertStringNotContainsString($this->appKey, file_get_contents($files[0]));
        self::assertSame('blocked',
            $history->inspect($this->home, 'base64:'.base64_encode(str_repeat('Z', 32)))['state']);
        self::assertSame('history', $history->inspect($this->home, $this->appKey)['state']);
    }

    public function test_modified_or_linked_receipts_are_never_displayed(): void
    {
        $history = new CpanelOwnerCodeUpdateHistory();
        $history->record($this->home, $this->release(), $this->appKey);
        $file = (glob($this->journal().'/receipt-*.json') ?: [])[0];
        $original = file_get_contents($file);
        self::assertIsString($original);
        $modified = str_replace('0.1.3', '0.1.4', $original);
        self::assertNotSame($original, $modified);
        file_put_contents($file, $modified);
        self::assertSame('blocked', $history->inspect($this->home, $this->appKey)['state']);
        file_put_contents($file, $original);
        self::assertSame('history', $history->inspect($this->home, $this->appKey)['state']);

        unlink($file);
        self::assertTrue(symlink($this->home.'/application/VERSION', $file));
        self::assertSame('blocked', $history->inspect($this->home, $this->appKey)['state']);
    }

    public function test_foreign_entries_and_partial_crash_receipts_block_history_without_claiming_success(): void
    {
        $history = new CpanelOwnerCodeUpdateHistory();
        $history->record($this->home, $this->release(), $this->appKey);
        file_put_contents($this->journal().'/.partial-'.str_repeat('f', 32), 'incomplete');
        self::assertSame('blocked', $history->inspect($this->home, $this->appKey)['state']);
        unlink($this->journal().'/.partial-'.str_repeat('f', 32));
        self::assertSame('history', $history->inspect($this->home, $this->appKey)['state']);
        chmod($this->journal(), 0777);
        self::assertSame('blocked', $history->inspect($this->home, $this->appKey)['state']);
    }

    public function test_nonregular_fifo_receipt_fails_closed_without_blocking(): void
    {
        if (! function_exists('posix_mkfifo')) {
            self::markTestSkipped('POSIX FIFO fixture unavailable');
        }
        $history = new CpanelOwnerCodeUpdateHistory();
        $history->record($this->home, $this->release(), $this->appKey);
        $fifo = $this->journal().'/receipt-'.str_repeat('c', 32).'.json';
        self::assertTrue(posix_mkfifo($fifo, 0600));
        self::assertSame('blocked', $history->inspect($this->home, $this->appKey)['state']);
    }
}

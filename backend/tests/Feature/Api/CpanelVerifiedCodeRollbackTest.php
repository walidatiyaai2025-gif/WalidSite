<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\CpanelImmutableCodePointer;
use App\Domain\Deployment\CpanelReleaseMaintenance;
use App\Domain\Deployment\CpanelVerifiedCodeRollback;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CpanelVerifiedCodeRollbackTest extends TestCase
{
    private string $home;
    private string $front;
    private string $previousRoot;
    private string $previousApplication;
    private string $candidate;
    private string $token;
    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-verified-rollback-'.bin2hex(random_bytes(8));
        $this->front = dirname(__DIR__, 3).'/resources/deployment/cpanel-stable-index.php';
        $this->sha = str_repeat('c', 40);
        $this->previousRoot = '.walidsite-releases/v0.1.1-'.str_repeat('b', 40);
        $this->previousApplication = $this->home.'/'.$this->previousRoot.'/application';
        $this->candidate = $this->home.'/.walidsite-releases/v0.1.2-'.$this->sha;

        foreach ([
            $this->home.'/application/storage/app',
            $this->home.'/public_html',
            $this->previousApplication.'/bootstrap',
            $this->previousApplication.'/vendor',
            $this->home.'/'.$this->previousRoot.'/public_html',
            $this->candidate.'/application/bootstrap',
            $this->candidate.'/application/vendor',
            $this->candidate.'/public_html',
        ] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }

        file_put_contents($this->home.'/application/VERSION', "0.1.0\n");
        file_put_contents($this->home.'/application/.env', 'KEEP_APP_KEY');
        file_put_contents($this->home.'/application/storage/app/owner.txt', 'KEEP_PRIVATE');
        copy($this->front, $this->home.'/public_html/index.php');
        file_put_contents($this->home.'/public_html/.htaccess', 'RewriteEngine On');

        foreach ([
            $this->previousApplication.'/VERSION' => "0.1.1\n",
            $this->candidate.'/application/VERSION' => "0.1.2\n",
        ] as $path => $bytes) {
            file_put_contents($path, $bytes);
        }
        foreach ([
            'composer.json' => '{"name":"walidsite/app","require":{"php":"^8.3"}}',
            'composer.lock' => '{"packages":[],"content-hash":"stable"}',
        ] as $name => $bytes) {
            file_put_contents($this->previousApplication.'/'.$name, $bytes);
            file_put_contents($this->candidate.'/application/'.$name, $bytes);
        }
        file_put_contents($this->previousApplication.'/bootstrap/app.php', '<?php');
        file_put_contents($this->previousApplication.'/vendor/autoload.php', '<?php');
        file_put_contents($this->candidate.'/application/bootstrap/app.php', '<?php');
        file_put_contents($this->candidate.'/application/vendor/autoload.php', '<?php');
        copy($this->front, $this->home.'/'.$this->previousRoot.'/public_html/index.php');
        copy($this->front, $this->candidate.'/public_html/index.php');
        file_put_contents($this->home.'/'.$this->previousRoot.'/public_html/.htaccess', 'RewriteEngine On');
        file_put_contents($this->candidate.'/public_html/.htaccess', 'RewriteEngine On');

        $pointer = $this->home.'/.walidsite-active-release.json';
        file_put_contents($pointer, json_encode([
            'schema' => 1, 'version' => '0.1.1', 'root' => $this->previousRoot,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertTrue(chmod($pointer, 0600));

        $this->token = (new CpanelReleaseMaintenance())->begin(
            $this->home, $this->previousRoot, '0.1.1',
        )['token'];
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    private function rejectCandidateHealth(): CpanelVerifiedCodeRollback
    {
        try {
            (new CpanelImmutableCodePointer())->activate(
                $this->home, $this->candidate, '0.1.2', $this->sha,
                $this->front, $this->token,
                static fn (): bool => true,
                static fn (): bool => true,
                static fn (): bool => true,
                static fn (): bool => false,
            );
        } catch (CpanelVerifiedCodeRollback $proof) {
            return $proof;
        }
        self::fail('A failed candidate health probe did not issue a verified rollback proof.');
    }

    public function test_chained_health_failure_restores_and_reverifies_previous_running_release(): void
    {
        $proof = $this->rejectCandidateHealth();

        self::assertSame($this->previousRoot, $proof->previousRoot);
        self::assertSame('0.1.1', $proof->previousVersion);
        self::assertSame([
            'schema' => 1, 'version' => '0.1.1', 'root' => $this->previousRoot,
        ], $proof->previousPointer);
        self::assertFileExists($this->home.'/.walidsite-active-release.json');
        self::assertFileExists($this->home.'/.walidsite-update-maintenance.json');

        $maintenance = new CpanelReleaseMaintenance();
        $pointer = new CpanelImmutableCodePointer();
        $pointer->assertVerifiedRollback(
            $this->home, $this->previousApplication, $this->home.'/public_html',
            $this->front, $this->token, $proof,
        );
        $maintenance->clear($this->home, $this->token);

        self::assertFileDoesNotExist($this->home.'/.walidsite-update-maintenance.json');
        self::assertSame('KEEP_APP_KEY', file_get_contents($this->home.'/application/.env'));
        self::assertSame('KEEP_PRIVATE', file_get_contents($this->home.'/application/storage/app/owner.txt'));
        self::assertSame("0.1.1\n", file_get_contents($this->previousApplication.'/VERSION'));
    }

    public function test_mutated_previous_release_keeps_maintenance_barrier_closed(): void
    {
        $proof = $this->rejectCandidateHealth();
        file_put_contents($this->previousApplication.'/VERSION', "0.1.9\n");

        try {
            (new CpanelImmutableCodePointer())->assertVerifiedRollback(
                $this->home, $this->previousApplication, $this->home.'/public_html',
                $this->front, $this->token, $proof,
            );
            self::fail('A changed previous release was incorrectly accepted for recovery.');
        } catch (DomainException) {
            self::assertFileExists($this->home.'/.walidsite-update-maintenance.json');
        }
    }
}

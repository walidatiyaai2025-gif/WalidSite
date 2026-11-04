<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelImmutableCodePointer;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CpanelImmutableCodePointerTest extends TestCase
{
    private string $home;
    private string $candidate;
    private string $front;
    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-pointer-'.bin2hex(random_bytes(8));
        $this->sha = str_repeat('a', 40);
        $this->candidate = $this->home.'/.walidsite-releases/v0.1.2-'.$this->sha;
        $this->front = dirname(__DIR__, 2).'/resources/deployment/cpanel-stable-index.php';

        foreach ([
            $this->home.'/application/storage/app',
            $this->home.'/public_html',
            $this->candidate.'/application/vendor',
            $this->candidate.'/application/bootstrap',
            $this->candidate.'/public_html',
        ] as $dir) {
            $this->assertTrue(mkdir($dir, 0700, true));
        }
        file_put_contents($this->home.'/application/VERSION', "0.1.1\n");
        file_put_contents($this->home.'/application/.env', 'KEEP_MY_PRIVATE_APP_KEY');
        file_put_contents($this->home.'/application/storage/app/photo.txt', 'PRIVATE_UPLOAD');
        copy($this->front, $this->home.'/public_html/index.php');
        file_put_contents($this->candidate.'/application/bootstrap/app.php', '<?php');
        file_put_contents($this->candidate.'/application/vendor/autoload.php', '<?php');
        file_put_contents($this->candidate.'/application/VERSION', "0.1.2\n");
        file_put_contents($this->candidate.'/public_html/index.php', '<?php');
        file_put_contents($this->candidate.'/public_html/.htaccess', 'RewriteEngine On');
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    /** @return array<mixed> */
    private function args(?\Closure $maintenance = null, ?\Closure $recovery = null,
                          ?\Closure $signed = null, ?\Closure $health = null): array
    {
        return [
            $this->home, $this->candidate, '0.1.2', $this->sha,
            $this->front,
            $maintenance ?? static fn (): bool => true,
            $recovery ?? static fn (): bool => true,
            $signed ?? static fn (): bool => true,
            $health ?? static fn (): bool => true,
        ];
    }

    public function test_private_pointer_switch_is_atomic_and_preserves_installed_data(): void
    {
        $result = (new CpanelImmutableCodePointer())->activate(...$this->args());
        $this->assertSame('switched', $result['state']);
        $this->assertSame('0.1.1', $result['from']);
        $this->assertSame('0.1.2', $result['version']);

        $pointer = $this->home.'/.walidsite-active-release.json';
        $this->assertFileExists($pointer);
        $this->assertSame(0600, fileperms($pointer) & 0777);
        $record = json_decode(file_get_contents($pointer), true);
        $this->assertSame('.walidsite-releases/v0.1.2-'.$this->sha, $record['root']);
        $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents($this->home.'/application/.env'));
        $this->assertSame('PRIVATE_UPLOAD', file_get_contents($this->home.'/application/storage/app/photo.txt'));
        $this->assertSame(file_get_contents($this->front), file_get_contents($this->home.'/public_html/index.php'));
    }

    public function test_failed_health_restores_previous_code_without_touching_private_data(): void
    {
        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                health: static fn (): bool => false,
            ));
            $this->fail('Health failure unexpectedly activated code.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('old code pointer restored', $failure->getMessage());
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents($this->home.'/application/.env'));
        }
    }

    public function test_unsigned_or_unbacked_or_non_quiesced_switch_is_forbidden(): void
    {
        foreach (['maintenance','recovery','signed'] as $rejected) {
            $kwargs = [
                'maintenance' => static fn (): bool => true,
                'recovery' => static fn (): bool => true,
                'signed' => static fn (): bool => true,
            ];
            $kwargs[$rejected] = static fn (): bool => false;
            try {
                (new CpanelImmutableCodePointer())->activate(...$this->args(...$kwargs));
                $this->fail($rejected.' gate not enforced');
            } catch (DomainException) {
                $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            }
        }
    }

    public function test_untrusted_public_dispatcher_is_rejected(): void
    {
        file_put_contents($this->home.'/public_html/index.php', '<?php echo "untrusted";');
        $this->expectException(DomainException::class);
        (new CpanelImmutableCodePointer())->activate(...$this->args());
    }

    public function test_migration_changes_cannot_use_a_code_only_pointer_switch(): void
    {
        mkdir($this->home.'/application/database/migrations', 0700, true);
        mkdir($this->candidate.'/application/database/migrations', 0700, true);
        file_put_contents($this->home.'/application/database/migrations/old.php', '<?php // old');
        file_put_contents($this->candidate.'/application/database/migrations/old.php', '<?php // changed');
        $this->expectException(DomainException::class);
        (new CpanelImmutableCodePointer())->activate(...$this->args());
    }

    public function test_candidate_must_be_the_exact_immutable_version_and_sha(): void
    {
        $bad = $this->args();
        $bad[2] = '0.1.3';
        $this->expectException(DomainException::class);
        (new CpanelImmutableCodePointer())->activate(...$bad);
    }
}

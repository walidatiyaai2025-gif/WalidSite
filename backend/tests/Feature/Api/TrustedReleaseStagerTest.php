<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseStager;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class TrustedReleaseStagerTest extends TestCase
{
    private string $home;

    private string $private;

    private string $public;

    private string $incoming;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZIP extension is required for a real release extraction test.');
        }

        $this->home = sys_get_temp_dir().'/walidsite-private-stage-'.bin2hex(random_bytes(8));
        $this->private = $this->home.'/application';
        $this->public = $this->home.'/public_html';
        $this->incoming = $this->private.'/incoming';
        self::assertTrue(mkdir($this->incoming, 0700, true));
        self::assertTrue(mkdir($this->public, 0700));
        file_put_contents($this->public.'/index.php', 'LIVE-SITE-UNTOUCHED');
        file_put_contents($this->private.'/.env', 'LIVE-APP-KEY-UNTOUCHED');
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->home, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                if ($item->isDir() && ! $item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            rmdir($this->home);
        }
        parent::tearDown();
    }

    /** @return array{0:string,1:array{artifact_url:string,artifact_size:int,artifact_sha256:string}} */
    private function release(bool $unsafe = false): array
    {
        $path = $this->incoming.'/WalidSite-update-v1.2.0.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ([
            'application/bootstrap/app.php' => '<?php return "new";',
            'application/vendor/autoload.php' => '<?php return [];',
            'public_html/index.php' => 'NEW-PUBLIC-ENTRY',
            'public_html/.htaccess' => 'RewriteEngine On',
            'contracts/product.json' => '{"product":"walidsite"}',
        ] as $file => $bytes) {
            self::assertTrue($zip->addFromString($file, $bytes));
        }
        if ($unsafe) {
            self::assertTrue($zip->addFromString('application/.env', 'OVERRIDE-SECRET'));
        }
        self::assertTrue($zip->close());

        return [$path, [
            'artifact_url' => 'https://updates.example.com/releases/WalidSite-update-v1.2.0.zip',
            'artifact_size' => filesize($path),
            'artifact_sha256' => hash_file('sha256', $path),
        ]];
    }

    public function test_verified_full_code_release_is_extracted_to_private_unique_directory_only(): void
    {
        [$file, $signed] = $this->release();
        $result = (new TrustedReleaseStager())->stage(
            $signed, $this->incoming, $file, $this->private, $this->public,
        );
        self::assertSame(5, $result['files']);
        self::assertGreaterThan(0, $result['bytes']);
        self::assertStringStartsWith(realpath($this->private).'/release-staging/candidate-', $result['directory']);
        self::assertSame('NEW-PUBLIC-ENTRY', file_get_contents($result['directory'].'/public_html/index.php'));
        self::assertSame('LIVE-SITE-UNTOUCHED', file_get_contents($this->public.'/index.php'));
        self::assertSame('LIVE-APP-KEY-UNTOUCHED', file_get_contents($this->private.'/.env'));

        $again = (new TrustedReleaseStager())->stage(
            $signed, $this->incoming, $file, $this->private, $this->public,
        );
        self::assertNotSame($result['directory'], $again['directory']);
    }

    public function test_stage_rejects_untrusted_archive_without_creating_candidate(): void
    {
        [$file, $signed] = $this->release();
        $signed['artifact_sha256'] = str_repeat('0', 64);
        $this->reject($signed, $file, $this->private, $this->public);
        self::assertSame([], glob($this->private.'/release-staging/candidate-*') ?: []);

        [$file, $signed] = $this->release(true);
        $this->reject($signed, $file, $this->private, $this->public);
        self::assertSame([], glob($this->private.'/release-staging/candidate-*') ?: []);
    }

    public function test_stage_rejects_public_or_nested_public_private_roots(): void
    {
        [$file, $signed] = $this->release();
        $this->reject($signed, $file, $this->public, $this->public);
        $this->reject($signed, $file, $this->public, $this->private);
        $this->reject($signed, $file, $this->home, $this->home);
        $this->reject($signed, $file, $this->home, $this->public);
        self::assertSame('LIVE-SITE-UNTOUCHED', file_get_contents($this->public.'/index.php'));
    }

    /** @param array{artifact_url:string,artifact_size:int,artifact_sha256:string} $signed */
    private function reject(array $signed, string $file, string $private, string $public): void
    {
        try {
            (new TrustedReleaseStager())->stage($signed, $this->incoming, $file, $private, $public);
            self::fail('Unsafe full-code staging was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

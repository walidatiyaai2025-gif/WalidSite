<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\TrustedReleaseArchive;
use DomainException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class TrustedReleaseArchiveTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZIP extension required for actual release archive checks.');
        }
        $this->directory = sys_get_temp_dir().'/walidsite-zip-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    /** @return array<string,string> */
    private function baseline(): array
    {
        return [
            'application/bootstrap/app.php' => '<?php return [];',
            'application/vendor/autoload.php' => '<?php return [];',
            'public_html/index.php' => '<?php echo "ok";',
            'public_html/.htaccess' => 'Deny from all',
            'contracts/product.json' => '{"product":"walidsite"}',
        ];
    }

    /** @param array<string,string> $entries */
    private function archive(array $entries, ?callable $mutate = null): array
    {
        $file = $this->directory.'/WalidSite-update-v1.2.0.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $path => $bytes) {
            self::assertTrue($zip->addFromString($path, $bytes));
        }
        if ($mutate !== null) {
            $mutate($zip);
        }
        self::assertTrue($zip->close());

        return [$file, [
            'artifact_url' => 'https://updates.example.com/releases/WalidSite-update-v1.2.0.zip',
            'artifact_size' => filesize($file),
            'artifact_sha256' => hash_file('sha256', $file),
        ]];
    }

    public function test_complete_signed_release_inventory_is_accepted_without_extraction(): void
    {
        [$file, $release] = $this->archive($this->baseline());
        $result = (new TrustedReleaseArchive())->inspect($release, $this->directory, $file);
        self::assertSame(5, $result['files']);
        self::assertGreaterThan(0, $result['uncompressed_bytes']);
        self::assertFileDoesNotExist($this->directory.'/application/bootstrap/app.php');
    }

    public function test_rejects_missing_application_components_and_preserved_runtime_secrets(): void
    {
        $baseline = $this->baseline();
        foreach (array_keys($baseline) as $name) {
            if (! in_array($name, ['application/bootstrap/app.php', 'application/vendor/autoload.php', 'public_html/index.php', 'public_html/.htaccess'])) {
                continue;
            }
            $entries = $baseline;
            unset($entries[$name]);
            $this->reject($entries);
        }

        foreach ([
            'application/.env',
            'application/.env.production',
            'application/storage/private.txt',
            'public_html/uploads/photo.jpg',
            'public_html/storage/secrets',
            'application/site-first-run.json',
        ] as $name) {
            $this->reject($baseline + [$name => 'private']);
        }
    }

    public function test_rejects_zip_slip_hidden_paths_and_unapproved_roots(): void
    {
        foreach ([
            '../escape.php', '/tmp/evil.php', 'application/../public_html/evil.php',
            'application//bootstrap/evil.php', 'application/.git/config',
            'public_html/.user.ini', 'public_html/evil\path.php',
            'backdoor.php', 'public_html/public/../../evil.php',
            'C:/windows/evil.php', 'application/vendor/.hidden/file',
        ] as $name) {
            $this->reject($this->baseline() + [$name => 'bad']);
        }
    }

    public function test_tampered_archive_digest_is_rejected_before_zip_inspection(): void
    {
        [$file, $release] = $this->archive($this->baseline());
        $release['artifact_sha256'] = str_repeat('0', 64);
        $this->assertRejectsFile($file, $release);

        $release['artifact_sha256'] = hash_file('sha256', $file);
        $release['artifact_size']++;
        $this->assertRejectsFile($file, $release);
    }

    public function test_symlink_attributes_are_rejected(): void
    {
        [$file, $release] = $this->archive($this->baseline(), static function (ZipArchive $zip): void {
            self::assertTrue($zip->addFromString('application/linked.php', 'target'));
            self::assertTrue($zip->setExternalAttributesName(
                'application/linked.php', ZipArchive::OPSYS_UNIX, (0120777 << 16),
            ));
        });
        $this->assertRejectsFile($file, $release);
    }

    /** @param array<string,string> $entries */
    private function reject(array $entries): void
    {
        [$file, $release] = $this->archive($entries);
        $this->assertRejectsFile($file, $release);
    }

    private function assertRejectsFile(string $file, array $release): void
    {
        try {
            (new TrustedReleaseArchive())->inspect($release, $this->directory, $file);
            self::fail('Unsafe verified release ZIP was accepted.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

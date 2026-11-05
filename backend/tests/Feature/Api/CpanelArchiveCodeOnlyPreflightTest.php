<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\CpanelArchiveCodeOnlyPreflight;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class CpanelArchiveCodeOnlyPreflightTest extends TestCase
{
    private string $root;

    private string $archive;

    private string $migration = '2026_11_04_010000_create_records.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('Actual ZipArchive extension required.');
        }
        $this->root = sys_get_temp_dir().'/walidsite-code-only-'.bin2hex(random_bytes(8));
        $this->archive = $this->root.'/WalidSite-update-v0.1.3.zip';
        self::assertTrue(mkdir($this->root.'/application/database/migrations', 0700, true));
        self::assertTrue(mkdir($this->root.'/public_html', 0700, true));
        file_put_contents($this->root.'/application/VERSION', "0.1.2\n");
        file_put_contents($this->root.'/application/database/migrations/'.$this->migration, '<?php return "original";');
        file_put_contents($this->root.'/public_html/index.php', '<?php echo "stable";');
        file_put_contents($this->root.'/public_html/.htaccess', "RewriteEngine On\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && ! $item->isLink()
                    ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    /** @return array<string,string> */
    private function entries(): array
    {
        return [
            'application/VERSION' => "0.1.3\n",
            'application/bootstrap/app.php' => '<?php return [];',
            'application/vendor/autoload.php' => '<?php return [];',
            'application/database/migrations/'.$this->migration => '<?php return "original";',
            'public_html/index.php' => '<?php echo "stable";',
            'public_html/.htaccess' => "RewriteEngine On\n",
        ];
    }

    /** @param array<string,string> $files */
    private function makeZip(array $files, ?callable $modify = null): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        if ($modify !== null) {
            $modify($zip);
        }
        self::assertTrue($zip->close());

        return hash_file('sha256', $this->archive);
    }

    private function check(string $sha, string $version = '0.1.3'): array
    {
        return (new CpanelArchiveCodeOnlyPreflight())->assertCompatible(
            $this->root, $this->root.'/public_html', $this->archive,
            $sha, '0.1.2', $version,
        );
    }

    private function reject(string $sha, string $message): void
    {
        $before = hash_file('sha256', $this->root.'/application/database/migrations/'.$this->migration);
        try {
            $this->check($sha);
            self::fail($message);
        } catch (DomainException) {
            self::assertSame($before, hash_file(
                'sha256', $this->root.'/application/database/migrations/'.$this->migration,
            ));
        }
    }

    public function test_real_verified_zip_with_identical_migrations_and_stable_public_code_passes_read_only(): void
    {
        $sha = $this->makeZip($this->entries());
        self::assertSame(
            ['state' => 'code_only_compatible', 'migrations' => 1],
            $this->check($sha),
        );
        self::assertFileDoesNotExist($this->root.'/application/bootstrap/app.php');
        self::assertSame("0.1.2\n", file_get_contents($this->root.'/application/VERSION'));
    }

    public function test_added_modified_removed_migrations_are_denied_without_unzipping(): void
    {
        foreach (['added', 'modified', 'removed'] as $case) {
            $entries = $this->entries();
            $key = 'application/database/migrations/'.$this->migration;
            if ($case === 'added') {
                $entries['application/database/migrations/2026_11_05_010000_new.php'] = '<?php return true;';
            } elseif ($case === 'modified') {
                $entries[$key] = '<?php return "changed";';
            } else {
                unset($entries[$key]);
            }
            $this->reject($this->makeZip($entries), 'Changed '.$case.' migrations cannot upgrade via code-only.');
        }
    }

    public function test_missing_or_wrong_candidate_version_and_modified_dispatcher_are_rejected(): void
    {
        foreach (['missing', 'wrong', 'dispatcher', 'htaccess'] as $case) {
            $entries = $this->entries();
            if ($case === 'missing') {
                unset($entries['application/VERSION']);
            } elseif ($case === 'wrong') {
                $entries['application/VERSION'] = '0.1.8';
            } elseif ($case === 'dispatcher') {
                $entries['public_html/index.php'] = '<?php echo "other";';
            } else {
                $entries['public_html/.htaccess'] = 'RewriteEngine Off';
            }
            $this->reject($this->makeZip($entries), 'Incompatible '.$case.' candidate should be rejected.');
        }
    }

    public function test_tampered_archive_and_incorrect_signed_sha_are_rejected_before_any_readiness_proof(): void
    {
        $sha = $this->makeZip($this->entries());
        $this->reject(str_repeat('0', 64), 'Incorrect signed artifact hash must fail.');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archive) === true);
        self::assertTrue($zip->addFromString('application/not-signed.php', '<?php echo "new";'));
        self::assertTrue($zip->close());
        $this->reject($sha, 'Changed ZIP bytes must not match signed digest.');
    }

    public function test_unsafe_migration_name_and_link_attributes_are_rejected(): void
    {
        $unsafe = $this->entries();
        $unsafe['application/database/migrations/README.txt'] = 'not a trusted migration';
        $this->reject($this->makeZip($unsafe), 'Unexpected migration file must fail.');
        $entries = $this->entries();
        $this->reject($this->makeZip($entries, static function (ZipArchive $zip): void {
            self::assertTrue($zip->setExternalAttributesName(
                'application/database/migrations/2026_11_04_010000_create_records.php',
                ZipArchive::OPSYS_UNIX, 0120777 << 16,
            ));
        }), 'Symlink migration must be denied.');
    }

    public function test_missing_or_divergent_installed_version_is_not_considered_compatible(): void
    {
        $sha = $this->makeZip($this->entries());
        file_put_contents($this->root.'/application/VERSION', '0.1.1');
        $this->reject($sha, 'Installed version drift must not pass.');
    }

    public function test_candidate_migrations_cannot_be_streamed_outside_safe_bounds(): void
    {
        $entries = $this->entries();
        $entries['application/database/migrations/'.$this->migration] = str_repeat('x', 2097153);
        $this->reject($this->makeZip($entries), 'Oversize migration must fail before reading.');
    }
}

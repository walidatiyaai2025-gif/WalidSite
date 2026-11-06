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
        file_put_contents($this->root.'/application/composer.json', '{"name":"walidsite/test","require":{"php":"^8.3"}}');
        file_put_contents($this->root.'/application/composer.lock', '{"packages":[],"content-hash":"baseline"}');
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
            'application/composer.json' => '{"name":"walidsite/test","require":{"php":"^8.3"}}',
            'application/composer.lock' => '{"packages":[],"content-hash":"baseline"}',
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

    public function test_changed_or_missing_composer_metadata_is_blocked_before_any_code_write(): void
    {
        foreach (['composer.json', 'composer.lock'] as $filename) {
            foreach (['missing', 'modified', 'invalid', 'oversized', 'linked'] as $case) {
                $entries = $this->entries();
                $key = 'application/'.$filename;
                if ($case === 'missing') {
                    unset($entries[$key]);
                } elseif ($case === 'modified') {
                    $entries[$key] = '{"packages":[{"name":"unexpected/package"}]}';
                } elseif ($case === 'invalid') {
                    $entries[$key] = '{not-json';
                } elseif ($case === 'oversized') {
                    $entries[$key] = str_repeat('x', $filename === 'composer.json' ? 1048577 : 8388609);
                }
                $modifier = $case === 'linked'
                    ? static function (ZipArchive $zip) use ($key): void {
                        self::assertTrue($zip->setExternalAttributesName(
                            $key, ZipArchive::OPSYS_UNIX, 0120777 << 16,
                        ));
                    } : null;
                $sha = $this->makeZip($entries, $modifier);
                $this->reject($sha, 'Unsafe '.$case.' '.$filename.' cannot pass read-only preflight.');
            }
        }
    }

    public function test_installed_composer_drift_or_links_are_denied(): void
    {
        foreach (['composer.json', 'composer.lock'] as $filename) {
            $path = $this->root.'/application/'.$filename;
            $original = file_get_contents($path);
            file_put_contents($path, '{"packages":[{"name":"unexpected/package"}]}');
            $this->reject($this->makeZip($this->entries()), 'Installed dependency drift must fail.');
            file_put_contents($path, $original);

            unlink($path);
            self::assertTrue(symlink($this->root.'/application/VERSION', $path));
            $this->reject($this->makeZip($this->entries()), 'Installed dependency symlink must fail.');
            unlink($path);
            file_put_contents($path, $original);
        }
    }

    public function test_hardlinked_installed_identity_and_archive_are_rejected_without_side_effects(): void
    {
        $sha = $this->makeZip($this->entries());
        foreach ([
            'application/VERSION',
            'application/composer.json',
            'application/composer.lock',
            'public_html/index.php',
            'public_html/.htaccess',
            'WalidSite-update-v0.1.3.zip',
        ] as $path) {
            $source = $this->root.'/'.$path;
            $alias = $this->root.'/pinned-identity-alias';
            if (! @link($source, $alias)) {
                self::markTestSkipped('POSIX hardlink fixtures are unavailable.');
            }
            try {
                $this->reject($sha, 'Hardlinked '.$path.' must not pass compatibility preflight.');
            } finally {
                unlink($alias);
            }
            self::assertSame(
                ['state' => 'code_only_compatible', 'migrations' => 1],
                $this->check($sha),
                'Removing the unsafe hardlink must restore a valid read-only preflight.',
            );
        }
    }

    public function test_group_writable_installed_identity_and_archive_fail_closed(): void
    {
        $sha = $this->makeZip($this->entries());
        foreach ([
            'application/VERSION',
            'application/composer.json',
            'application/composer.lock',
            'public_html/index.php',
            'public_html/.htaccess',
            'WalidSite-update-v0.1.3.zip',
        ] as $path) {
            $source = $this->root.'/'.$path;
            $originalMode = fileperms($source) & 0777;
            self::assertTrue(chmod($source, 0666));
            try {
                $this->reject($sha, 'A different hosting principal must not alter '.$path.' during attestation.');
            } finally {
                chmod($source, $originalMode);
            }
            self::assertSame(
                ['state' => 'code_only_compatible', 'migrations' => 1],
                $this->check($sha),
            );
        }
    }

    public function test_installed_version_and_public_dispatcher_symlink_fail_closed(): void
    {
        $sha = $this->makeZip($this->entries());
        foreach (['application/VERSION', 'public_html/index.php'] as $path) {
            $source = $this->root.'/'.$path;
            $safe = $source.'.safe';
            self::assertTrue(rename($source, $safe));
            self::assertTrue(symlink($safe, $source));
            try {
                $this->reject($sha, 'A linked installed identity must not pass signed ZIP preflight.');
            } finally {
                unlink($source);
                rename($safe, $source);
            }
            self::assertSame(
                ['state' => 'code_only_compatible', 'migrations' => 1],
                $this->check($sha),
            );
        }
    }

    public function test_candidate_migrations_cannot_be_streamed_outside_safe_bounds(): void
    {
        $entries = $this->entries();
        $entries['application/database/migrations/'.$this->migration] = str_repeat('x', 2097153);
        $this->reject($this->makeZip($entries), 'Oversize migration must fail before reading.');
    }

    /**
     * Simulate a successful first cPanel pointer switch without changing the
     * original private runtime application or the physical public_html.
     * The second update must compare against the active immutable code.
     */
    private function activateVersionedRelease(): string
    {
        $release = $this->root.'/.walidsite-releases/v0.1.3-'.str_repeat('a', 40);
        self::assertTrue(mkdir($release.'/application/database/migrations', 0700, true));
        foreach (['composer.json', 'composer.lock'] as $file) {
            self::assertTrue(copy($this->root.'/application/'.$file, $release.'/application/'.$file));
        }
        self::assertTrue(copy(
            $this->root.'/application/database/migrations/'.$this->migration,
            $release.'/application/database/migrations/'.$this->migration,
        ));
        file_put_contents($release.'/application/VERSION', "0.1.3\n");
        $pointer = $this->root.'/.walidsite-active-release.json';
        file_put_contents($pointer, json_encode([
            'schema' => 1, 'version' => '0.1.3',
            'root' => '.walidsite-releases/'.basename($release),
        ], JSON_THROW_ON_ERROR));
        self::assertTrue(chmod($pointer, 0600));

        return $release;
    }

    public function test_second_upgrade_reads_active_versioned_code_not_retired_original(): void
    {
        $active = $this->activateVersionedRelease();
        $entries = $this->entries();
        $entries['application/VERSION'] = "0.1.4\n";
        $sha = $this->makeZip($entries);
        $verify = fn () => (new CpanelArchiveCodeOnlyPreflight())->assertCompatible(
            $this->root, $this->root.'/public_html', $this->archive,
            $sha, '0.1.3', '0.1.4',
        );

        self::assertSame(
            ['state' => 'code_only_compatible', 'migrations' => 1],
            $verify(),
        );
        // Retired legacy code remains at v0.1.2, and is never overwritten.
        self::assertSame('0.1.2', trim(file_get_contents($this->root.'/application/VERSION')));
        self::assertSame('0.1.3', trim(file_get_contents($active.'/application/VERSION')));

        file_put_contents($active.'/application/composer.lock', '{"packages":[{"name":"drifted/active"}]}');
        try {
            $verify();
            self::fail('A second update cannot ignore dependency changes to active code.');
        } catch (DomainException) {
            self::assertSame('0.1.2', trim(file_get_contents($this->root.'/application/VERSION')));
        }
    }

    public function test_invalid_or_linked_active_release_pointer_never_falls_back_to_legacy(): void
    {
        $this->activateVersionedRelease();
        $entries = $this->entries();
        $entries['application/VERSION'] = "0.1.4\n";
        $sha = $this->makeZip($entries);
        $verify = fn () => (new CpanelArchiveCodeOnlyPreflight())->assertCompatible(
            $this->root, $this->root.'/public_html', $this->archive,
            $sha, '0.1.3', '0.1.4',
        );
        $pointer = $this->root.'/.walidsite-active-release.json';

        file_put_contents($pointer, '{"schema":1,"version":"0.1.3","root":"../../external"}');
        try {
            $verify();
            self::fail('Pointer traversal cannot select untrusted executable code.');
        } catch (DomainException) {
            self::assertSame('0.1.2', trim(file_get_contents($this->root.'/application/VERSION')));
        }

        if (function_exists('symlink')) {
            $outside = $this->root.'/pointer-untrusted.json';
            file_put_contents($outside, '{}');
            self::assertTrue(unlink($pointer));
            self::assertTrue(symlink($outside, $pointer));
            try {
                $verify();
                self::fail('Linked pointer cannot authorize a code-only release.');
            } catch (DomainException) {
                self::assertSame('{}', file_get_contents($outside));
            }
        }
    }
}

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
    private string $maintenanceToken;

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
        file_put_contents($this->home.'/public_html/.htaccess', 'RewriteEngine On');
        file_put_contents($this->candidate.'/application/bootstrap/app.php', '<?php');
        file_put_contents($this->candidate.'/application/vendor/autoload.php', '<?php');
        file_put_contents($this->candidate.'/application/VERSION', "0.1.2\n");
        // A code-only release must preserve both Composer metadata byte for
        // byte, not merely its migration inventory and stable web router.
        foreach ([
            'composer.json' => '{"name":"walidsite/app","require":{"php":"^8.3"}}',
            'composer.lock' => '{"packages":[],"content-hash":"stable"}',
        ] as $file => $bytes) {
            file_put_contents($this->home.'/application/'.$file, $bytes);
            file_put_contents($this->candidate.'/application/'.$file, $bytes);
        }
        copy($this->front, $this->candidate.'/public_html/index.php');
        file_put_contents($this->candidate.'/public_html/.htaccess', 'RewriteEngine On');
        mkdir($this->candidate.'/public_html/assets/icons', 0700, true);
        mkdir($this->candidate.'/public_html/assets/technology-logos', 0700, true);
        file_put_contents($this->candidate.'/public_html/assets/icons/new.svg', '<svg/>');
        file_put_contents($this->candidate.'/public_html/assets/technology-logos/LICENSE', 'MIT');
        file_put_contents($this->candidate.'/public_html/assets/technology-logos/ATTRIBUTION.md', 'Review');
        $this->maintenanceToken = (new \App\Domain\Deployment\CpanelReleaseMaintenance())
            ->begin($this->home, 'legacy', '0.1.1')['token'];
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
            $this->maintenanceToken,
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
        $this->assertSame('<svg/>', file_get_contents($this->home.'/public_html/assets/icons/new.svg'));
        $this->assertSame('MIT', file_get_contents($this->home.'/public_html/assets/technology-logos/LICENSE'));
        $this->assertSame('Review', file_get_contents($this->home.'/public_html/assets/technology-logos/ATTRIBUTION.md'));
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

    public function test_lease_expiring_during_successful_health_rolls_back_code_pointer(): void
    {
        $lease = $this->home.'/.walidsite-update-maintenance.json';
        $health = function () use ($lease): bool {
            // The new version was temporarily selected for this HTTP probe.
            $this->assertFileExists($this->home.'/.walidsite-active-release.json');
            $marker = json_decode(
                (string) file_get_contents($lease), true, 8, JSON_THROW_ON_ERROR,
            );
            $marker['expires_at'] = time() - 1;
            $this->assertNotFalse(file_put_contents(
                $lease, json_encode($marker, JSON_THROW_ON_ERROR),
            ));
            return true; // Successful health alone cannot authorize cutover.
        };

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                health: $health,
            ));
            $this->fail('Expired lease was accepted after health verification.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString(
                'old code pointer restored', $error->getMessage(),
            );
        }

        $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        $this->assertSame('0.1.1', trim((string) file_get_contents(
            $this->home.'/application/VERSION',
        )));
        $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
            $this->home.'/application/.env',
        ));
        $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
            $this->home.'/application/storage/app/photo.txt',
        ));
        // Keep the expired marker for supervised recovery; never silently
        // clear it and expose an unverified release via the web dispatcher.
        $this->assertFileExists($lease);
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

    public function test_foreign_or_expired_maintenance_lease_cannot_switch_code(): void
    {
        $args = $this->args();
        $args[5] = str_repeat('0', 64);
        try {
            (new CpanelImmutableCodePointer())->activate(...$args);
            $this->fail('Foreign maintenance token accepted');
        } catch (DomainException) {
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }

        $path = $this->home.'/.walidsite-update-maintenance.json';
        $json = json_decode((string) file_get_contents($path), true);
        $json['expires_at'] = time() - 1;
        file_put_contents($path, json_encode($json));
        $this->expectException(DomainException::class);
        (new CpanelImmutableCodePointer())->activate(...$this->args());
    }

    public function test_untrusted_public_dispatcher_is_rejected(): void
    {
        file_put_contents($this->home.'/public_html/index.php', '<?php echo "untrusted";');
        $this->expectException(DomainException::class);
        (new CpanelImmutableCodePointer())->activate(...$this->args());
    }

    public function test_immutable_public_asset_collision_rejects_replace_without_overwriting_existing_file(): void
    {
        mkdir($this->home.'/public_html/assets/icons', 0700, true);
        file_put_contents($this->home.'/public_html/assets/icons/new.svg', '<svg>existing</svg>');
        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args());
            $this->fail('Existing public asset was silently overwritten');
        } catch (DomainException $error) {
            $this->assertStringContainsString('collision', $error->getMessage());
            $this->assertSame('<svg>existing</svg>', file_get_contents($this->home.'/public_html/assets/icons/new.svg'));
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }
    }

    public function test_nested_public_asset_symlink_is_rejected_even_when_it_points_inside_web_root(): void
    {
        if (! function_exists('symlink') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX symlinks required for this regression.');
        }

        $alias = $this->home.'/public_html/alias';
        mkdir($alias.'/icons', 0700, true);
        if (! @symlink($alias, $this->home.'/public_html/assets')) {
            self::markTestSkipped('This environment disallows creating symlinks.');
        }

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args());
            $this->fail('Symlinked public asset directory was accepted.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('escapes web root', $error->getMessage());
        }

        $this->assertFileDoesNotExist($alias.'/icons/new.svg');
        $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
            $this->home.'/application/.env',
        ));
    }


    public function test_hard_linked_code_pointer_lock_blocks_activation_without_touching_private_data(): void
    {
        if (! function_exists('link')) {
            self::markTestSkipped('Hard links unavailable on this host.');
        }
        $external = $this->home.'/external-lock-data';
        file_put_contents($external, 'KEEP_OTHER_PRIVATE_DATA');
        if (! @link($external, $this->home.'/.walidsite-active-release.lock')) {
            self::markTestSkipped('Hard-link fixture cannot be created.');
        }

        $this->expectException(DomainException::class);
        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args());
        } finally {
            $this->assertSame('KEEP_OTHER_PRIVATE_DATA', file_get_contents($external));
            $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents($this->home.'/application/.env'));
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }
    }

    public function test_swapped_pointer_lock_during_maintenance_blocks_activation(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX rename semantics required.');
        }
        $lock = $this->home.'/.walidsite-active-release.lock';
        $old = $this->home.'/.orphaned-code-pointer.lock';
        $calls = 0;
        $maintenance = static function () use (&$calls, $lock, $old): bool {
            ++$calls;
            if ($calls === 1) {
                if (! rename($lock, $old) || file_put_contents($lock, 'replacement') === false) {
                    throw new RuntimeException('Cannot set up lock swap test.');
                }
            }
            return true;
        };

        $this->expectException(DomainException::class);
        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(maintenance: $maintenance));
        } finally {
            $this->assertSame(1, $calls);
            $this->assertSame('replacement', file_get_contents($lock));
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            $this->assertSame('PRIVATE_UPLOAD', file_get_contents($this->home.'/application/storage/app/photo.txt'));
        }
    }

    public function test_changed_missing_invalid_or_oversized_composer_identity_cannot_switch_code(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            $path = $this->candidate.'/application/'.$file;
            $original = file_get_contents($path);
            $cases = [
                '{"changed":true}',
                'not-valid-json',
                '',
                str_repeat(' ', $file === 'composer.json' ? 1048577 : 8388609),
            ];
            foreach ($cases as $bytes) {
                file_put_contents($path, $bytes);
                try {
                    (new CpanelImmutableCodePointer())->activate(...$this->args());
                    $this->fail($file.' incompatible metadata unexpectedly activated code.');
                } catch (DomainException) {
                    $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
                    $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
                        $this->home.'/application/.env',
                    ));
                    $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
                        $this->home.'/application/storage/app/photo.txt',
                    ));
                } finally {
                    file_put_contents($path, $original);
                }
            }

            unlink($path);
            try {
                (new CpanelImmutableCodePointer())->activate(...$this->args());
                $this->fail($file.' missing metadata unexpectedly activated code.');
            } catch (DomainException) {
                $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            } finally {
                file_put_contents($path, $original);
            }
        }
    }

    public function test_linked_or_hard_linked_composer_metadata_is_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('symlink')
            || ! function_exists('link')) {
            self::markTestSkipped('POSIX file-link fixtures required.');
        }
        $path = $this->candidate.'/application/composer.json';
        $original = file_get_contents($path);
        $other = $this->home.'/composer-alias-source.json';
        file_put_contents($other, $original);

        unlink($path);
        if (! @symlink($other, $path)) {
            file_put_contents($path, $original);
            self::markTestSkipped('Symlink fixtures unavailable.');
        }
        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args());
            $this->fail('Symlinked Composer metadata was accepted.');
        } catch (DomainException) {
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        }

        unlink($path);
        if (! @link($other, $path)) {
            file_put_contents($path, $original);
            self::markTestSkipped('Hard link fixtures unavailable.');
        }
        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args());
            $this->fail('Hard-linked Composer metadata was accepted.');
        } catch (DomainException) {
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            $this->assertSame($original, file_get_contents($other));
            $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
                $this->home.'/application/storage/app/photo.txt',
            ));
        }
    }

    public function test_composer_metadata_changed_in_host_callback_is_rechecked_before_cutover(): void
    {
        $calls = 0;
        $path = $this->candidate.'/application/composer.lock';
        $maintenance = static function () use (&$calls, $path): bool {
            ++$calls;
            if ($calls === 2) {
                file_put_contents($path, '{"packages":[{"name":"unexpected/runtime"}]}');
            }
            return true;
        };

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                maintenance: $maintenance,
            ));
            $this->fail('Late Composer dependency mutation unexpectedly switched code.');
        } catch (DomainException $failure) {
            $this->assertStringContainsString('Composer', $failure->getMessage());
            $this->assertSame(2, $calls);
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
                $this->home.'/application/.env',
            ));
            $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
                $this->home.'/application/storage/app/photo.txt',
            ));
        }
    }

    public function test_health_callback_cannot_change_runtime_dependencies_and_keep_new_pointer(): void
    {
        $candidateLock = $this->candidate.'/application/composer.lock';
        $health = function () use ($candidateLock): bool {
            // The new code is selected only provisionally during the probe.
            $this->assertFileExists($this->home.'/.walidsite-active-release.json');
            file_put_contents($candidateLock, '{"packages":[{"name":"late/runtime"}]}');
            return true;
        };

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                health: $health,
            ));
            $this->fail('Post-switch Composer mutation was accepted.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString(
                'old code pointer restored', $failure->getMessage(),
            );
            $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
            $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
                $this->home.'/application/.env',
            ));
            $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
                $this->home.'/application/storage/app/photo.txt',
            ));
        }
    }

    public function test_health_probe_cannot_mutate_signed_php_and_leave_pointer_active(): void
    {
        $bootstrap = $this->candidate.'/application/bootstrap/app.php';
        $originalDigest = hash_file('sha256', $bootstrap);
        $checks = 0;
        $signed = static function () use ($bootstrap, $originalDigest, &$checks): bool {
            ++$checks;
            return hash_equals($originalDigest, (string) hash_file('sha256', $bootstrap));
        };
        $health = function () use ($bootstrap): bool {
            $this->assertFileExists($this->home.'/.walidsite-active-release.json');
            file_put_contents($bootstrap, '<?php // modified after signed validation');
            return true;
        };

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                signed: $signed,
                health: $health,
            ));
            $this->fail('Mutated release PHP was accepted after health validation.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('old code pointer restored', $failure->getMessage());
        }

        $this->assertSame(3, $checks);
        $this->assertFileDoesNotExist($this->home.'/.walidsite-active-release.json');
        $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
            $this->home.'/application/.env',
        ));
        $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
            $this->home.'/application/storage/app/photo.txt',
        ));
    }

    public function test_health_callback_cannot_replace_active_pointer_and_trigger_unsafe_rollback(): void
    {
        $pointer = $this->home.'/.walidsite-active-release.json';
        $foreign = [
            'schema' => 1,
            'version' => '0.1.3',
            'root' => '.walidsite-releases/v0.1.3-'.str_repeat('b', 40),
        ];
        // A third party may replace the pointer with an invalid or different
        // release during an external HTTP health callback. Do not overwrite it.
        $health = function () use ($pointer): bool {
            $this->assertFileExists($pointer);
            file_put_contents($pointer, '{"foreign":"pointer"}');
            return true;
        };

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                health: $health,
            ));
            $this->fail('Changed active pointer was reported as switched.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('manual recovery required', $failure->getMessage());
        }

        $this->assertSame('{"foreign":"pointer"}', file_get_contents($pointer));
        $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
            $this->home.'/application/.env',
        ));
        $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
            $this->home.'/application/storage/app/photo.txt',
        ));
    }

    public function test_health_callback_cannot_swap_lock_and_report_success_or_rollback(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX rename semantics required.');
        }
        $lock = $this->home.'/.walidsite-active-release.lock';
        $orphan = $this->home.'/.orphaned-health-release.lock';
        $pointer = $this->home.'/.walidsite-active-release.json';
        $health = function () use ($lock, $orphan, $pointer): bool {
            $this->assertFileExists($pointer);
            if (! rename($lock, $orphan)
                || file_put_contents($lock, 'another updater') === false) {
                throw new RuntimeException('Unable to arrange health lock swap fixture.');
            }
            return true;
        };

        try {
            (new CpanelImmutableCodePointer())->activate(...$this->args(
                health: $health,
            ));
            $this->fail('Swapped release lock was accepted.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('manual recovery required', $failure->getMessage());
        }

        $this->assertSame('another updater', file_get_contents($lock));
        $this->assertFileExists($pointer); // Never unlink under a foreign lock.
        $this->assertSame('KEEP_MY_PRIVATE_APP_KEY', file_get_contents(
            $this->home.'/application/.env',
        ));
        $this->assertSame('PRIVATE_UPLOAD', file_get_contents(
            $this->home.'/application/storage/app/photo.txt',
        ));
    }

    public function test_changed_public_router_or_htaccess_blocks_unsafe_cutover(): void
    {
        file_put_contents($this->candidate.'/public_html/.htaccess', 'RewriteEngine Off');
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

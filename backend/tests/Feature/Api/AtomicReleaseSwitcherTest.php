<?php

namespace Tests\Feature\Api;

use App\Domain\Deployment\AtomicReleaseSwitcher;
use DomainException;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AtomicReleaseSwitcherTest extends TestCase
{
    private string $home;
    private string $releases;
    private string $current;
    private string $docroot;
    private string $old;
    private string $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-switch-'.bin2hex(random_bytes(8));
        $this->releases = $this->home.'/releases';
        $this->current = $this->home.'/current';
        $this->docroot = $this->home.'/public_html';
        $this->old = $this->releases.'/v1';
        $this->next = $this->releases.'/v2';
        self::assertTrue(mkdir($this->releases, 0700, true));
        $this->makeRelease($this->old, 'v1');
        $this->makeRelease($this->next, 'v2');
        self::assertTrue(symlink($this->old, $this->current));
        self::assertTrue(symlink($this->current.'/public_html', $this->docroot));
    }

    private function makeRelease(string $path, string $version): void
    {
        foreach (['application/bootstrap', 'application/vendor', 'public_html'] as $part) {
            self::assertTrue(mkdir($path.'/'.$part, 0700, true));
        }
        foreach (['application/bootstrap/app.php', 'application/vendor/autoload.php', 'public_html/index.php', 'public_html/.htaccess'] as $name) {
            file_put_contents($path.'/'.$name, $version);
        }
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
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    public function test_verified_backup_and_health_perform_real_atomic_code_cutover(): void
    {
        $calls = 0;
        $result = (new AtomicReleaseSwitcher())->switch(
            $this->releases, $this->current, $this->docroot, $this->next,
            function () use (&$calls): bool { $calls++; return true; },
            fn (): bool => file_get_contents($this->docroot.'/index.php') === 'v2',
        );
        self::assertSame(1, $calls);
        self::assertSame('switched', $result['state']);
        self::assertSame(realpath($this->next), realpath($this->current));
        self::assertSame('v2', file_get_contents($this->docroot.'/index.php'));
        self::assertSame('v1', file_get_contents($this->old.'/public_html/index.php'));
        self::assertSame($this->old, $result['previous']);
    }

    public function test_backup_failure_prevents_any_cutover(): void
    {
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static fn (): bool => false,
                static fn (): bool => true,
            );
            self::fail('Missing backup must fail closed.');
        } catch (DomainException) {
            self::assertSame($this->old, realpath($this->current));
            self::assertSame('v1', file_get_contents($this->docroot.'/index.php'));
        }
    }

    public function test_failed_health_rolls_pointer_back_to_original_code(): void
    {
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static fn (): bool => true,
                fn (): bool => file_get_contents($this->docroot.'/index.php') === 'v1',
            );
            self::fail('Failed health check must roll back.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('previous release restored', $error->getMessage());
            self::assertSame($this->old, realpath($this->current));
            self::assertSame('v1', file_get_contents($this->docroot.'/index.php'));
        }
    }

    public function test_real_health_exception_also_rolls_back(): void
    {
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static fn (): bool => true,
                static function (): bool { throw new RuntimeException('unhealthy'); },
            );
            self::fail('Health exception must roll back.');
        } catch (RuntimeException) {
            self::assertSame($this->old, realpath($this->current));
        }
    }

    public function test_physical_document_root_is_not_supported_and_never_overwritten(): void
    {
        @unlink($this->docroot);
        self::assertTrue(mkdir($this->docroot));
        file_put_contents($this->docroot.'/index.php', 'KEEP');
        $this->reject();
        self::assertSame('KEEP', file_get_contents($this->docroot.'/index.php'));
    }

    public function test_untrusted_candidate_outside_release_root_is_rejected(): void
    {
        $outside = $this->home.'/outside';
        $this->makeRelease($outside, 'vX');
        $this->reject($outside);
        self::assertSame($this->old, realpath($this->current));
    }

    public function test_replayed_update_to_current_version_is_rejected(): void
    {
        $this->reject($this->old);
    }

    public function test_missing_release_vendor_is_blocked_before_switch(): void
    {
        unlink($this->next.'/application/vendor/autoload.php');
        $this->reject();
        self::assertSame($this->old, realpath($this->current));
    }

    public function test_nested_release_symlink_is_blocked(): void
    {
        $external = $this->home.'/external';
        self::assertTrue(mkdir($external));
        file_put_contents($external.'/autoload.php', 'INJECTED');
        unlink($this->next.'/application/vendor/autoload.php');
        rmdir($this->next.'/application/vendor');
        self::assertTrue(symlink($external, $this->next.'/application/vendor'));
        $this->reject();
    }

    public function test_hard_linked_release_lock_denies_backup_and_pointer_swap(): void
    {
        $unrelated = $this->home.'/shared-release-lock';
        file_put_contents($unrelated, 'preserved');
        self::assertTrue(link($unrelated, $this->releases.'/.release-switch.lock'));
        $backupCalls = 0;

        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static function () use (&$backupCalls): bool {
                    ++$backupCalls;
                    return true;
                },
                static fn (): bool => true,
            );
            self::fail('A hard-linked release lock must not authorize cutover.');
        } catch (DomainException $failure) {
            self::assertStringContainsString('lock identity', $failure->getMessage());
        }

        self::assertSame(0, $backupCalls);
        self::assertSame('preserved', file_get_contents($unrelated));
        self::assertSame($this->old, realpath($this->current));
        self::assertSame('v1', file_get_contents($this->docroot.'/index.php'));
    }

    public function test_backup_callback_cannot_swap_release_lock_before_code_cutover(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX lock replacement fixture required.');
        }
        $lockPath = $this->releases.'/.release-switch.lock';
        $orphaned = $this->releases.'/.release-switch-old.lock';
        $calls = 0;

        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static function () use ($lockPath, $orphaned, &$calls): bool {
                    $calls++;
                    if (! rename($lockPath, $orphaned)
                        || file_put_contents($lockPath, 'replacement') === false) {
                        throw new RuntimeException('Failed to arrange lock swap test.');
                    }
                    return true;
                },
                static fn (): bool => true,
            );
            self::fail('A lock pathname swap must not authorize a release cutover.');
        } catch (DomainException $error) {
            self::assertStringContainsString('lock identity', $error->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame('replacement', file_get_contents($lockPath));
        self::assertSame($this->old, realpath($this->current));
        self::assertSame('v1', file_get_contents($this->docroot.'/index.php'));
        self::assertSame('v2', file_get_contents($this->next.'/public_html/index.php'));
    }

    public function test_health_callback_cannot_replace_live_pointer_and_claim_success(): void
    {
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static fn (): bool => true,
                function (): bool {
                    self::assertSame('v2', file_get_contents($this->docroot.'/index.php'));
                    // Simulate an independent deployment changing the current
                    // pointer while our external health callback is running.
                    self::assertTrue(unlink($this->current));
                    self::assertTrue(symlink($this->old, $this->current));
                    return true;
                },
            );
            self::fail('Changed current pointer must not be reported as a successful release.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('manual recovery required', $error->getMessage());
        }

        self::assertSame($this->old, realpath($this->current));
        self::assertSame('v1', file_get_contents($this->docroot.'/index.php'));
    }

    public function test_health_callback_replacing_lock_requires_manual_recovery_not_unsafe_rollback(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX lock replacement fixture required.');
        }
        $lockPath = $this->releases.'/.release-switch.lock';
        $orphaned = $this->releases.'/.orphaned-health-lock';
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static fn (): bool => true,
                function () use ($lockPath, $orphaned): bool {
                    self::assertSame('v2', file_get_contents($this->docroot.'/index.php'));
                    if (! rename($lockPath, $orphaned)
                        || file_put_contents($lockPath, 'replacement') === false) {
                        throw new RuntimeException('Cannot arrange health lock swap fixture.');
                    }
                    return true;
                },
            );
            self::fail('Lost lease must never produce a successful cutover receipt.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('manual recovery required', $error->getMessage());
        }
        self::assertSame('replacement', file_get_contents($lockPath));
        // No unsafe automatic rollback while a different process could now
        // hold the replacement lock. Operators must verify the live release.
        self::assertSame($this->next, realpath($this->current));
        self::assertSame('v2', file_get_contents($this->docroot.'/index.php'));
    }

    public function test_backup_callback_replacing_public_document_root_blocks_cutover(): void
    {
        $healthCalls = 0;
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                function (): bool {
                    self::assertTrue(unlink($this->docroot));
                    self::assertTrue(symlink($this->old.'/public_html', $this->docroot));
                    return true;
                },
                static function () use (&$healthCalls): bool {
                    ++$healthCalls;
                    return true;
                },
            );
            self::fail('Replaced public document root must not authorize any cutover.');
        } catch (DomainException $error) {
            self::assertStringContainsString('public document root', $error->getMessage());
        }
        self::assertSame(0, $healthCalls);
        self::assertSame($this->old, realpath($this->current));
        self::assertSame($this->old.'/public_html', realpath($this->docroot));
    }

    public function test_health_callback_replacing_public_document_root_requires_manual_recovery(): void
    {
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $this->next,
                static fn (): bool => true,
                function (): bool {
                    self::assertSame('v2', file_get_contents($this->docroot.'/index.php'));
                    self::assertTrue(unlink($this->docroot));
                    self::assertTrue(symlink($this->old.'/public_html', $this->docroot));
                    return true;
                },
            );
            self::fail('A changed public document root must not produce a successful cutover.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('manual recovery required', $error->getMessage());
        }
        // The original update lock does not authorize clobbering a public
        // symlink changed by another actor. Leave both paths for inspection.
        self::assertSame($this->next, realpath($this->current));
        self::assertSame($this->old.'/public_html', realpath($this->docroot));
        self::assertSame('v1', file_get_contents($this->docroot.'/index.php'));
    }

    private function reject(?string $candidate = null): void
    {
        try {
            (new AtomicReleaseSwitcher())->switch(
                $this->releases, $this->current, $this->docroot, $candidate ?? $this->next,
                static fn (): bool => true, static fn (): bool => true,
            );
            self::fail('Unsafe pointer cutover was allowed.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}

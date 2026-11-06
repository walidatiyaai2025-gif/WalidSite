<?php

namespace Tests\Unit;

use App\Domain\Deployment\CpanelReleaseMaintenance;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CpanelReleaseMaintenanceTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/walidsite-maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->home, 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            $items = scandir($this->home);
            foreach ($items ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    @unlink($this->home.'/'.$item);
                }
            }
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    public function test_atomic_barrier_requires_private_possession_and_exclusive_owner(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $session['token']);
        $this->assertGreaterThan(time(), $session['expires_at']);
        $path = $this->home.'/.walidsite-update-maintenance.json';
        $this->assertSame(0600, fileperms($path) & 0777);
        $text = file_get_contents($path);
        $this->assertStringNotContainsString($session['token'], $text);
        $this->assertStringContainsString(hash('sha256', $session['token']), $text);
        $maintenance->assertActive($this->home, $session['token'], 'legacy');
        try {
            $maintenance->begin($this->home, 'legacy', '0.1.1');
            $this->fail('Second update lease accepted.');
        } catch (DomainException) {
            $this->assertFileExists($path);
        }
        try {
            $maintenance->clear($this->home, str_repeat('0', 64));
            $this->fail('Foreign update owner cleared barrier.');
        } catch (DomainException) {
            $this->assertFileExists($path);
        }
        $maintenance->clear($this->home, $session['token']);
        $this->assertFileDoesNotExist($path);
    }

    public function test_expired_or_tampered_marker_rejects_maintenance_proof(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $path = $this->home.'/.walidsite-update-maintenance.json';
        $marker = json_decode((string) file_get_contents($path), true);
        $marker['expires_at'] = time() - 1;
        file_put_contents($path, json_encode($marker));
        $this->expectException(DomainException::class);
        $maintenance->assertActive($this->home, $session['token'], 'legacy');
    }

    public function test_active_maintenance_rejects_forged_previous_code_identity_with_valid_token(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $path = $this->home.'/.walidsite-update-maintenance.json';
        $original = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);

        foreach ([
            ['previous_version' => 'not-a-version'],
            ['previous_root' => '.walidsite-releases/v0.1.2-'.str_repeat('a', 40)],
            ['previous_root' => ['legacy']],
            ['probe_sha256' => ['invalid']],
        ] as $changes) {
            file_put_contents($path, json_encode(array_replace($original, $changes), JSON_THROW_ON_ERROR));
            try {
                $maintenance->assertActive($this->home, $session['token'], 'legacy');
                $this->fail('Malformed or inconsistent release lineage was accepted as an active maintenance lease.');
            } catch (DomainException) {
                $this->assertFileExists($path);
            }
        }

        file_put_contents($path, json_encode($original, JSON_THROW_ON_ERROR));
        $maintenance->assertActive($this->home, $session['token'], 'legacy');
        $maintenance->clear($this->home, $session['token']);
    }

    public function test_expired_lease_cannot_be_cleared_to_expose_unverified_new_code(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $path = $this->home.'/.walidsite-update-maintenance.json';

        // Simulate a pointer switched for a live health probe but its lease
        // expiring before the updater could finalize the barrier.
        file_put_contents(
            $this->home.'/.walidsite-active-release.json',
            '{"schema":1,"version":"0.1.2","root":".walidsite-releases/v0.1.2-'.str_repeat('a', 40).'"}',
        );
        $marker = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $marker['expires_at'] = time() - 1;
        file_put_contents($path, json_encode($marker, JSON_THROW_ON_ERROR));

        try {
            $maintenance->clear($this->home, $session['token']);
            $this->fail('Expired maintenance marker was removed, exposing unverified new code.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('recovery required', $error->getMessage());
        }
        $this->assertFileExists($path);
        $this->assertFileExists($this->home.'/.walidsite-active-release.json');
    }

    public function test_group_or_world_writable_cpanel_home_cannot_issue_a_maintenance_token(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $marker = $this->home.'/.walidsite-update-maintenance.json';

        foreach ([0770, 0702, 0777] as $mode) {
            $this->assertTrue(chmod($this->home, $mode));
            clearstatcache(true, $this->home);
            try {
                $maintenance->begin($this->home, 'legacy', '0.1.1');
                $this->fail('An account home writable by another principal authorized release maintenance.');
            } catch (DomainException $error) {
                $this->assertStringContainsString('home', $error->getMessage());
                $this->assertFileDoesNotExist($marker);
            } finally {
                chmod($this->home, 0700);
                clearstatcache(true, $this->home);
            }
        }
    }

    public function test_private_home_permission_regression_revokes_active_and_clear_authority(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $marker = $this->home.'/.walidsite-update-maintenance.json';

        $this->assertTrue(chmod($this->home, 0777));
        clearstatcache(true, $this->home);
        try {
            try {
                $maintenance->assertActive($this->home, $session['token'], 'legacy');
                $this->fail('Maintenance proof accepted a newly writable account home.');
            } catch (DomainException) {
                $this->assertFileExists($marker);
            }
            try {
                $maintenance->clear($this->home, $session['token']);
                $this->fail('An unsafe account home allowed the maintenance barrier to be cleared.');
            } catch (DomainException) {
                $this->assertFileExists($marker);
            }
        } finally {
            chmod($this->home, 0700);
            clearstatcache(true, $this->home);
        }

        $maintenance->assertActive($this->home, $session['token'], 'legacy');
        $maintenance->clear($this->home, $session['token']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_real_live_probe_token_only_authorizes_private_unchanged_maintenance_identity(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $maintenance->assertLiveHealthProbe($this->home, $session['token']);

        foreach ([str_repeat('0', 64), 'invalid', ''] as $wrong) {
            try {
                $maintenance->assertLiveHealthProbe($this->home, $wrong);
                $this->fail('Foreign or malformed token was accepted as a live release probe.');
            } catch (DomainException) {
                // No change to the private lease or previously pinned code.
                $this->assertFileExists($this->home.'/.walidsite-update-maintenance.json');
            }
        }

        $maintenance->clear($this->home, $session['token']);
        $this->expectException(DomainException::class);
        $maintenance->assertLiveHealthProbe($this->home, $session['token']);
    }

    public function test_live_health_probe_rejects_tampered_version_or_expired_barrier(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $path = $this->home.'/.walidsite-update-maintenance.json';
        $original = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $tampered = $original;
        $tampered['previous_version'] = 'not-a-version';
        file_put_contents($path, json_encode($tampered, JSON_THROW_ON_ERROR));
        try {
            $maintenance->assertLiveHealthProbe($this->home, $session['token']);
            $this->fail('Malformed previous release identity authorized a health probe.');
        } catch (DomainException) {
            $this->assertFileExists($path);
        }

        $original['expires_at'] = time() - 1;
        file_put_contents($path, json_encode($original, JSON_THROW_ON_ERROR));
        try {
            $maintenance->assertLiveHealthProbe($this->home, $session['token']);
            $this->fail('An expired health token must not reopen the new release.');
        } catch (DomainException) {
            $this->assertFileExists($path);
        }
    }

    public function test_unknown_release_identity_and_untrusted_link_are_forbidden(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $this->expectException(DomainException::class);
        $maintenance->begin($this->home, '../public_html', '0.1.1');
    }

    public function test_private_marker_link_is_not_followed_or_overwritten(): void
    {
        if (! function_exists('symlink') || ! @symlink('/dev/null', $this->home.'/.walidsite-update-maintenance.json')) {
            $this->markTestSkipped('Symlinks unavailable.');
        }
        $this->expectException(DomainException::class);
        (new CpanelReleaseMaintenance())->begin($this->home, 'legacy', '0.1.1');
    }

    public function test_live_release_previous_root_must_match_previous_version(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $this->expectException(DomainException::class);
        $maintenance->begin($this->home, '.walidsite-releases/v0.1.2-'.str_repeat('a', 40), '0.1.1');
    }
    public function test_hardlinked_lease_is_never_accepted_or_cleared(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $path = $this->home.'/.walidsite-update-maintenance.json';
        $alias = $this->home.'/.unauthorized-lease-alias';
        if (! @link($path, $alias)) {
            $this->markTestSkipped('Hard links unavailable.');
        }

        try {
            try {
                $maintenance->assertActive($this->home, $session['token'], 'legacy');
                $this->fail('Multi-linked maintenance marker was accepted.');
            } catch (DomainException) {
                $this->assertFileExists($path);
            }
            try {
                $maintenance->clear($this->home, $session['token']);
                $this->fail('Multi-linked maintenance marker was cleared.');
            } catch (DomainException) {
                $this->assertFileExists($path);
            }
        } finally {
            unlink($alias);
        }
        $maintenance->assertActive($this->home, $session['token'], 'legacy');
        $maintenance->clear($this->home, $session['token']);
    }

    public function test_loosened_private_lease_permissions_fail_closed(): void
    {
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $path = $this->home.'/.walidsite-update-maintenance.json';
        chmod($path, 0644);
        clearstatcache(true, $path);

        try {
            $maintenance->assertActive($this->home, $session['token'], 'legacy');
            $this->fail('Readable maintenance marker was accepted.');
        } catch (DomainException) {
            $this->assertFileExists($path);
        }
        try {
            $maintenance->clear($this->home, $session['token']);
            $this->fail('Readable maintenance marker was cleared.');
        } catch (DomainException) {
            $this->assertFileExists($path);
        }
        chmod($path, 0600);
        $maintenance->assertActive($this->home, $session['token'], 'legacy');
        $maintenance->clear($this->home, $session['token']);
    }

    public function test_maintenance_clear_rejects_symlinked_account_home(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks unavailable.');
        }
        $maintenance = new CpanelReleaseMaintenance();
        $session = $maintenance->begin($this->home, 'legacy', '0.1.1');
        $alias = sys_get_temp_dir().'/walidsite-home-alias-'.bin2hex(random_bytes(6));
        if (! @symlink($this->home, $alias)) {
            $this->markTestSkipped('Symlinks unavailable.');
        }
        try {
            $this->expectException(DomainException::class);
            $maintenance->clear($alias, $session['token']);
        } finally {
            unlink($alias);
        }
    }

}

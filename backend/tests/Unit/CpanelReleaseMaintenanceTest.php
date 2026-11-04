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
}

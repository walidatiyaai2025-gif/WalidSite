<?php

namespace Tests\Feature\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FirstRunEntryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function enableUninstalledSite(): string
    {
        $path = storage_path('app/first-run-entry-'.bin2hex(random_bytes(12)).'.lock');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('s', 64),
            'site.setup_database_ready' => false,
            'site.install_lock_path' => $path,
        ]);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    public function test_first_visit_opens_full_installer_and_explicit_legacy_recovery_remains_available(): void
    {
        $this->enableUninstalledSite();

        // After a single cPanel ZIP extraction, the owner opens only the domain:
        // never strand the owner in the obsolete four-step setup wizard.
        $this->get('https://localhost/')->assertRedirect(route('site.install'));
        $this->get('https://localhost/admin/login')->assertRedirect(route('site.install'));
        $this->get('https://localhost/install')
            ->assertOk()
            ->assertSee('WalidSite Installation')
            ->assertSee('name="token"', false)
            ->assertDontSee(str_repeat('s', 64));

        // An interrupted older /setup installation remains an explicit,
        // token-gated recovery path, not the default new-install entrypoint.
        $this->get('https://localhost/setup')
            ->assertOk()
            ->assertSee('name="token"', false);
    }

    public function test_uninstalled_site_blocks_non_setup_writes_and_preserves_lock_after_install(): void
    {
        $lock = $this->enableUninstalledSite();

        // Disallow pre-install mutation even when a route would otherwise
        // accept POST; never create uninitialized site content or accounts.
        $this->post('https://localhost/contact', [])->assertStatus(503);

        file_put_contents($lock, "installed\n");
        $this->get('https://localhost/admin/login')->assertOk();
        $this->get('https://localhost/install')->assertNotFound();
        $this->get('https://localhost/setup')->assertNotFound();
    }
}

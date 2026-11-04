<?php

namespace Tests\Feature\Web;

use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class FoodexInstallWizardTest extends TestCase
{
    use RefreshDatabase;

    private function enableFreshInstall(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('k', 40),
            'site.setup_database_ready' => false,
            'site.install_lock_path' => storage_path('app/foodex-install-lock-'.bin2hex(random_bytes(8))),
        ]);
    }

    public function test_new_installer_is_closed_by_default_and_requires_private_possession(): void
    {
        $this->get('https://localhost/install')->assertNotFound();
        $this->enableFreshInstall();

        $this->get('https://localhost/install')->assertOk()
            ->assertSee('WalidSite Installation')
            ->assertSee('Step')
            ->assertSee('15')
            ->assertSee('name="token"', false)
            ->assertDontSee(str_repeat('k', 40));

        $this->post('https://localhost/install/step/2')->assertForbidden();
        $this->post('https://localhost/install/step/1', ['token' => 'wrong'])
            ->assertSessionHasErrors('install');
        $this->assertFalse(session('site_setup_authorized') === true);
        $this->post('https://localhost/install/step/1', ['token' => str_repeat('k', 40)])
            ->assertRedirect();
        $this->assertSame(2, session('foodex_install_step'));
        $this->assertTrue(session('site_setup_authorized'));

        $this->post('https://localhost/install/step/4')->assertStatus(409);
        $this->get('https://localhost/install?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('متطلبات الخادم')
            ->assertSee('المرحلة')
            ->assertDontSee(str_repeat('k', 40));
    }

    public function test_host_preflight_gate_is_sequential_and_does_not_accept_plain_http(): void
    {
        $this->enableFreshInstall();
        $this->get('http://walidatiya.com/install')->assertForbidden();
        $this->withSession([
            'site_setup_authorized' => true,
            'foodex_install_step' => 2,
        ]);
        $this->get('https://localhost/install')->assertOk()
            ->assertSee('data-testid="foodex-host-checks"', false)
            ->assertSee('PHP 8.3');
        $this->post('https://localhost/install/step/2')->assertRedirect();
        $this->assertSame(3, session('foodex_install_step'));
        $this->post('https://localhost/install/step/3')->assertRedirect();
        $this->assertSame(4, session('foodex_install_step'));
    }

    public function test_super_admin_credentials_are_prepared_as_a_hash_not_plaintext(): void
    {
        $this->enableFreshInstall();
        $email = app(SiteInstaller::class)->ownerEmail();
        $password = 'Long-UniquePassword-734!';
        $this->withSession([
            'site_setup_authorized' => true,
            'foodex_install_step' => 7,
        ]);
        $this->post('https://localhost/install/step/7', [
            'admin_email' => 'attacker@example.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])->assertSessionHasErrors('install');
        $this->assertSame(7, session('foodex_install_step'));

        $this->post('https://localhost/install/step/7', [
            'admin_email' => $email,
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])->assertRedirect();

        $hash = session('foodex_install_owner_hash');
        $this->assertIsString($hash);
        $this->assertNotSame($password, $hash);
        $this->assertTrue(Hash::check($password, $hash));
        $this->assertSame(8, session('foodex_install_step'));
    }

    public function test_platform_settings_only_write_whitelisted_private_env_fields(): void
    {
        $this->enableFreshInstall();
        $path = storage_path('app/wizard-environment-'.bin2hex(random_bytes(8)));
        file_put_contents($path, "APP_KEY=\"base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\"\n");
        $this->beforeApplicationDestroyed(static function () use ($path): void { @unlink($path); });
        config(['site.environment_path' => $path]);
        $this->withSession(['site_setup_authorized' => true, 'foodex_install_step' => 6]);
        $this->post('https://localhost/install/step/6', [
            'app_name' => 'My WalidSite',
            'app_locale' => 'en',
        ])->assertRedirect();
        $this->assertSame(7, session('foodex_install_step'));
        $text = file_get_contents($path);
        $this->assertStringContainsString('APP_NAME="My WalidSite"', $text);
        $this->assertStringContainsString('APP_LOCALE="en"', $text);
        $this->assertStringContainsString('APP_KEY="base64:AAAAAAAA', $text);
        $this->assertStringNotContainsString('DB_PASSWORD', $text);
    }

    public function test_installer_does_not_allow_reopening_after_activation(): void
    {
        $this->enableFreshInstall();
        $lock = (string) config('site.install_lock_path');
        file_put_contents($lock, 'already installed');
        try {
            $this->get('https://localhost/install')->assertNotFound();
            $this->post('https://localhost/install/step/1', [
                'token' => str_repeat('k', 40),
            ])->assertNotFound();
        } finally {
            @unlink($lock);
        }
    }
}

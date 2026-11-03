<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Support\Deployment\DatabaseConfiguration;
use App\Support\Deployment\SiteInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

final class SetupArabicContinuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_one_browser_install_keeps_locale_and_locks_the_activated_site(): void
    {
        $token = str_repeat('a', 40);
        $id = bin2hex(random_bytes(8));
        $lock = storage_path('app/setup-arabic-'.$id.'.lock');
        $environment = storage_path('app/setup-arabic-'.$id.'.env');
        $bootstrap = storage_path('app/setup-arabic-'.$id.'.json');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => true,
            'site.install_lock_path' => $lock,
            'site.environment_path' => $environment,
            'site.bootstrap_path' => $bootstrap,
        ]);

        try {
            $this->get('/setup?lang=ar')->assertOk()
                ->assertSee('dir="rtl"', false)
                ->assertSee('action="'.e(route('site.setup.unlock', ['lang' => 'ar'])).'"', false)
                ->assertDontSee($token);

            $this->post('/setup/unlock?lang=ar', ['token' => $token])
                ->assertRedirect('/setup?lang=ar');
            $this->get('/setup?lang=ar')->assertOk()
                ->assertSee('action="'.e(route('site.setup.database', ['lang' => 'ar'])).'"', false)
                ->assertSee('الترحيلات المنتظرة');

            $this->post('/setup/database?lang=ar')->assertRedirect('/setup?lang=ar');
            $this->get('/setup?lang=ar')->assertOk()
                ->assertSee('تفعيل حساب المالك')
                ->assertSee('action="'.e(route('site.setup.finish', ['lang' => 'ar'])).'"', false);

            $password = 'Owner-Arabic-First-Install-1234!';
            $this->post('/setup/finish?lang=ar', [
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertRedirect('/admin/login?lang=ar')
                ->assertSessionHas('status', 'اكتمل التثبيت. سجّل الدخول باستخدام بريد المالك.');

            $owner = User::where('email', app(SiteInstaller::class)->ownerEmail())->firstOrFail();
            $this->assertSame('active', $owner->status);
            $this->assertTrue($owner->hasRole('owner'));
            $this->assertTrue(Hash::check($password, $owner->password_hash));
            $this->assertFileExists($lock);
            $this->get('/setup?lang=ar')->assertNotFound();
        } finally {
            @unlink($lock);
            @unlink($environment);
            @unlink($bootstrap);
        }
    }

    public function test_arabic_database_configuration_preserves_step_and_never_puts_secrets_in_redirect(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('b', 40),
            'site.setup_database_ready' => false,
        ]);
        $database = Mockery::mock(DatabaseConfiguration::class)->makePartial();
        $database->shouldReceive('probe')->once()->andReturn(true);
        $database->shouldReceive('save')->once()
            ->with(Mockery::type('array'), 'https://walidsite.example.org');
        $this->app->instance(DatabaseConfiguration::class, $database);
        $this->withSession(['site_setup_authorized' => true]);

        $this->get('/setup?lang=ar')->assertOk()
            ->assertSee('action="'.e(route('site.setup.configure', ['lang' => 'ar'])).'"', false)
            ->assertSee('إعداد قاعدة البيانات');

        $response = $this->post('/setup/configure?lang=ar', [
            'site_url' => 'https://walidsite.example.org',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => 'new_walidsite',
            'db_username' => 'database_user',
            'db_password' => 'never-in-query-or-output',
        ])->assertRedirect('/setup?lang=ar');

        $this->assertStringNotContainsString('never-in-query-or-output', (string) $response->headers->get('Location'));
        $response->assertSessionMissing('_old_input.db_password');
    }

    public function test_invalid_arabic_token_and_database_fields_remain_private_and_actionable(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('c', 40),
            'site.setup_database_ready' => false,
        ]);
        $this->from('/setup?lang=ar')->post('/setup/unlock?lang=ar', [
            'token' => 'wrong-private-token',
        ])->assertRedirect('/setup?lang=ar')
            ->assertSessionHasErrors('token')
            ->assertSessionMissing('_old_input.token');
        $this->assertSame('رمز الإعداد غير صحيح.', session('errors')->first('token'));

        $this->post('/setup/unlock?lang=ar', ['token' => str_repeat('c', 40)])
            ->assertRedirect('/setup?lang=ar');
        $this->from('/setup?lang=ar')->post('/setup/configure?lang=ar', [
            'site_url' => 'https://walidsite.example.org',
            'db_host' => 'localhost',
            'db_port' => 70000,
            'db_database' => 'new_walidsite',
            'db_username' => 'database_user',
            'db_password' => 'sensitive-database-secret',
        ])->assertRedirect('/setup?lang=ar')
            ->assertSessionHasErrors('db_port')
            ->assertSessionMissing('_old_input.db_password');
        $this->assertStringContainsString('منفذ قاعدة البيانات', session('errors')->first('db_port'));
        $this->get('/setup?lang=ar')->assertOk()
            ->assertDontSee('sensitive-database-secret')
            ->assertDontSee('wrong-private-token');
    }

    public function test_english_default_actions_and_malformed_locale_remain_canonical(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('d', 40),
            'site.setup_database_ready' => false,
        ]);
        $this->get('/setup?lang=en')->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('action="'.e(route('site.setup.unlock')).'"', false);
        $this->get('/setup?lang%5B%5D=ar')->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('action="'.e(route('site.setup.unlock')).'"', false);
        $this->post('/setup/unlock', ['token' => str_repeat('d', 40)])
            ->assertRedirect('/setup');
        $this->get('/setup')->assertOk()
            ->assertSee('action="'.e(route('site.setup.configure')).'"', false);
    }
}

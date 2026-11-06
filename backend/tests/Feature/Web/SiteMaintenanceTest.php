<?php

namespace Tests\Feature\Web;

use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Models\Role;
use App\Models\User;
use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_is_not_public_by_default(): void
    {
        $this->get('/setup')->assertNotFound();
        $this->get('/')->assertOk();
    }

    public function test_first_visit_opens_full_installer_and_legacy_setup_stays_token_gated(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('x', 40),
            'site.setup_database_ready' => true,
        ]);

        // Default first visit uses the 15-stage installer; /setup is recovery only.
        $this->get('/')->assertRedirect('/install');
        $this->get('/setup?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('name="token"', false)
            ->assertDontSee('Step 3: Activate owner account');

        $this->post('/setup/database')->assertForbidden();
        $this->post('/setup/unlock', ['token' => 'wrong'])->assertSessionHasErrors('token');
        $this->post('/setup/unlock', ['token' => str_repeat('x', 40)])
            ->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Pending migrations');
        $this->post('/setup/database')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Activate owner account');
    }


    public function test_legacy_setup_denies_migrations_if_database_is_filled_after_configuration(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('r', 40),
            'site.setup_database_ready' => true,
            'site.install_lock_path' => storage_path('app/absent-setup-'.bin2hex(random_bytes(10))),
        ]);
        DB::table('migrations')->delete();
        $this->withSession(['site_setup_authorized' => true]);
        $this->post('/setup/database?lang=en')
            ->assertRedirect()
            ->assertSessionHasErrors([
                'setup' => 'The selected database now contains existing tables without trusted migration history. Select an empty database or recover the interrupted install; no migrations were run.',
            ]);
        self::assertSame(0, DB::table('migrations')->count());
        $this->assertFalse(session('site_setup_migrated') === true);
    }

    public function test_setup_wizard_exposes_exactly_one_current_step_in_arabic_and_english(): void
    {
        $token = str_repeat('p', 40);
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => false,
        ]);

        // Read real GET responses at every state rather than asserting against
        // the source template or manufacturing visual evidence.
        $assertStage = function (int $number, array $headings, array $labels): void {
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
                $response = $this->get('/setup?lang='.$lang)->assertOk()
                    ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                    ->assertSee($headings[$lang]);
                $html = $response->getContent();
                // Count only rendered step elements, not the CSS rule
                // that styles [aria-current="step"] in the same response.
                $matches = [];
                preg_match_all('/<li[^>]*aria-current="step"[^>]*>(.*?)<\/li>/us', $html, $matches);
                $this->assertCount(1, $matches[1]);
                $this->assertSame([$number.' — '.$labels[$lang]],
                    array_map('trim', $matches[1]));
            }
        };

        $assertStage(1,
            ['en' => 'Step 1: Server setup token', 'ar' => 'الخطوة 1: رمز الإعداد السري'],
            ['en' => 'Verify', 'ar' => 'التحقق']);

        $this->post('/setup/unlock', ['token' => $token])->assertRedirect('/setup');
        $assertStage(2,
            ['en' => 'Step 2: Configure database', 'ar' => 'الخطوة 2: إعداد قاعدة البيانات'],
            ['en' => 'Database', 'ar' => 'قاعدة البيانات']);

        // A configured database changes the page to its migration stage;
        // it does not re-label the configuration form as another Step 2.
        config(['site.setup_database_ready' => true]);
        $assertStage(3,
            ['en' => 'Step 3: Database migrations', 'ar' => 'الخطوة 3: ترحيلات قاعدة البيانات'],
            ['en' => 'Migrations', 'ar' => 'الترحيلات']);

        $this->post('/setup/database')->assertRedirect('/setup');
        $assertStage(4,
            ['en' => 'Step 4: Activate owner account', 'ar' => 'الخطوة 4: تفعيل حساب المالك'],
            ['en' => 'Owner', 'ar' => 'المالك']);
    }


    public function test_full_install_activates_only_canonical_cv_owner_and_permanently_locks_setup(): void
    {
        $token = str_repeat('s', 40);
        $path = storage_path('app/site-install-test-'.bin2hex(random_bytes(8)).'.lock');
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.install_lock_path' => $path,
            'site.setup_database_ready' => true,
            'site.environment_path' => storage_path('app/site-test-environment'),
            'site.bootstrap_path' => storage_path('app/site-test-bootstrap'),
        ]);

        try {
            $this->post('/setup/unlock', ['token' => $token])->assertRedirect('/setup');
            $this->post('/setup/database')->assertRedirect('/setup');

            $password = 'An-Independent-Test-Password-123!';
            $this->post('/setup/finish', [
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertRedirect('/admin/login');

            $ownerEmail = app(SiteInstaller::class)->ownerEmail();
            $owner = User::where('email', $ownerEmail)->firstOrFail();
            $this->assertSame('active', $owner->status);
            $this->assertTrue($owner->hasRole('owner'));
            $this->assertTrue(Hash::check($password, $owner->password_hash));
            $this->assertFileExists($path);
            // Real browser Setup also seeds CMS fields in the same atomic
            // owner activation, rather than requiring a second CLI command.
            $this->assertGreaterThan(150, DB::table('site_page_texts')->count());
            $this->assertSame('Enterprise Technology & AI Solutions Architecture',
                DB::table('site_page_texts')
                    ->where('page_key','home')->where('section_key','hero')
                    ->where('field_key','tagline')->where('locale','en')
                    ->value('published_text'));
            $this->assertNull(DB::table('site_page_texts')
                ->where('page_key','expertise')->where('section_key','ai')
                ->where('field_key','hero_description')->where('locale','en')
                ->value('published_text'));
            $this->assertNotNull(DB::table('site_page_texts')
                ->where('page_key','expertise')->where('section_key','ai')
                ->where('field_key','hero_description')->where('locale','en')
                ->value('draft_text'));
            $this->get('/setup')->assertNotFound();
            $this->get('/')->assertOk();
        } finally {
            @unlink($path);
            @unlink((string) config('site.environment_path'));
        }
    }

    public function test_setup_is_locked_when_an_owner_already_exists_even_without_marker(): void
    {
        config(['site.setup_enabled' => true, 'site.setup_token' => str_repeat('t', 40)]);
        $owner = User::create([
            'email' => 'protected@example.test',
            'password_hash' => 'already-configured',
            'status' => 'active',
        ]);
        $role = Role::firstOrCreate(['name' => 'owner']);
        $owner->roles()->attach($role->id);

        $this->get('/setup')->assertNotFound();
        $this->get('/')->assertOk();
    }

    public function test_missing_install_marker_does_not_reopen_setup_for_suspended_owner_or_revoked_admin(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('k', 40),
            'site.install_lock_path' => storage_path('app/site-test-absent-'.bin2hex(random_bytes(8)).'.lock'),
        ]);

        $owner = User::create([
            'email' => 'suspended-owner@example.test',
            'password_hash' => Hash::make('An-existing-owner-password-123!'),
            'status' => 'suspended',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        $this->assertTrue(app(SiteInstaller::class)->isInstalled());
        $this->get('/setup')->assertNotFound();
        $this->get('/')->assertOk();
        $this->post('/setup/unlock', ['token' => str_repeat('k', 40)])
            ->assertNotFound();

        // Revoking the role cannot unlock setup while an existing account
        // still has a password. First-install credentials must never
        // overwrite or reset an old/suspended user's database.
        $owner->roles()->detach();
        $this->assertTrue(app(SiteInstaller::class)->isInstalled());
        $this->get('/setup?lang=ar')->assertNotFound();
        $this->post('/setup/database')->assertNotFound();
        $this->post('/setup/finish', [
            'password' => 'Unexpected-new-owner-password-123!',
            'password_confirmation' => 'Unexpected-new-owner-password-123!',
        ])->assertNotFound();
    }

    public function test_unactivated_owner_placeholder_without_credentials_does_not_block_initial_setup(): void
    {
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('p', 40),
            'site.install_lock_path' => storage_path('app/site-test-absent-'.bin2hex(random_bytes(8)).'.lock'),
        ]);
        // The approved CV seeder may prepare a non-active identity without
        // a password. The guard must not treat that draft as a prior install.
        User::create([
            'email' => 'placeholder@example.test',
            'password_hash' => null,
            'status' => 'pending',
        ]);

        $this->assertFalse(app(SiteInstaller::class)->isInstalled());
        $this->get('/setup')->assertOk()->assertSee('Server setup token');
    }

    public function test_update_center_is_owner_only_and_reports_real_migration_status(): void
    {
        $this->get('/admin/updates')->assertRedirect('/admin/login');
        $user = User::create([
            'email' => 'regular@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($adminRole->id);
        $this->actingAs($user);
        $this->get('/admin/updates')->assertForbidden();
        $this->post('/admin/updates/apply')->assertForbidden();

        $ownerRole = Role::firstOrCreate(['name' => 'owner']);
        $user->roles()->attach($ownerRole->id);
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الترحيلات المنتظرة');
        $this->assertTrue(app(SiteUpdater::class)->inspect()['ready']);
        $this->post('/admin/updates/apply')->assertRedirect();
    }


    public function test_production_setup_denies_all_plain_http_steps_and_accepts_https_unlock(): void
    {
        $priorEnvironment = $this->app['env'];
        $token = str_repeat('h', 40);
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => $token,
            'site.setup_database_ready' => true,
            'site.install_lock_path' => storage_path('app/setup-https-test-uncreated.lock'),
        ]);

        // The production web stack enforces CSRF before route middleware.
        // Supply a real session token so transport denials are exercised
        // rather than receiving an unrelated 419 CSRF rejection.
        $csrf = str_repeat('c', 40);
        $this->withSession(['_token' => $csrf]);
        $this->app->instance('env', 'production');

        try {
            $this->get('http://localhost/setup')->assertForbidden();
            $this->post('http://localhost/setup/unlock', ['token' => $token, '_token' => $csrf])
                ->assertForbidden();
            $this->post('http://localhost/setup/configure', [
                'db_password' => 'would-be-exposed', '_token' => $csrf,
            ])->assertForbidden();
            $this->post('http://localhost/setup/database', ['_token' => $csrf])->assertForbidden();
            $this->post('http://localhost/setup/finish', [
                'password' => 'Example-Owner-Password-1234',
                'password_confirmation' => 'Example-Owner-Password-1234',
                '_token' => $csrf,
            ])->assertForbidden();

            // Correctly secured requests still reach the normal wizard.
            $this->get('https://localhost/setup')->assertOk()
                ->assertSee('Server setup token');
            $this->post('https://localhost/setup/unlock', ['token' => $token, '_token' => $csrf])
                ->assertRedirect('/setup');
            $this->get('https://localhost/setup')->assertOk();

        } finally {
            $this->app->instance('env', $priorEnvironment);
        }
    }

    public function test_disabled_or_finished_installer_is_hidden_over_plain_http_in_production(): void
    {
        $priorEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => str_repeat('c', 40)]);
        config([
            'site.setup_enabled' => false,
            'site.setup_token' => str_repeat('h', 40),
        ]);

        try {
            // A rejected transport must not make a closed wizard visible
            // (403). Historical behavior and the installation contract are
            // consistently 404 on HTTP and HTTPS.
            $this->get('http://localhost/setup')->assertNotFound();
            $this->get('https://localhost/setup')->assertNotFound();
            foreach (['/setup/unlock', '/setup/configure', '/setup/database', '/setup/finish'] as $path) {
                $this->post('http://localhost'.$path, ['_token' => session()->token()])
                    ->assertNotFound();
            }

            // A completed installation stays undiscoverable even if the
            // configuration toggle is accidentally left enabled.
            config(['site.setup_enabled' => true]);
            $owner = User::create([
                'email' => 'setup-installed@example.test',
                'password_hash' => 'test-already-activated',
                'status' => 'active',
            ]);
            $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
            $this->get('http://localhost/setup')->assertNotFound();
            $this->get('https://localhost/setup')->assertNotFound();
            $this->post('http://localhost/setup/finish', [
                '_token' => session()->token(), 'password' => 'not-accepted',
            ])->assertNotFound();
        } finally {
            $this->app->instance('env', $priorEnvironment);
        }
    }

    public function test_owner_migration_failure_never_reports_driver_secret_or_exception_message(): void
    {
        $owner = User::create([
            'email' => 'migration-redaction-owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);
        config(['site.updates_enabled' => true]);

        // Simulate an exception whose driver message embeds an environment
        // secret. Only its type may reach private logs or browser flash.
        $secret = 'PRIVATE-DB-PASSWORD-MUST-NOT-LOG';
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->with('walidsite:trusted-migrations', 600)
            ->once()
            ->andThrow(new \RuntimeException($secret));
        \Illuminate\Support\Facades\Log::spy();

        $this->post('/admin/updates/apply')
            ->assertRedirect()
            ->assertSessionHasErrors('updates');
        $message = session('errors')->first('updates');
        $this->assertStringNotContainsString($secret, $message);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->with('admin.updates.migration_blocked', ['type' => \RuntimeException::class])
            ->once();
    }

    public function test_owner_recovery_history_is_read_only_and_hidden_from_guests(): void
    {
        $this->get('/admin/updates?lang=en')->assertRedirect('/admin/login');
        $owner = User::create([
            'email' => 'history-owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);

        foreach (['en' => 'Backup & recovery history', 'ar' => 'سجل النسخ الاحتياطي واختبار الاستعادة'] as $lang => $heading) {
            $this->get('/admin/updates?lang='.$lang)->assertOk()
                ->assertSee($heading)
                ->assertSee('data-testid="owner-recovery-history"', false)
                ->assertSee('data-testid="trusted-code-update-unavailable"', false)
                ->assertDontSee('APP_KEY=')
                ->assertDontSee('PRIVATE-DB-PASSWORD');
        }
        // Receipt history never becomes an additional unverified Apply route.
        $this->post('/admin/updates/recovery-history')->assertNotFound();
    }

    public function test_owner_backup_now_fails_closed_without_independent_host_adapter(): void
    {
        // Test the identity/host guard independently of the production 1/5-minute limit.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post('/admin/updates/backup-now')->assertRedirect('/admin/login');
        $user = User::create([
            'email' => 'backup-host-gate@example.test',
            'password_hash' => 'unused', 'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);
        $this->actingAs($user)->post('/admin/updates/backup-now')->assertForbidden();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        config(['site.updates_enabled' => true]);
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('data-testid="owner-backup-now-form"', false)
            ->assertSee('data-testid="owner-host-backup-provider-unavailable"', false)
            ->assertSee('data-testid="owner-backup-now"', false)
            ->assertSee('disabled', false);
        $this->post('/admin/updates/backup-now')->assertRedirect()->assertSessionHasErrors('backup');
        $this->assertNull(session('owner_backup_receipt'));
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('نسخة احتياطية مع اختبار الاستعادة');
    }

    public function test_host_adapter_cannot_accept_false_writer_isolation_as_backup_evidence(): void
    {
        // Test the identity/host guard independently of the production 1/5-minute limit.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $user = User::create([
            'email' => 'backup-rejected-owner@example.test',
            'password_hash' => 'unused', 'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($user);
        config(['site.updates_enabled' => true]);
        $host = new class implements \App\Domain\Deployment\CpanelOwnerBackupHost {
                public int $calls = 0;
                public function externalWritersStopped(): bool
                {
                    $this->calls++;
                    return false;
                }
                public function disposableRestoreConnection(): \PDO
                {
                    throw new \RuntimeException('A false writer guard must never touch the restore database.');
                }
            };
        $this->app->instance(\App\Domain\Deployment\CpanelOwnerBackupHost::class, $host);
        // Running under the test/application tree, not the approved physical
        // cPanel layout, must fail before contacting the host implementation.
        $this->post('/admin/updates/backup-now')
            ->assertRedirect()->assertSessionHasErrors('backup');
        $this->assertNull(session('owner_backup_receipt'));
        $this->assertSame(0, $host->calls);
    }

    public function test_owner_signed_code_install_requires_verified_stage_and_host_capability(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post('/admin/updates/install-signed')->assertRedirect('/admin/login');

        $user = User::create([
            'email' => 'signed-install-owner@example.test',
            'password_hash' => 'unused', 'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);
        $this->actingAs($user)
            ->post('/admin/updates/install-signed')->assertForbidden();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        config(['site.updates_enabled' => true]);

        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('data-testid="owner-install-host-blocked"', false)
            ->assertDontSee('data-testid="owner-signed-install-form"', false);
        $this->post('/admin/updates/install-signed')
            ->assertRedirect()->assertSessionHasErrors('install');
        $this->assertNull(session('owner_update_installed'));
        $this->post('/admin/updates/install-signed?lang=en', [
            'version' => '9.9.9', 'backup_verified' => 'true',
        ])->assertRedirect()->assertSessionHasErrors('install');
        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('تثبيت التحديث — الاستضافة أو الاستعادة غير جاهزة');
    }

    public function test_owner_code_install_reauthenticates_current_password_before_any_host_operation(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $password = 'Strong-Owner-Install-Passphrase-2026';
        $owner = User::create([
            'email' => 'owner-reauth-install@example.test',
            'password_hash' => $password, 'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        config(['site.updates_enabled' => true]);
        $this->actingAs($owner);

        // An authenticated owner browser session is not sufficient to
        // install executable code, even if signed staging was attempted.
        $this->post('/admin/updates/install-signed')
            ->assertRedirect()->assertSessionHasErrors('install');
        $this->assertSame('Owner password confirmation failed.',
            session('errors')->first('install'));

        $this->post('/admin/updates/install-signed', ['current_password' => 'wrong'])
            ->assertRedirect()->assertSessionHasErrors('install');
        $this->assertSame('Owner password confirmation failed.',
            session('errors')->first('install'));

        $this->post('/admin/updates/install-signed', [
            'current_password' => ['malformed'],
        ])->assertRedirect()->assertSessionHasErrors('install');
        $this->assertSame('Owner password confirmation failed.',
            session('errors')->first('install'));

        // Fresh and correct credentials pass this reauthentication gate,
        // but do NOT bypass the independently required signed ZIP, stage,
        // writer isolation, disposable database and host health checks.
        $this->post('/admin/updates/install-signed', [
            'current_password' => $password,
        ])->assertRedirect()->assertSessionHasErrors('install');
        $this->assertStringContainsString('BLOCKED HOSTING:',
            session('errors')->first('install'));

        // The password is never saved to the session or echoed to the UI.
        $this->assertNull(session('current_password'));
        $this->assertNull(session('owner_update_installed'));

        $this->post('/admin/updates/install-signed', [
            'current_password' => $password,
            'source_sha' => str_repeat('a', 40),
        ])->assertRedirect()->assertSessionHasErrors('install');
        $this->assertSame('Unexpected installation request parameter.',
            session('errors')->first('install'));

        // Privilege revocation remains authoritative even with the correct
        // current password and a previously authenticated session.
        $owner->roles()->detach();
        $this->post('/admin/updates/install-signed', [
            'current_password' => $password,
        ])->assertForbidden();
    }

    public function test_manual_updates_can_be_disabled_without_hiding_status(): void
    {
        config(['site.updates_enabled' => false]);
        $owner = User::create([
            'email' => 'owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $role = Role::firstOrCreate(['name' => 'owner']);
        $owner->roles()->attach($role->id);
        $this->actingAs($owner)->get('/admin/updates')->assertOk();
        $this->post('/admin/updates/apply')->assertForbidden();
    }

    public function test_installer_completion_notice_and_public_entry_work_in_both_login_locales(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $lang => $direction) {
            $ownerNotice = $lang === 'ar'
                ? 'اكتمل التثبيت. سجّل الدخول باستخدام بريد المالك.'
                : 'Installation complete. Sign in with your owner email.';
            $page = $this->withSession(['status' => $ownerNotice])
                ->get('/admin/login?lang='.$lang)->assertOk()
                ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                ->assertSee('role="status"', false)
                ->assertSee('href="'.e(route('home', ['lang' => $lang])).'"', false)
                ->assertSee('type="password" name="password"', false)
                ->assertSee('autocomplete="username"', false);
            $page->assertSee($lang === 'ar'
                ? 'اكتمل تثبيت الموقع وتفعيل حساب المالك.'
                : 'Installation complete. Sign in using the approved owner email.');
            $page->assertDontSee('site-first-run.json');
            $this->assertSame(1, substr_count($page->getContent(), 'name="password"'));
        }
    }

    public function test_owner_update_panel_does_not_misrepresent_migrations_as_code_upgrades(): void
    {
        config(['site.release_ref' => 'unidentified']);
        $owner = User::create([
            'email' => 'update-w2-owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
            $page = $this->get('/admin/updates?lang='.$lang)->assertOk()
                ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                ->assertSee('data-testid="trusted-code-update-unavailable"', false)
                ->assertSee('role="status"', false)
                ->assertSee('href="'.e(route('admin.updates.index', ['lang' => $lang])).'"', false)
                ->assertSee('action="'.e(route('admin.updates.apply', ['lang' => $lang])).'"', false)
                ->assertSee('type="submit"', false);
            $html = $page->getContent();
            $this->assertSame(1, substr_count($html, 'action="'.e(route('admin.updates.apply', ['lang' => $lang])).'"'));
            $this->assertStringNotContainsString('name="release_url"', $html);
            $page->assertSee($lang === 'ar'
                ? 'التحديث الآمن للكود غير متاح بعد.'
                : 'Trusted code updates are not yet available.');
            $page->assertSee('data-testid="installed-migration-apply-blocked"', false)
                ->assertSee('disabled', false)
                ->assertSee($lang === 'ar'
                    ? 'تطبيق الترحيلات محجوب — إثبات الاستعادة مطلوب'
                    : 'Apply blocked — verified recovery required');
        }

        $this->get('/admin/updates?lang=en')
            ->assertSee('Unidentified')
            ->assertSee('data-testid="installed-package-version"', false)
            ->assertSee('0.1.2')
            ->assertSee('Not verified')
            ->assertSee('data-testid="owner-code-upgrade-status"', false)
            ->assertSee('Blocked: host integration unavailable');
    }

    public function test_owner_can_check_real_ed25519_publisher_release_without_applying_code(): void
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            $this->markTestSkipped('Ed25519 sodium extension required.');
        }

        $pair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $payload = json_encode([
            'product' => 'walidsite',
            'version' => '1.2.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
            'artifact_size' => 4096,
            'min_php' => '8.3.0',
            'artifact_file' => 'WalidSite-update-v1.2.0.zip',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $envelope = json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $payload, sodium_crypto_sign_secretkey($pair),
            )),
        ], JSON_THROW_ON_ERROR);
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function (string $url, \Closure $write) use (&$calls, $envelope): int {
                self::assertSame('https://updates.example.com/releases/latest.json', $url);
                $calls++;
                self::assertSame(strlen($envelope), $write($envelope));
                return 200;
            },
        ));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => trim((string) file_get_contents(base_path('VERSION'))),
            'site.trusted_release_origin' => 'https://updates.example.com',
            'site.trusted_release_public_key' => $publicKey,
        ]);
        $owner = User::create([
            'email' => 'signed-check-owner@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);

        // Loading the update panel must never trigger outbound discovery.
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('Check for signed release (read-only)')
            ->assertSee('data-testid="trusted-code-update-unavailable"', false)
            ->assertDontSee('1.2.0');
        $this->assertSame(0, $calls);
        $this->post('/admin/updates/check?lang=ar')
            ->assertRedirect('/admin/updates?lang=ar')
            ->assertSessionHas('trusted_release_check.state', 'signed_release_available')
            ->assertSessionHas('trusted_release_check.version', '1.2.0');
        $this->assertSame(1, $calls);

        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-testid="trusted-release-verified"', false)
            ->assertSee('data-testid="publisher-signed-release-notes"', false)
            ->assertSee('data-testid="signed-release-notes-not-provided"', false)
            ->assertSee('لم يرفق الناشر ملاحظات موقّعة لهذا الإصدار.')
            ->assertSee('1.2.0')
            ->assertSee(str_repeat('a', 40))
            ->assertSee(str_repeat('b', 64))
            ->assertDontSee('https://updates.example.com')
            ->assertDontSee($publicKey)
            ->assertDontSee($envelope)
            ->assertDontSee('name="release_url"', false);
    }

    public function test_missing_publisher_trust_configuration_never_contacts_release_host(): void
    {
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function () use (&$calls): int {
                $calls++;
                return 200;
            },
        ));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => '',
            'site.trusted_release_origin' => '',
            'site.trusted_release_public_key' => '',
        ]);
        $owner = User::create([
            'email' => 'signed-check-unconfigured@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner)->post('/admin/updates/check?lang=en')
            ->assertRedirect('/admin/updates?lang=en')
            ->assertSessionHas('trusted_release_check.state', 'not_configured');
        $this->assertSame(0, $calls);
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('data-testid="trusted-release-not-configured"', false);
    }

    public function test_mismatched_installed_version_blocks_catalog_network_and_false_signed_status(): void
    {
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function () use (&$calls): int {
                $calls++;
                return 200;
            },
        ));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => '9.9.9',
            'site.trusted_release_origin' => 'https://updates.example.com',
            'site.trusted_release_public_key' => base64_encode(str_repeat('x', 32)),
        ]);
        $owner = User::create([
            'email' => 'mismatched-installed-release@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner)->post('/admin/updates/check?lang=en')
            ->assertRedirect('/admin/updates?lang=en')
            ->assertSessionHas('trusted_release_check.state', 'not_configured');
        $this->assertSame(0, $calls, 'Must not contact publisher for a fabricated installed version.');
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('data-testid="trusted-release-not-configured"', false)
            ->assertSee('0.1.2')
            ->assertDontSee('data-testid="trusted-release-verified"', false);
    }

    public function test_old_signed_discovery_flash_is_invalidated_after_trust_or_version_change(): void
    {
        $installed = trim((string) file_get_contents(base_path('VERSION')));
        $origin = 'https://updates.example.com';
        $key = base64_encode(str_repeat('x', 32));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => $installed,
            'site.trusted_release_origin' => $origin,
            'site.trusted_release_public_key' => $key,
        ]);
        $owner = User::create([
            'email' => 'stale-release-check@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner);

        $result = [
            'state' => 'signed_release_available',
            'installed_version' => $installed,
            'trust_fingerprint' => hash('sha256', $origin."\n".$key),
            'checked_at' => time(),
            'version' => '99.0.0',
            'source_sha' => str_repeat('a', 40),
            'artifact_sha256' => str_repeat('b', 64),
        ];

        // A previously authenticated result must NOT survive a publisher
        // key change, code deployment, or expired discovery window.
        foreach ([
            ['site.trusted_release_public_key' => base64_encode(str_repeat('y', 32))],
            ['site.trusted_installed_version' => '8.8.8'],
            [],
        ] as $i => $changed) {
            config([
                'site.trusted_installed_version' => $installed,
                'site.trusted_release_origin' => $origin,
                'site.trusted_release_public_key' => $key,
            ]);
            config($changed);
            $flash = $result;
            if ($i === 2) {
                $flash['checked_at'] = time() - 301;
            }
            $this->withSession(['trusted_release_check' => $flash])
                ->get('/admin/updates?lang=en')->assertOk()
                ->assertDontSee('data-testid="trusted-release-verified"', false)
                ->assertDontSee('99.0.0')
                ->assertSee('Not verified');
        }
    }

    public function test_untrusted_catalog_fails_closed_and_hides_transport_error(): void
    {
        $calls = 0;
        $this->app->instance(TrustedReleaseCatalog::class, new TrustedReleaseCatalog(
            static function (string $url, \Closure $write) use (&$calls): int {
                $calls++;
                $write('private-signature-error-do-not-display');
                return 200;
            },
        ));
        config([
            'site.updates_enabled' => true,
            'site.trusted_installed_version' => trim((string) file_get_contents(base_path('VERSION'))),
            'site.trusted_release_origin' => 'https://updates.example.com',
            'site.trusted_release_public_key' => base64_encode(str_repeat('x', 32)),
        ]);
        $owner = User::create([
            'email' => 'signed-check-untrusted@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner)->post('/admin/updates/check?lang=en')
            ->assertRedirect('/admin/updates?lang=en')
            ->assertSessionHas('trusted_release_check.state', 'publisher_unverified');
        $this->assertSame(1, $calls);
        $this->get('/admin/updates?lang=en')->assertOk()
            ->assertSee('data-testid="trusted-release-unverified"', false)
            ->assertDontSee('private-signature-error-do-not-display')
            ->assertDontSee('updates.example.com')
            ->assertDontSee(base64_encode(str_repeat('x', 32)));
    }

    public function test_signed_release_check_restricts_owner_and_request_overrides(): void
    {
        // This test asserts authorization/validation across five consecutive
        // requests; retain real route throttling but isolate it here so 429
        // does not mask an eventual suspended-owner 403 decision.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->post('/admin/updates/check')->assertRedirect('/admin/login');
        $user = User::create([
            'email' => 'release-check-admin@example.test',
            'password_hash' => 'unused',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);
        $this->actingAs($user)->post('/admin/updates/check')->assertForbidden();

        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->post('/admin/updates/check?lang=en', [
            'release_url' => 'https://attacker.example.com/release.zip',
            'publisher_public_key' => 'untrusted',
        ])->assertSessionHasErrors('updates');
        $this->post('/admin/updates/check?origin=https://attacker.example.com')
            ->assertSessionHasErrors('updates');
        $user->forceFill(['status' => 'suspended'])->save();
        $this->post('/admin/updates/check?lang=ar')->assertForbidden();
    }

}

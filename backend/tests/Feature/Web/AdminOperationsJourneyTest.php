<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminOperationsJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['portfolio.github_public_repos' => []]);
    }

    private function account(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(18)).'@example.test',
            'status' => $status,
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function project(string $visibility = 'private'): Project
    {
        return Project::create([
            'slug' => 'project-'.Str::lower(Str::random(12)),
            'title' => 'Evidence-managed project',
            'summary_en' => 'A factual English draft summary.',
            'summary_ar' => 'وصف عربي لمسودة قابلة للمراجعة.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'research',
            'visibility' => $visibility,
            'published_at' => $visibility === 'public' ? now()->subDay() : null,
        ]);
    }

    public function test_every_operations_screen_requires_real_admin_web_session(): void
    {
        Http::fake();
        foreach (['github', 'projects', 'audience', 'analytics'] as $screen) {
            $this->get('/admin/'.$screen)->assertRedirect('/admin/login');
        }
        $this->post('/admin/github/refresh')->assertRedirect('/admin/login');
        $this->actingAs($this->account('reader'), 'web');
        foreach (['github', 'projects', 'audience', 'analytics'] as $screen) {
            $this->get('/admin/'.$screen)->assertForbidden();
        }
        $this->post('/admin/github/refresh')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_bilingual_admin_journey_is_reachable_from_dashboard_without_secret_or_indexing(): void
    {
        Http::fake();
        $this->actingAs($this->account('admin'), 'web');
        foreach (['ar', 'en'] as $lang) {
            $home = $this->get('/admin?lang='.$lang)->assertOk();
            foreach (['github', 'projects', 'audience', 'analytics'] as $screen) {
                $url = route('admin.operations.'.$screen, ['lang' => $lang]);
                $home->assertSee('href="'.$url.'"', false);
                $this->get($url)->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertSee('dir="'.($lang === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee('lang="'.$lang.'"', false)
                    ->assertSee('name="robots" content="noindex,nofollow"', false)
                    ->assertSee('href="#content"', false)
                    ->assertSee('tabindex="-1"', false)
                    ->assertSee('family=Tajawal', false)
                    // Audience and analytics also preserve their validated
                    // segment/day filters; the locale is the leading query.
                    ->assertSee('href="'.route('admin.operations.'.$screen, [
                        'lang' => $lang === 'ar' ? 'en' : 'ar',
                    ]), false);
            }
        }
        Http::assertNothingSent();
    }

    public function test_github_refresh_only_uses_public_allowlist_and_never_reveals_private_names(): void
    {
        config(['portfolio.github_public_repos' => [
            'owner/verified', 'private-org/private-work',
        ]]);
        Http::fake([
            'api.github.com/repos/owner/verified' => Http::response([
                'full_name' => 'owner/verified',
                'html_url' => 'https://github.com/owner/verified',
                'description' => 'Verified real public metadata',
                'language' => 'PHP', 'visibility' => 'public', 'private' => false,
            ], 200),
            'api.github.com/repos/private-org/private-work' => Http::response([], 404),
        ]);
        $this->actingAs($this->account(), 'web');
        $this->get('/admin/github')->assertOk()
            ->assertSee('owner/verified')->assertSee('Verified public')
            ->assertSee('Repository unavailable')
            ->assertDontSee('private-org/private-work')
            ->assertDontSee('Verified public repositories: 1')
            ->assertSee('name="_token"', false)
            ->assertDontSee('PRIVATE_TOKEN');
        $this->post('/admin/github/refresh?lang=en', [])
            ->assertRedirect(route('admin.operations.github', ['lang' => 'en']))
            ->assertSessionHas('status', 'Verified public repositories: 1');
        $this->post('/admin/github/refresh?lang=en', ['repo' => 'private-org/private-work'])
            ->assertRedirect()->assertSessionHasErrors('repo');
        Http::assertSent(fn ($request) =>
            str_starts_with($request->url(), 'https://api.github.com/repos/'));
        // A rejected selection can re-check the public allowlist but must
        // never issue a caller-specified URL or expose credentials.
        Http::assertSent(fn ($request) =>
            in_array($request->url(), [
                'https://api.github.com/repos/owner/verified',
                'https://api.github.com/repos/private-org/private-work',
            ], true));
    }

    public function test_only_verified_allowlisted_github_metadata_can_be_inspected_in_both_locales(): void
    {
        // Test the publication/role gate independently from the production
        // request-rate limiter (which is tested in its own suite).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['portfolio.github_public_repos' => ['owner/verified', 'private-org/private-work']]);
        Http::fake([
            'api.github.com/repos/owner/verified' => Http::response([
                'full_name' => 'owner/verified', 'html_url' => 'https://github.com/owner/verified',
                'description' => '<script>not executable</script> Public description',
                'language' => 'PHP', 'visibility' => 'public', 'private' => false,
            ]),
            'api.github.com/repos/private-org/private-work' => Http::response([], 404),
        ]);
        $this->actingAs($this->account('admin'), 'web');
        foreach (['en','ar'] as $lang) {
            $url = '/admin/github?lang='.$lang.'&repo=owner/verified';
            $response = $this->get($url)->assertOk()
                ->assertSee('owner/verified')
                ->assertSee('name="repo" value="owner/verified"', false)
                ->assertSee('GitHub public REST')
                ->assertSee('&lt;script&gt;', false)
                ->assertDontSee('<script>', false)
                ->assertHeader('Cache-Control', 'no-store, private');
            if ($lang === 'ar') {
                $response->assertSee('dir="rtl"', false)
                    ->assertSee('فحص البيانات');
            } else {
                $response->assertSee('Selected verified public repository')
                    ->assertSee('Recheck selected public repository');
            }
        }
        $this->get('/admin/github?repo=private-org/private-work')->assertNotFound();
        $this->get('/admin/github?repo=unknown/repository')->assertNotFound();
        $this->get('/admin/github?repo[]=owner/verified')->assertRedirect()
            ->assertSessionHasErrors('repo');
        $this->post('/admin/github/refresh?lang=ar', ['repo' => 'private-org/private-work'])
            ->assertRedirect()->assertSessionHasErrors('repo');
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/github?repo=owner/verified')->assertForbidden();
    }

    public function test_selected_github_refresh_rechecks_revoked_public_metadata_without_claiming_publication(): void
    {
        // Test the publication/role gate independently from the production
        // request-rate limiter (which is tested in its own suite).
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $active = true;
        config(['portfolio.github_public_repos' => ['owner/verified']]);
        Http::fake(function ($request) use (&$active) {
            if ($request->url() !== 'https://api.github.com/repos/owner/verified') {
                return Http::response([], 404);
            }
            return $active ? Http::response([
                'full_name' => 'owner/verified', 'html_url' => 'https://github.com/owner/verified',
                'description' => 'Public proof', 'visibility' => 'public', 'private' => false,
            ]) : Http::response([
                'full_name' => 'owner/verified', 'html_url' => 'https://github.com/owner/verified',
                'visibility' => 'private', 'private' => true,
            ], 200);
        });
        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/github?repo=owner/verified')->assertOk()
            ->assertSee('Selected verified public repository');
        $active = false;
        $this->post('/admin/github/refresh?lang=ar', ['repo' => 'owner/verified'])
            ->assertRedirect(route('admin.operations.github', ['lang'=>'ar']))
            ->assertSessionHas('warning', 'Selected repository is no longer publicly verified.');
        $this->get('/admin/github?repo=owner/verified')->assertNotFound();
        $this->get('/admin/github?lang=ar')->assertOk()
            ->assertSee('مستودع غير متاح')
            ->assertDontSee('owner/verified')
            ->assertDontSee('Public proof');
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.github.com/repos/owner/verified');
    }

    public function test_github_sync_cannot_claim_success_when_all_repositories_are_unavailable(): void
    {
        config(['portfolio.github_public_repos' => ['owner/nonexistent']]);
        Http::fake(['api.github.com/repos/*' => Http::response([], 404)]);
        $this->actingAs($this->account('admin'), 'web');
        $this->post('/admin/github/refresh')
            ->assertRedirect()->assertSessionHas('warning');
        $this->get('/admin/github?lang=ar')->assertOk()
            ->assertSee('تعذر التحقق')
            ->assertDontSee('owner/nonexistent');
    }

    public function test_admin_github_cards_count_only_valid_unique_allowlist_sources_in_both_locales(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['portfolio.github_public_repos' => array_merge(
            ['owner/verified', 'OWNER/VERIFIED'],
            array_fill(0, 21, 'invalid!'),
            ['owner/unavailable']
        )]);
        Http::fake([
            'api.github.com/repos/owner/verified' => Http::response([
                'full_name' => 'owner/verified',
                'html_url' => 'https://github.com/owner/verified',
                'description' => 'Verified source',
                'private' => false, 'visibility' => 'public',
            ]),
            'api.github.com/repos/owner/unavailable' => Http::response([], 404),
        ]);
        $this->actingAs($this->account('admin'), 'web');

        foreach (['en' => 'Configured entries', 'ar' => 'إعدادات القائمة'] as $lang => $label) {
            $response = $this->get('/admin/github?lang='.$lang)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('owner/verified')
                ->assertDontSee('owner/unavailable')
                ->assertDontSee('invalid!');
            $this->assertStringContainsString(
                '<span>'.$label.'</span><strong>2</strong>',
                $response->getContent()
            );
        }

        $this->post('/admin/github/refresh?lang=en', [])
            ->assertRedirect(route('admin.operations.github', ['lang' => 'en']))
            ->assertSessionHas('status', 'Verified public repositories: 1');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'invalid!'));
    }

    public function test_empty_admin_github_allowlist_reports_no_external_sync_in_arabic_and_english(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['portfolio.github_public_repos' => ['invalid!', 'owner/../private']]);
        Http::fake();
        $this->actingAs($this->account('admin'), 'web');

        $english = $this->get('/admin/github?lang=en')->assertOk()
            ->assertSee('No repositories are configured.')
            ->assertDontSee('owner/../private');
        $this->assertStringContainsString(
            '<span>Configured entries</span><strong>0</strong>', $english->getContent()
        );
        $arabic = $this->get('/admin/github?lang=ar')->assertOk()
            ->assertSee('لا توجد مستودعات مهيأة.');
        $this->assertStringContainsString(
            '<span>إعدادات القائمة</span><strong>0</strong>', $arabic->getContent()
        );

        $this->post('/admin/github/refresh?lang=en', [])
            ->assertRedirect(route('admin.operations.github', ['lang' => 'en']))
            ->assertSessionHas('warning', 'No valid public GitHub repositories are configured.');
        $this->post('/admin/github/refresh?lang=ar', [])
            ->assertRedirect(route('admin.operations.github', ['lang' => 'ar']))
            ->assertSessionHas('warning', 'لا توجد مستودعات عامة صالحة مهيأة للتحقق.');
        Http::assertNothingSent();
    }

    public function test_projects_filter_paginates_real_evidence_and_only_owner_can_edit_private_drafts(): void
    {
        Http::fake();
        $private = $this->project();
        $published = $this->project('public');
        DB::table('project_evidence')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $published->id,
            'evidence_type' => 'public_url',
            'url' => 'https://github.com/owner/verified',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->account('admin'), 'web');
        $this->get('/admin/projects?visibility=public&lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('1 / 1')->assertDontSee('Edit private draft');
        $this->get('/admin/projects?visibility=private')->assertOk()
            ->assertSee('Evidence-managed project')
            ->assertDontSee('Edit private draft');
        $payload = [
            'title' => 'Reviewed title',
            'summary_en' => 'Updated English draft with evidence pending.',
            'summary_ar' => 'تحديث عربي يحتاج إثباتاً قبل النشر.',
            'role_en' => 'Developer',
            'role_ar' => 'مطور',
        ];
        $this->patch('/admin/projects/'.$private->id, $payload)->assertForbidden();

        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/projects?visibility=private')->assertOk()
            ->assertSee('Edit private draft')
            ->assertSee('name="_method" value="PATCH"', false);
        $this->patch('/admin/projects/'.$private->id.'?lang=ar', $payload)
            ->assertRedirect(route('admin.operations.projects', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم حفظ مسودة المشروع الخاصة دون نشرها.');
        $this->get('/admin/projects?lang=ar')->assertOk()
            ->assertSee('تم حفظ مسودة المشروع الخاصة دون نشرها.')
            ->assertSee('dir="rtl"', false);
        $this->assertSame('Reviewed title', $private->fresh()->title);
        $this->assertSame('private', $private->fresh()->visibility);
        $this->assertNull($private->fresh()->published_at);
        $this->patch('/admin/projects/'.$published->id, $payload)->assertStatus(409);
        $this->patch('/admin/projects/'.$private->id, $payload + ['visibility' => 'public'])
            ->assertRedirect()->assertSessionHasErrors('project');
        $this->assertSame('private', $private->fresh()->visibility);
        Http::assertNothingSent();
    }

    public function test_audience_preview_excludes_revocations_deleted_users_and_missing_consent(): void
    {
        Http::fake();
        $admin = $this->account('admin');
        $allowed = $this->account('reader');
        $revoked = $this->account('reader');
        $disabled = $this->account('reader');
        $future = $this->account('reader');
        $suspended = $this->account('reader', 'suspended');
        foreach ([
            [$allowed, true, now()->subDay(), null],
            [$revoked, true, now()->subDay(), now()],
            [$disabled, false, now()->subDay(), null],
            // Imported future consent must not count before its grant time.
            [$future, true, now('UTC')->addDay(), null],
            [$suspended, true, now()->subDay(), null],
        ] as [$user, $enabled, $consented, $revokedAt]) {
            DB::table('notification_preferences')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'purpose' => 'editorial_updates',
                'channel' => 'push',
                'enabled' => $enabled,
                'consented_at' => $consented,
                'revoked_at' => $revokedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->actingAs($admin, 'web');
        $this->get('/admin/audience?purpose=editorial_updates&channel=push')->assertOk()
            ->assertSee('Consented audience preview')
            ->assertSee('>1</strong>', false)
            ->assertSee('Delivery is disabled')
            ->assertDontSee($allowed->email)
            ->assertDontSee($revoked->email)
            ->assertDontSee($future->email)
            ->assertDontSee($suspended->email);
        $this->get('/admin/audience?channel=email')->assertOk()
            ->assertSee('>0</strong>', false);
        $this->get('/admin/audience?lang=ar')->assertOk()
            ->assertSee('معاينة جمهور وافق بالفعل')
            ->assertSee('>1</strong>', false)
            ->assertSee('الإرسال معطّل بأمان');
        Http::assertNothingSent();
    }

    public function test_analytics_uses_real_utc_receipts_never_mixes_currencies_or_invents_traffic(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $provider = AiProvider::create([
            'display_name' => 'Never expose credentials',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'PRIVATE-CREDENTIAL-NOT-FOR-HTML',
            'enabled' => false, 'health_status' => 'unverified',
        ]);
        $jobId = (string) Str::uuid();
        $day = now('UTC')->toDateString();
        DB::table('ai_jobs')->insert([
            'id' => $jobId, 'provider_id' => $provider->id,
            'job_type' => 'draft', 'idempotency_key' => 'admin-operation-test',
            'payload_hash' => str_repeat('c', 64), 'status' => 'completed',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        foreach ([['USD', 99], ['KWD', 7]] as [$currency, $cost]) {
            DB::table('ai_usage')->insert([
                'id' => (string) Str::uuid(),
                'usage_key' => 'operation-'.Str::uuid(),
                'job_id' => $jobId, 'provider_id' => $provider->id,
                'cost_currency' => $currency, 'cost_minor' => $cost,
                'created_at' => now('UTC'),
            ]);
        }
        $this->get('/admin/analytics?day='.$day)->assertOk()
            ->assertSee('Stored operational metrics')
            ->assertSee('USD')->assertSee('KWD')
            ->assertSee('>99</td>', false)->assertSee('>7</td>', false)
            ->assertDontSee('>106</td>', false)
            ->assertDontSee('PRIVATE-CREDENTIAL-NOT-FOR-HTML')
            ->assertDontSee('Never expose credentials')
            ->assertSee('not estimated traffic');
        $response = $this->get('/admin/analytics?day='.$day.'&format=csv')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('USD,1,99', $csv);
        $this->assertStringContainsString('KWD,1,7', $csv);
        $this->assertStringNotContainsString('PRIVATE-CREDENTIAL-NOT-FOR-HTML', $csv);
        Http::assertNothingSent();
    }

    public function test_historical_analytics_uses_only_real_utc_calendar_windows_and_exports_exact_period(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $provider = AiProvider::create([
            'display_name' => 'Private provider identifier',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'NEVER-LEAK-SECRET',
            'enabled' => false, 'health_status' => 'unverified',
        ]);

        $day = now('UTC')->toDateString();
        $rows = [
            [0, 'USD', 17],
            [6, 'USD', 23],
            [31, 'KWD', 700],
        ];
        foreach ($rows as [$daysAgo, $currency, $minor]) {
            $when = now('UTC')->subDays($daysAgo)->startOfDay()->addHours(12);
            $jobId = (string) Str::uuid();
            DB::table('ai_jobs')->insert([
                'id' => $jobId, 'provider_id' => $provider->id,
                'job_type' => 'draft',
                'idempotency_key' => 'historic-'.Str::uuid(),
                'payload_hash' => str_repeat('b', 64), 'status' => 'completed',
                'created_at' => $when, 'updated_at' => $when,
            ]);
            DB::table('ai_usage')->insert([
                'id' => (string) Str::uuid(),
                'usage_key' => 'historic-usage-'.Str::uuid(),
                'job_id' => $jobId, 'provider_id' => $provider->id,
                'cost_currency' => $currency, 'cost_minor' => $minor,
                'created_at' => $when,
            ]);
        }

        $one = $this->get('/admin/analytics?day='.$day)->assertOk()
            ->assertSee('value="1" selected', false)
            ->assertSee('>17</td>', false)
            ->assertDontSee('>40</td>', false)
            ->assertDontSee('KWD')
            ->assertDontSee('NEVER-LEAK-SECRET');
        $seven = $this->get('/admin/analytics?day='.$day.'&window=7&lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('value="7" selected', false)
            ->assertSee('>40</td>', false)
            ->assertDontSee('KWD')
            ->assertDontSee('Private provider identifier')
            ->assertSee('الفترة الفعلية')
            ->assertSee('name="window"', false);
        $this->assertStringContainsString(
            'window=7',
            $seven->getContent()
        );
        $this->get('/admin/analytics?day='.$day.'&window=30&lang=en')
            ->assertOk()->assertSee('>40</td>', false)->assertDontSee('KWD');

        $csv = $this->get('/admin/analytics?day='.$day.'&window=7&format=csv')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->streamedContent();
        $this->assertStringContainsString('"UTC period start","UTC period end",Currency,Receipts,"Cost (minor units)"', $csv);
        $this->assertStringContainsString(now('UTC')->subDays(6)->toDateString().','.$day.',USD,2,40', $csv);
        $this->assertStringNotContainsString('KWD', $csv);
        $this->assertStringNotContainsString('Private provider identifier', $csv);

        $old = now('UTC')->subDays(31)->toDateString();
        $this->get('/admin/analytics?day='.$old.'&window=1&lang=en')->assertOk()
            ->assertSee('KWD')->assertSee('>700</td>', false)
            ->assertDontSee('USD');
        Http::assertNothingSent();
    }

    public function test_analytics_cost_download_is_real_utf8_csv_with_safe_headers_in_both_locales(): void
    {
        Http::fake();
        $day = now('UTC')->toDateString();
        $start = now('UTC')->subDays(6)->toDateString();
        $filename = 'ai-usage-'.$start.'-to-'.$day.'.csv';

        $this->get('/admin/analytics?format=csv')->assertRedirect('/admin/login');
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/analytics?format=csv')->assertForbidden();
        $this->actingAs($this->account('owner'), 'web');
        foreach (['ar', 'en'] as $locale) {
            $response = $this->get('/admin/analytics?format=csv&window=7&lang='.$locale)
                ->assertOk()
                ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertDownload($filename);
            $csv = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $this->assertStringContainsString(
                '"UTC period start","UTC period end",Currency,Receipts,"Cost (minor units)"',
                $csv
            );
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_SECRET', $csv);
        }
        Http::assertNothingSent();
    }

    public function test_analytics_csv_never_interprets_imported_currency_as_spreadsheet_formula(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $provider = AiProvider::create([
            'display_name' => 'Private provider',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'enabled' => false, 'health_status' => 'unverified',
        ]);
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $jobId, 'provider_id' => $provider->id,
            'job_type' => 'draft', 'idempotency_key' => 'csv-injection-'.Str::uuid(),
            'payload_hash' => str_repeat('e', 64), 'status' => 'completed',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => 'csv-injection-'.Str::uuid(),
            'job_id' => $jobId, 'provider_id' => $provider->id,
            'cost_currency' => '=HYPERLINK("https://example.invalid")',
            'cost_minor' => 15, 'created_at' => now('UTC'),
        ]);

        $csv = $this->get('/admin/analytics?window=1&format=csv')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('INVALID,1,15', $csv);
        $this->assertStringNotContainsString('=HYPERLINK', $csv);
        Http::assertNothingSent();
    }

    public function test_job_status_csv_honors_utc_windows_and_hides_private_credentials(): void
    {
        Http::fake();
        $provider = AiProvider::create([
            'display_name' => 'INTERNAL-PROVIDER-PRIVATE',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'DO-NOT-EXPORT-PROVIDER-TOKEN',
            'enabled' => false, 'health_status' => 'unverified',
        ]);
        foreach ([
            [0, 'completed'], [0, 'completed'], [6, 'queued'],
            [31, 'failed'], [0, '=SUM(1,1)'],
        ] as [$daysAgo, $status]) {
            $when = now('UTC')->subDays($daysAgo)->startOfDay()->addHours(12);
            DB::table('ai_jobs')->insert([
                'id' => (string) Str::uuid(), 'provider_id' => $provider->id,
                'job_type' => 'draft',
                'idempotency_key' => 'status-export-'.Str::uuid(),
                'payload_hash' => str_repeat('f', 64),
                'status' => $status,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }
        $day = now('UTC')->toDateString();
        $this->get('/admin/analytics?format=jobs-csv')->assertRedirect('/admin/login');
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/analytics?format=jobs-csv')->assertForbidden();
        $this->actingAs($this->account('owner'), 'web');
        foreach (['ar', 'en'] as $lang) {
            $this->get('/admin/analytics?window=7&lang='.$lang)->assertOk()
                ->assertSee('format=jobs-csv', false)
                ->assertSee('dir="'.($lang === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee($lang === 'ar'
                    ? 'تصدير حالات مهام الذكاء الاصطناعي CSV'
                    : 'Export AI job status CSV');
        }
        $response = $this->get('/admin/analytics?day='.$day.'&window=7&format=jobs-csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $response->streamedContent();
        $start = now('UTC')->subDays(6)->toDateString();
        $this->assertStringContainsString(
            '"UTC period start","UTC period end","AI job status","Recorded jobs"', $csv);
        $this->assertStringContainsString($start.','.$day.',completed,2', $csv);
        $this->assertStringContainsString($start.','.$day.',queued,1', $csv);
        $this->assertStringContainsString($start.','.$day.',INVALID,1', $csv);
        $this->assertStringNotContainsString('failed,1', $csv);
        foreach (['=SUM', 'INTERNAL-PROVIDER-PRIVATE', 'DO-NOT-EXPORT-PROVIDER-TOKEN', $provider->id] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        }
        $one = $this->get('/admin/analytics?day='.$day.'&window=1&format=jobs-csv')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString($day.','.$day.',completed,2', $one);
        $this->assertStringNotContainsString('queued,1', $one);
        $this->assertStringNotContainsString('failed,1', $one);
        $this->get('/admin/analytics?format=jobs-csv&window=2')
            ->assertRedirect()->assertSessionHasErrors('window');
        Http::assertNothingSent();
    }

    public function test_empty_job_status_csv_does_not_fabricate_operational_counts(): void
    {
        Http::fake();
        $this->actingAs($this->account('admin'), 'web');
        $csv = $this->get('/admin/analytics?format=jobs-csv')
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('AI job status', $csv);
        $this->assertStringNotContainsString('completed,', $csv);
        $this->assertStringNotContainsString('queued,', $csv);
        Http::assertNothingSent();
    }

    public function test_analytics_period_rejects_bad_ranges_and_preserves_role_protection(): void
    {
        Http::fake();
        $admin = $this->account('admin');
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/analytics?window=7')->assertForbidden();
        $this->actingAs($admin, 'web');
        foreach ([
            '/admin/analytics?window=0',
            '/admin/analytics?window=2',
            '/admin/analytics?window=31',
            '/admin/analytics?window[]=7',
            '/admin/analytics?day=2999-01-01&window=7',
            '/admin/analytics?day='.now('UTC')->subDays(366)->toDateString(),
        ] as $path) {
            $this->get($path)->assertRedirect()->assertSessionHasErrors();
        }
        $this->get('/admin/analytics?window=30&lang=en')->assertOk()
            ->assertSee('Actual window')
            ->assertSee('name="window"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
    }

    public function test_malformed_parameters_fail_safely_instead_of_reflecting_inputs(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        foreach ([
            '/admin/github?lang[]=ar',
            '/admin/projects?status[]=public',
            '/admin/projects?page=10001',
            '/admin/projects?visibility=hidden',
            '/admin/audience?purpose[]=editorial_updates',
            '/admin/audience?channel=phone',
            '/admin/analytics?day[]=2026-11-02',
            '/admin/analytics?day=not-date',
            '/admin/analytics?format=json',
            '/admin/analytics?day=2999-01-01',
        ] as $path) {
            $this->get($path)->assertRedirect()->assertSessionHasErrors();
        }
        Http::assertNothingSent();
    }
    public function test_admin_project_and_audience_filters_have_translated_labels_but_stable_values(): void
    {
        Http::fake();
        $this->project();
        $this->actingAs($this->account('owner'), 'web');

        $this->get('/admin/projects?lang=ar&status=research&visibility=private')->assertOk()
            ->assertSee('value="research"', false)
            ->assertSee('بحث')
            ->assertSee('خاص')
            ->assertDontSee('>research</option>', false);
        $this->get('/admin/projects?lang=en&status=research&visibility=private')->assertOk()
            ->assertSee('Research')
            ->assertSee('Private');

        $this->get('/admin/audience?lang=ar&purpose=editorial_updates&channel=push')->assertOk()
            ->assertSee('تحديثات المحتوى')
            ->assertSee('إشعار فوري')
            ->assertSee('value="editorial_updates"', false)
            ->assertSee('value="push"', false);
        $this->get('/admin/audience?lang=en&purpose=editorial_updates&channel=push')->assertOk()
            ->assertSee('Editorial updates')
            ->assertSee('Push notification');
        Http::assertNothingSent();
    }



    public function test_admin_project_discovery_combines_limited_search_sort_and_visibility_without_leaking_private_evidence(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();

        $alpha = $this->project();
        $alpha->forceFill(['title' => 'Alpha planning', 'slug' => 'alpha-plan', 'status' => 'research'])->save();
        $beta = $this->project();
        $beta->forceFill(['title' => 'Beta release', 'slug' => 'beta-release', 'status' => 'completed'])->save();
        $special = $this->project();
        $special->forceFill(['title' => 'Project 100% _secure', 'slug' => 'safe-search'])->save();
        DB::table('project_evidence')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $alpha->id,
            'evidence_type' => 'document', 'url' => 'https://example.org/PRIVATE-OWNER-EVIDENCE',
            'visibility' => 'private', 'verified_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->account('admin'), 'web');
        foreach (['en', 'ar'] as $locale) {
            $res = $this->get('/admin/projects?lang='.$locale.'&q=alpha&sort=title&visibility=private&status=research')
                ->assertOk()->assertSee('Alpha planning')
                ->assertDontSee('Beta release')->assertDontSee('Project 100% _secure')
                ->assertDontSee('PRIVATE-OWNER-EVIDENCE')
                ->assertSee('name="q"', false)
                ->assertSee('value="alpha"', false)
                ->assertSee('value="title" selected', false)
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringContainsString('q=alpha', $res->getContent());
            $res->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('lang="'.$locale.'"', false);
            $switch = route('admin.operations.projects', [
                'lang' => $locale === 'ar' ? 'en' : 'ar',
                'status' => 'research', 'visibility' => 'private',
                'q' => 'alpha', 'sort' => 'title',
            ]);
            $res->assertSee('href="'.str_replace('&', '&amp;', $switch).'"', false);
        }
        $this->get('/admin/projects?q=%25')->assertOk()
            ->assertSee('Project 100% _secure')->assertDontSee('Alpha planning');
        $this->get('/admin/projects?q=_secure')->assertOk()
            ->assertSee('Project 100% _secure')->assertDontSee('Beta release');
        $this->get('/admin/projects?q=does-not-exist&lang=ar')->assertOk()
            ->assertSee('لا توجد مشاريع تطابق النتائج.')
            ->assertSee('عرض جميع المشاريع');
        $this->get('/admin/projects?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
        Http::assertNothingSent();
    }

    public function test_project_discovery_rejects_invalid_input_and_retains_filters_across_pages(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        $owner = $this->account('owner');
        for ($i = 0; $i < 23; $i++) {
            $project = $this->project();
            $project->forceFill([
                'title' => sprintf('Reviewable item %02d', $i),
                'slug' => sprintf('reviewable-item-%02d', $i),
            ])->save();
        }

        $this->get('/admin/projects?q=Reviewable')->assertRedirect('/admin/login');
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/projects?q=Reviewable')->assertForbidden();
        $this->actingAs($owner, 'web');
        $page = $this->get('/admin/projects?lang=ar&q=Reviewable&sort=title&visibility=private&page=2')
            ->assertOk()->assertSee('Reviewable item 20')
            ->assertDontSee('Reviewable item 00')
            ->assertSee('dir="rtl"', false)
            ->assertSee('q=Reviewable', false)
            ->assertSee('sort=title', false)
            ->assertSee('visibility=private', false);
        $page->assertDontSee('type="search" name="q" maxlength="100" value="Reviewable" onfocus=', false);
        foreach (['sort=unknown', 'sort[]=title', 'q[]=abc', 'q='.str_repeat('x', 101), 'visibility=unknown'] as $query) {
            $this->get('/admin/projects?'.$query)->assertRedirect()
                ->assertSessionHasErrors();
        }
        Http::assertNothingSent();
    }
}

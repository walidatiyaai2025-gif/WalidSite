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
            ->assertRedirect()->assertSessionHasErrors('refresh');
        Http::assertSent(fn ($request) =>
            str_starts_with($request->url(), 'https://api.github.com/repos/'));
        Http::assertSentCount(4);
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
            ->assertRedirect(route('admin.operations.projects', ['lang' => 'ar']));
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
        $suspended = $this->account('reader', 'suspended');
        foreach ([
            [$allowed, true, now()->subDay(), null],
            [$revoked, true, now()->subDay(), now()],
            [$disabled, false, now()->subDay(), null],
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
            ->assertDontSee($suspended->email);
        $this->get('/admin/audience?channel=email')->assertOk()
            ->assertSee('>0</strong>', false);
        $this->get('/admin/audience?lang=ar')->assertOk()
            ->assertSee('معاينة جمهور وافق بالفعل')
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
}

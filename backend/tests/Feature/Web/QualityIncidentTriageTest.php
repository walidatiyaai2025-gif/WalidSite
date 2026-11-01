<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QualityIncidentTriageTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Recorded provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://PRIVATE-ENDPOINT.example.test/v1',
            'secret_ciphertext' => 'PRIVATE-PROVIDER-SECRET',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 1000,
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    private function incident(AiProvider $provider, string $status, int $attempts = 1): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'editorial-art',
            'idempotency_key' => 'PRIVATE-INTENT-'.$id,
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'attempt_count' => $attempts,
            'max_attempts' => 3,
            'reserved_budget_minor' => 25,
            'next_attempt_at' => $status === 'retry' ? now()->addMinutes(20) : null,
            'result_ref' => 'PRIVATE-RESULT-REF',
            'last_error_code' => 'step_failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_admin_filters_inspects_checkpoints_and_retries_one_job_in_both_locales(): void
    {
        Http::fake();
        $this->actingAs($this->actor(), 'web');
        $provider = $this->provider();
        $retry = $this->incident($provider, 'retry');
        $terminal = $this->incident($provider, 'failed', 3);
        $queued = $this->incident($provider, 'queued', 0);

        DB::table('ai_job_steps')->insert([
            'id' => (string) Str::uuid(), 'job_id' => $retry,
            'step_key' => 'PRIVATE-STEP-KEY', 'position' => 0,
            'status' => 'completed', 'output_ref' => 'PRIVATE-CHECKPOINT-OUTPUT',
            'attempt_no' => 1, 'completed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
        DB::table('ai_job_steps')->insert([
            'id' => (string) Str::uuid(), 'job_id' => $retry,
            'step_key' => 'PRIVATE-NEXT-STEP', 'position' => 1,
            'status' => 'pending', 'output_ref' => null,
            'attempt_no' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['en', 'ar'] as $locale) {
            $filtered = $this->get('/admin/quality?lang='.$locale.'&job_status=retry')
                ->assertOk()
                ->assertSee($locale === 'ar' ? 'فشل وإعادة محاولة مهام AI (1)' : 'AI failures and retries (1)')
                ->assertSee($retry)
                ->assertDontSee($terminal)->assertDontSee($queued)
                ->assertSee('inspect_job='.$retry)
                ->assertSee('name="job_status"', false)
                ->assertSee('name="job_id"', false)
                ->assertDontSee('PRIVATE-STEP-KEY')
                ->assertDontSee('PRIVATE-PROVIDER-SECRET');
            if ($locale === 'ar') {
                $filtered->assertSee('dir="rtl"', false)
                    ->assertSee('البحث في أعطال AI');
            } else {
                $filtered->assertSee('dir="ltr"', false)
                    ->assertSee('Search AI incidents');
            }

            $detail = $this->get('/admin/quality?lang='.$locale.'&inspect_job='.$retry)
                ->assertOk()
                ->assertSee($retry)
                ->assertSee($locale === 'ar' ? 'فحص مهمة AI المحفوظة' : 'Inspect persisted AI incident')
                ->assertSee($locale === 'ar' ? 'ملخص نقاط المعالجة المحفوظة' : 'Persisted checkpoint summary')
                ->assertSee($locale === 'ar' ? 'إعادة محاولة هذه المهمة الآن' : 'Retry this job now')
                ->assertDontSee('PRIVATE-STEP-KEY')->assertDontSee('PRIVATE-NEXT-STEP')
                ->assertDontSee('PRIVATE-CHECKPOINT-OUTPUT')
                ->assertDontSee('PRIVATE-RESULT-REF')
                ->assertDontSee('PRIVATE-PROVIDER-SECRET')
                ->assertDontSee('PRIVATE-ENDPOINT')
                ->assertDontSee('PRIVATE-INTENT-');
            // The inspection holds only allowlisted step positions, not keys
            // or output references; the UI remains read-only until explicit POST.
        }

        $postUrl = '/admin/quality/jobs/'.$retry.'/retry?lang=ar&inspect_job='.$retry;
        $this->post($postUrl)->assertRedirect(
            route('admin.quality.index', ['lang' => 'ar', 'inspect_job' => $retry])
        )->assertSessionHas('status');
        $this->assertNull(DB::table('ai_jobs')->where('id', $retry)->value('next_attempt_at'));
        $this->assertSame('retry', DB::table('ai_jobs')->where('id', $retry)->value('status'));
        $this->assertSame(1, (int) DB::table('ai_jobs')->where('id', $retry)->value('attempt_count'));
        $this->get('/admin/quality?lang=ar&inspect_job='.$retry)->assertOk()
            ->assertDontSee('PRIVATE-STEP-KEY');
        $this->post('/admin/quality/jobs/'.$terminal.'/retry?lang=en')
            ->assertSessionHasErrors('retry');
        $this->assertSame('failed', DB::table('ai_jobs')->where('id', $terminal)->value('status'));
        Http::assertNothingSent();
    }

    public function test_guest_reader_invalid_and_nonincident_inspection_are_denied(): void
    {
        Http::fake();
        $provider = $this->provider();
        $retry = $this->incident($provider, 'retry');
        $queued = $this->incident($provider, 'queued');
        $this->get('/admin/quality?inspect_job='.$retry)->assertRedirect('/admin/login');

        $this->actingAs($this->actor('reader'), 'web');
        $this->get('/admin/quality?inspect_job='.$retry)->assertForbidden();
        $this->get('/admin/quality?job_status=retry')->assertForbidden();

        $this->actingAs($this->actor(), 'web');
        $this->get('/admin/quality?inspect_job='.$queued)->assertNotFound();
        $this->get('/admin/quality?inspect_job='.(string) Str::uuid())->assertNotFound();
        foreach ([
            'inspect_job[]=abc', 'inspect_job=not-a-uuid',
            'job_id[]=abc', 'job_id=not-a-uuid',
            'job_status=running', 'job_status[]=retry',
        ] as $invalid) {
            $this->get('/admin/quality?'.$invalid)
                ->assertRedirect()->assertSessionHasErrors();
        }
        Http::assertNothingSent();
    }

    public function test_exact_id_filter_and_no_matching_incidents_have_truthful_empty_state(): void
    {
        Http::fake();
        $this->actingAs($this->actor('owner'), 'web');
        $provider = $this->provider();
        $retry = $this->incident($provider, 'retry');
        $failed = $this->incident($provider, 'failed', 3);
        $unknown = (string) Str::uuid();

        $this->get('/admin/quality?lang=en&job_id='.$retry)->assertOk()
            ->assertSee('AI failures and retries (1)')
            ->assertSee($retry)->assertDontSee($failed)
            ->assertSee('value="'.$retry.'"', false);

        $this->get('/admin/quality?lang=ar&job_id='.$retry.'&job_status=failed')
            ->assertOk()
            ->assertSee('فشل وإعادة محاولة مهام AI (0)')
            ->assertSee('لا توجد أعطال تطابق هذه المرشحات.')
            ->assertDontSee($failed)
            ->assertSee('value="'.$retry.'"', false);

        $this->get('/admin/quality?job_id='.$unknown)->assertOk()
            ->assertSee('No incidents match these filters.');
        $this->get('/admin/quality')->assertOk()
            ->assertSee('AI failures and retries (2)');

        Http::assertNothingSent();
    }

    public function test_filtered_backlog_pagination_retains_locale_and_current_query(): void
    {
        Http::fake();
        $this->actingAs($this->actor(), 'web');
        $provider = $this->provider();
        $retryIds = [];
        for ($index = 0; $index < 27; $index++) {
            $id = $this->incident($provider, 'retry');
            DB::table('ai_jobs')->where('id', $id)->update([
                'updated_at' => now()->subMinutes($index),
            ]);
            $retryIds[] = $id;
        }
        $failed = $this->incident($provider, 'failed', 3);

        $first = $this->get('/admin/quality?lang=ar&job_status=retry')->assertOk()
            ->assertSee('فشل وإعادة محاولة مهام AI (27)')
            ->assertSee($retryIds[0])->assertSee($retryIds[24])
            ->assertDontSee($retryIds[25])->assertDontSee($failed)
            ->assertSee('jobs_page=2', false)
            ->assertSee('job_status=retry', false)
            ->assertSee('lang=ar', false);
        $this->assertSame(25, substr_count($first->getContent(), 'step_failed'));

        $last = $this->get('/admin/quality?lang=ar&job_status=retry&jobs_page=2')
            ->assertOk()->assertSee($retryIds[25])->assertSee($retryIds[26])
            ->assertDontSee($retryIds[0])->assertDontSee($failed)
            ->assertSee('jobs_page=1', false);
        $this->assertSame(2, substr_count($last->getContent(), 'step_failed'));
        Http::assertNothingSent();
    }

    public function test_leased_retry_and_terminal_job_never_expose_action_or_change_state(): void
    {
        Http::fake();
        $this->actingAs($this->actor(), 'web');
        $provider = $this->provider();
        $retry = $this->incident($provider, 'retry');
        $failed = $this->incident($provider, 'failed', 3);

        DB::table('job_leases')->insert([
            'id' => (string) Str::uuid(),
            'job_id' => $retry,
            'owner_key' => 'PRIVATE-WORKER-IDENTITY',
            'expires_at' => now()->addMinutes(2),
            'lease_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/quality?lang=en&inspect_job='.$retry)->assertOk()
            ->assertSee('Retry is not available for this job.')
            ->assertDontSee('Retry this job now')
            ->assertDontSee('PRIVATE-WORKER-IDENTITY');

        $this->post('/admin/quality/jobs/'.$retry.'/retry?lang=en&inspect_job='.$retry)
            ->assertRedirect()->assertSessionHasErrors('retry');
        $this->assertNotNull(DB::table('ai_jobs')
            ->where('id', $retry)->value('next_attempt_at'));

        $this->get('/admin/quality?lang=ar&inspect_job='.$failed)->assertOk()
            ->assertSee('إعادة المحاولة غير مسموحة لهذه المهمة حاليًا.')
            ->assertDontSee('إعادة محاولة هذه المهمة الآن');
        $this->post('/admin/quality/jobs/'.$failed.'/retry?lang=en')
            ->assertSessionHasErrors('retry');
        Http::assertNothingSent();
    }

}

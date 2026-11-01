<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminQualityGatesTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::random(14).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Quality Provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://PRIVATE-quality-provider.example.test/v1',
            'secret_ciphertext' => 'PRIVATE-QUALITY-SECRET',
            'enabled' => true,
            'health_status' => 'healthy',
            'daily_budget_minor' => 1000,
            'concurrent_limit' => 2,
            'timeout_seconds' => 8,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    private function job(AiProvider $provider, string $status, int $attempt = 1, int $max = 3): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'editorial-art',
            'idempotency_key' => 'PRIVATE-INTENT-'.$id,
            'payload_hash' => str_repeat('a', 64),
            'status' => $status,
            'attempt_count' => $attempt,
            'max_attempts' => $max,
            'reserved_budget_minor' => 25,
            'next_attempt_at' => $status === 'retry' ? now()->addHour() : null,
            'result_ref' => 'private/result/path',
            'last_error_code' => 'step_failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_guest_and_reader_cannot_open_or_retry_quality_jobs(): void
    {
        $provider = $this->provider();
        $job = $this->job($provider, 'retry');

        $this->get('/admin/quality')->assertRedirect('/admin/login');
        $this->post('/admin/quality/jobs/'.$job.'/retry')->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web');
        $this->get('/admin/quality')->assertForbidden();
        $this->post('/admin/quality/jobs/'.$job.'/retry')->assertForbidden();

        $this->assertNotNull(DB::table('ai_jobs')->where('id', $job)->value('next_attempt_at'));
    }

    public function test_quality_page_shows_persisted_gates_without_private_content(): void
    {
        $this->actingAs($this->account('admin'), 'web');

        $asset = Asset::create([
            'asset_key' => 'blocked-hero',
            'mime_type' => 'image/png',
            'storage_key' => 'private/SECRET-HERO-PATH.png',
            'sha256' => str_repeat('b', 64),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);
        $blocked = Article::create([
            'slug' => 'blocked-quality',
            'title_en' => 'Blocked quality draft',
            'title_ar' => 'مسودة جودة محظورة',
            'canonical_lang' => 'en',
            'hero_asset_id' => $asset->id,
        ]);
        $blocked->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'PRIVATE DRAFT BODY MUST NEVER RENDER',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/PRIVATE-CITATION',
                'verified' => false,
            ]],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
            'status' => 'draft',
        ]);

        $ready = Article::create([
            'slug' => 'ready-quality',
            'title_en' => 'Ready quality draft',
            'title_ar' => 'مسودة جودة جاهزة',
            'canonical_lang' => 'en',
        ]);
        $ready->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Reviewed safe body',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/reference',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
            'status' => 'validated',
        ]);

        $provider = $this->provider();
        $retry = $this->job($provider, 'retry');
        $failed = $this->job($provider, 'failed', 3, 3);

        $response = $this->get('/admin/quality?lang=en')->assertOk()
            ->assertSee('Quality gates')
            ->assertSee('Blocked quality draft')
            ->assertSee('Ready quality draft')
            ->assertSee($retry)
            ->assertSee($failed)
            ->assertSee('step_failed')
            ->assertSee('Retry now')
            ->assertSee('Terminal / not retryable')
            ->assertDontSee('PRIVATE DRAFT BODY MUST NEVER RENDER')
            ->assertDontSee('PRIVATE-CITATION')
            ->assertDontSee('SECRET-HERO-PATH')
            ->assertDontSee('PRIVATE-INTENT')
            ->assertDontSee('PRIVATE-QUALITY-SECRET')
            ->assertDontSee($provider->base_url)
            ->assertDontSee('private/result/path');

        $this->assertGreaterThanOrEqual(1, substr_count($response->getContent(), 'Pass'));
        $this->assertGreaterThanOrEqual(1, substr_count($response->getContent(), 'Blocked'));

        $this->get('/admin/quality?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('بوابات الجودة')
            ->assertSee('أعد المحاولة الآن');
    }

    public function test_quality_draft_titles_open_the_exact_authorized_editor_in_both_locales(): void
    {
        $this->actingAs($this->account('admin'), 'web');
        $draft = Article::create([
            'slug' => 'quality-inspection-link',
            'title_en' => 'Inspect this draft',
            'title_ar' => 'مراجعة هذه المسودة',
            'canonical_lang' => 'en',
        ]);
        $draft->revisions()->create([
            'revision_no' => 1,
            'status' => 'draft',
            'body_md' => 'PRIVATE-DRAFT-CONTENT',
            'citations_json' => [[
                'source_url' => 'https://example.test/PRIVATE-CITATION',
                'verified' => false,
            ]],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => false,
            ],
        ]);
        $published = Article::create([
            'slug' => 'quality-published-exclusion',
            'title_en' => 'Do not inspect as a draft',
            'title_ar' => 'ليست مسودة',
            'canonical_lang' => 'en',
        ]);
        // Status is deliberately guarded against mass assignment in the
        // domain model; use a controlled fixture to prove excluded rows.
        $published->forceFill(['status' => 'published'])->save();

        foreach (['en', 'ar'] as $locale) {
            $title = $locale === 'ar' ? $draft->title_ar : $draft->title_en;
            $url = route('admin.content.index', [
                'article' => $draft->id,
                'lang' => $locale,
            ]);
            $response = $this->get('/admin/quality?lang='.$locale)->assertOk()
                ->assertSee('href="#content"', false)
                ->assertSee('id="content" tabindex="-1"', false)
                ->assertSee('href="'.e($url).'">'.$title.'</a>', false)
                ->assertDontSee('PRIVATE-DRAFT-CONTENT')
                ->assertDontSee('PRIVATE-CITATION')
                ->assertDontSee($published->title_en);

            if ($locale === 'ar') {
                $response->assertSee('dir="rtl"', false);
            } else {
                $response->assertSee('dir="ltr"', false);
            }
            $this->get($url)->assertOk()->assertSee($title);
        }
    }

    public function test_all_current_draft_quality_gates_are_paginated_independently_of_ai_jobs(): void
    {
        $this->actingAs($this->account('admin'), 'web');
        $titles = [];

        for ($index = 0; $index < 54; $index++) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $title = 'Quality draft '.$number;
            $article = Article::create([
                'slug' => 'quality-page-'.$number,
                'title_en' => $title,
                'title_ar' => 'مسودة جودة '.$number,
                'canonical_lang' => 'en',
            ]);
            $revision = $article->revisions()->create([
                'revision_no' => 1,
                'status' => 'draft',
                'body_md' => 'PRIVATE-QUALITY-DRAFT-BODY-'.$number,
                'citations_json' => [],
                'validation_json' => [
                    'citations_passed' => false,
                    'technical_passed' => false,
                ],
            ]);
            $revision->forceFill(['updated_at' => now()->subMinutes($index)])->save();
            $titles[] = $title;

            if ($index === 0) {
                // Superseded versions do not count as separate quality rows.
                $article->revisions()->create([
                    'revision_no' => 2,
                    'status' => 'draft',
                    'body_md' => 'PRIVATE-CURRENT-REVISION',
                    'citations_json' => [],
                    'validation_json' => [
                        'citations_passed' => false,
                        'technical_passed' => false,
                    ],
                ]);
            }
        }

        $provider = $this->provider();
        $jobs = [];
        for ($index = 0; $index < 26; $index++) {
            $jobs[] = $this->job($provider, 'retry');
        }

        $first = $this->get('/admin/quality?lang=en')->assertOk()
            ->assertSee('Current draft publish blockers (54)')
            ->assertSee('Editorial draft pages')
            ->assertSee('drafts_page=2', false)
            ->assertSee($titles[0])->assertSee($titles[24])
            ->assertDontSee($titles[25])->assertDontSee($titles[53])
            ->assertDontSee('PRIVATE-QUALITY-DRAFT-BODY')
            ->assertDontSee('PRIVATE-CURRENT-REVISION')
            ->assertDontSee('private/result/path');
        $this->assertSame(25, substr_count($first->getContent(), '/admin/content?article='));

        $middle = $this->get('/admin/quality?lang=ar&drafts_page=2&jobs_page=2')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('صفحات مراجعة المسودات')
            ->assertSee('مسودة جودة 25')
            ->assertSee('مسودة جودة 49')
            ->assertDontSee($titles[0])
            ->assertDontSee('مسودة جودة 50')
            ->assertSee('drafts_page=3', false)
            ->assertSee('jobs_page=2', false);
        $this->assertStringContainsString('lang=ar', $middle->getContent());
        $this->assertSame(1, substr_count($middle->getContent(), 'step_failed'));

        $last = $this->get('/admin/quality?lang=en&drafts_page=3&jobs_page=1')->assertOk()
            ->assertSee($titles[50])->assertSee($titles[53])
            ->assertDontSee($titles[0])
            ->assertSee('jobs_page=2', false)
            ->assertSee('drafts_page=3', false);
        // Only four editorial rows are present on the final draft page.
        $this->assertSame(4, substr_count($last->getContent(), '/admin/content?article='));
        // Admin review stays behind authorization even with paging parameters.
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/quality?drafts_page=2&jobs_page=2')->assertForbidden();

        $this->actingAs($this->account('admin'), 'web');
        foreach (['drafts_page[]=2', 'drafts_page=0', 'drafts_page=-1',
                  'drafts_page=10001', 'drafts_page=nope'] as $invalid) {
            $this->get('/admin/quality?'.$invalid)
                ->assertRedirect()->assertSessionHasErrors('drafts_page');
        }
    }

    public function test_ai_failure_backlog_is_paginated_without_losing_old_recoverable_jobs(): void
    {
        $this->actingAs($this->account('owner'), 'web');
        $provider = $this->provider();
        $ids = [];
        for ($index = 0; $index < 53; $index++) {
            $id = $this->job($provider, 'retry');
            DB::table('ai_jobs')->where('id', $id)
                ->update(['updated_at' => now()->subMinutes($index)]);
            $ids[] = $id;
        }
        $terminal = $this->job($provider, 'failed', 3, 3);
        DB::table('ai_jobs')->where('id', $terminal)
            ->update(['updated_at' => now()->subMinutes(53)]);
        $ids[] = $terminal;

        $first = $this->get('/admin/quality?lang=en')->assertOk()
            ->assertSee('AI failures and retries (54)')
            ->assertSee('AI failure pages')
            ->assertSee('jobs_page=2', false)
            ->assertSee($ids[0])->assertSee($ids[24])
            ->assertDontSee($ids[25])->assertDontSee($ids[50]);
        $this->assertSame(25, substr_count($first->getContent(), 'step_failed'));

        $second = $this->get('/admin/quality?lang=ar&jobs_page=2')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('صفحات مهام AI الفاشلة')
            ->assertSee($ids[25])->assertSee($ids[49])
            ->assertDontSee($ids[0])->assertDontSee($ids[50])
            ->assertSee('jobs_page=3', false);
        $this->assertSame(25, substr_count($second->getContent(), 'step_failed'));

        $third = $this->get('/admin/quality?lang=ar&jobs_page=3')->assertOk()
            ->assertSee($ids[50])->assertSee($ids[52])->assertSee($terminal)
            ->assertDontSee($ids[0])
            ->assertSee('نهائي / غير قابل للإعادة');
        $this->assertSame(4, substr_count($third->getContent(), 'step_failed'));
        // Page links retain the selected locale, not the default language.
        $this->assertStringContainsString('lang=ar', $second->getContent());

        foreach (['jobs_page[]=2', 'jobs_page=0', 'jobs_page=-2',
                  'jobs_page=10001', 'jobs_page=not-a-number'] as $query) {
            $this->get('/admin/quality?'.$query)
                ->assertRedirect()->assertSessionHasErrors('jobs_page');
        }
    }

    public function test_retry_now_only_clears_backoff_and_preserves_attempt_limits_without_http(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider();
        $job = $this->job($provider, 'retry', 1, 3);

        $before = DB::table('ai_jobs')->where('id', $job)->first();
        $this->assertNotNull($before->next_attempt_at);

        $this->post('/admin/quality/jobs/'.$job.'/retry?lang=en')
            ->assertRedirect(route('admin.quality.index', ['lang' => 'en']))
            ->assertSessionHas('status');

        $after = DB::table('ai_jobs')->where('id', $job)->first();
        $this->assertSame('retry', $after->status);
        $this->assertSame(1, (int) $after->attempt_count);
        $this->assertSame(3, (int) $after->max_attempts);
        $this->assertNull($after->next_attempt_at);
        $this->assertSame('step_failed', $after->last_error_code);
        Http::assertNothingSent();
    }

    public function test_terminal_exhausted_and_active_jobs_cannot_be_resurrected(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider();

        $failed = $this->job($provider, 'failed', 3, 3);
        $exhaustedRetry = $this->job($provider, 'retry', 3, 3);
        $running = $this->job($provider, 'running', 1, 3);

        foreach ([$failed, $exhaustedRetry, $running] as $job) {
            $before = DB::table('ai_jobs')->where('id', $job)->first();
            $this->post('/admin/quality/jobs/'.$job.'/retry')
                ->assertRedirect()
                ->assertSessionHasErrors('retry');
            $after = DB::table('ai_jobs')->where('id', $job)->first();
            $this->assertSame($before->status, $after->status);
            $this->assertSame((int) $before->attempt_count, (int) $after->attempt_count);
            $this->assertSame((int) $before->max_attempts, (int) $after->max_attempts);
            $this->assertSame($before->next_attempt_at, $after->next_attempt_at);
        }

        Http::assertNothingSent();
    }

    public function test_retry_with_active_lease_fails_closed(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider();
        $job = $this->job($provider, 'retry', 1, 3);
        DB::table('job_leases')->insert([
            'id' => (string) Str::uuid(),
            'job_id' => $job,
            'owner_key' => 'worker:test',
            'expires_at' => now()->addMinute(),
            'lease_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/admin/quality/jobs/'.$job.'/retry')
            ->assertSessionHasErrors('retry');
        $this->assertNotNull(DB::table('ai_jobs')->where('id', $job)->value('next_attempt_at'));
        Http::assertNothingSent();
    }
}

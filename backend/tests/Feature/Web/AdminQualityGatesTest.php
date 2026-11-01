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

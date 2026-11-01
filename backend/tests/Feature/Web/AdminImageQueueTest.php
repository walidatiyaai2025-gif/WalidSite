<?php

namespace Tests\Feature\Web;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminImageQueueTest extends TestCase
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

    private function provider(
        string $name = 'Image Provider',
        bool $enabled = true,
        string $health = 'healthy',
        int $budget = 1000
    ): AiProvider {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://PRIVATE-'.$name.'.example.test/v1',
            'secret_ciphertext' => 'PRIVATE-IMAGE-PROVIDER-SECRET',
            'enabled' => $enabled,
            'health_status' => $health,
            'daily_budget_minor' => $budget,
            'concurrent_limit' => 2,
            'timeout_seconds' => 8,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    private function model(
        AiProvider $provider,
        string $id = 'image-model-v1',
        array $capabilities = ['image-generation'],
        bool $enabled = true
    ): AiModel {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $id,
            'capabilities_json' => $capabilities,
            'enabled' => $enabled,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
    }

    /** @return array{0:Article,1:ArticleRevision} */
    private function draft(string $slug = 'queue-artwork'): array
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Safe technical diagram',
            'title_ar' => 'رسم تقني آمن',
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Current draft',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/reference',
                'verified' => false,
            ]],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => false,
            ],
            'status' => 'draft',
        ]);

        return [$article, $revision];
    }

    private function queuePayload(ArticleRevision $revision, AiModel $model, int $reserve = 25): array
    {
        return [
            'revision_id' => $revision->id,
            'model_id' => $model->id,
            'estimated_cost_minor' => $reserve,
        ];
    }

    public function test_guest_and_reader_cannot_queue_artwork(): void
    {
        [$article, $revision] = $this->draft();
        $model = $this->model($this->provider());

        $this->post('/admin/ai/images/queue', $this->queuePayload($revision, $model))
            ->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web')
            ->post('/admin/ai/images/queue', $this->queuePayload($revision, $model))
            ->assertForbidden();

        $this->assertDatabaseCount('ai_jobs', 0);
        $this->assertDatabaseCount('ai_job_steps', 0);
    }

    public function test_console_lists_only_eligible_image_models_and_safe_fields_in_both_locales(): void
    {
        $this->actingAs($this->account('admin'), 'web');
        [$article, $revision] = $this->draft();
        $eligibleProvider = $this->provider('Eligible Image Provider');
        $eligible = $this->model($eligibleProvider, 'image-capable-v1');

        $textOnly = $this->model($eligibleProvider, 'text-only-v1', ['text']);
        $disabled = $this->model($eligibleProvider, 'disabled-image-v1', ['image-generation'], false);
        $unhealthyProvider = $this->provider('Unhealthy Image Provider', true, 'unhealthy');
        $unhealthy = $this->model($unhealthyProvider, 'unhealthy-image-v1');

        $response = $this->get('/admin/ai/images?lang=en')->assertOk()
            ->assertSee('Queue technical artwork')
            ->assertSee($eligible->id)
            ->assertSee('image-capable-v1')
            ->assertSee($revision->id)
            ->assertSee('Cost reservation (minor units)')
            ->assertSee('Artwork queue status')
            ->assertDontSee($textOnly->id)
            ->assertDontSee($disabled->id)
            ->assertDontSee($unhealthy->id)
            ->assertDontSee('PRIVATE-IMAGE-PROVIDER-SECRET')
            ->assertDontSee($eligibleProvider->base_url)
            ->assertDontSee('idempotency_key');

        $this->assertSame(1, substr_count($response->getContent(), 'image-capable-v1'));

        $this->get('/admin/ai/images?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('توليد صورة تقنية')
            ->assertSee('أضف مهمة توليد الصورة');
    }

    public function test_artwork_queue_requires_explicit_cost_estimate_in_both_locales(): void
    {
        $this->actingAs($this->account('owner'), 'web');
        [$article, $revision] = $this->draft();
        $model = $this->model($this->provider());

        foreach (['en', 'ar'] as $locale) {
            $response = $this->get('/admin/ai/images?lang='.$locale)->assertOk()
                ->assertSee('id="reservation-help"', false)
                ->assertSee('aria-describedby="reservation-help"', false)
                ->assertSee('value="" required autocomplete="off"', false)
                ->assertSee('href="'.route('admin.quality.index', ['lang' => $locale]).'"', false);

            if ($locale === 'en') {
                $response->assertSee('manual budget reservation')
                    ->assertSee('Inspect failures and retry eligible jobs in Quality gates');
            } else {
                $response->assertSee('حجز ميزانية يدوي')
                    ->assertSee('راجع الأعطال وأعد محاولة المهام المؤهلة من بوابات الجودة');
            }
        }

        // Server validation must reject omission; never silently reserve a
        // misleading single minor unit on the operator's behalf.
        $this->post('/admin/ai/images/queue', [
            'revision_id' => $revision->id,
            'model_id' => $model->id,
        ])->assertRedirect()->assertSessionHasErrors('estimated_cost_minor');
        $this->assertDatabaseCount('ai_jobs', 0);
    }

    public function test_admin_queues_real_durable_image_job_without_provider_http(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        [$article, $revision] = $this->draft();
        $provider = $this->provider();
        $model = $this->model($provider);

        $this->post('/admin/ai/images/queue?lang=en', $this->queuePayload($revision, $model, 25))
            ->assertRedirect(route('admin.media.index', ['lang' => 'en']))
            ->assertSessionHas('status');

        $job = DB::table('ai_jobs')->where('job_type', 'editorial-art')->first();
        $this->assertNotNull($job);
        $this->assertSame($provider->id, $job->provider_id);
        $this->assertSame('queued', $job->status);
        $this->assertSame(25, (int) $job->reserved_budget_minor);
        $this->assertStringStartsWith('editorial:image:'.$revision->id.':image-model-v1:', $job->idempotency_key);

        $this->assertDatabaseHas('ai_job_steps', [
            'job_id' => $job->id,
            'step_key' => 'generate',
            'status' => 'pending',
        ]);
        Http::assertNothingSent();

        $this->get('/admin/ai/images')->assertOk()
            ->assertSee($job->id)
            ->assertSee($provider->display_name)
            ->assertSee('queued')
            ->assertSee('25 USD')
            ->assertDontSee($job->idempotency_key)
            ->assertDontSee('PRIVATE-IMAGE-PROVIDER-SECRET')
            ->assertDontSee($provider->base_url);
    }

    public function test_budget_admission_counts_existing_unsettled_reservations(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        [$article, $revision] = $this->draft();
        $provider = $this->provider('Budgeted Provider', true, 'healthy', 100);
        $model = $this->model($provider);

        DB::table('ai_jobs')->insert([
            'id' => (string) Str::uuid(),
            'provider_id' => $provider->id,
            'job_type' => 'editorial-art',
            'idempotency_key' => 'existing:'.Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => 'queued',
            'attempt_count' => 0,
            'max_attempts' => 3,
            'reserved_budget_minor' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/admin/ai/images/queue', $this->queuePayload($revision, $model, 30))
            ->assertRedirect()
            ->assertSessionHasErrors('estimated_cost_minor');

        $this->assertDatabaseCount('ai_jobs', 1);
        $this->assertDatabaseCount('ai_job_steps', 0);
        Http::assertNothingSent();
    }

    public function test_ineligible_model_and_stale_revision_fail_closed(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        [$article, $first] = $this->draft();
        $provider = $this->provider();
        $textOnly = $this->model($provider, 'text-only-model', ['text']);

        $this->post('/admin/ai/images/queue', $this->queuePayload($first, $textOnly, 20))
            ->assertSessionHasErrors('model_id');
        $this->assertDatabaseCount('ai_jobs', 0);

        $imageModel = $this->model($provider, 'image-model-v2');
        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => '# Newer draft',
            'citations_json' => [],
            'validation_json' => [],
            'status' => 'draft',
        ]);

        $this->post('/admin/ai/images/queue', $this->queuePayload($first, $imageModel, 20))
            ->assertRedirect()
            ->assertSessionHasErrors('revision_id');

        $this->assertDatabaseCount('ai_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_persisted_spend_also_reduces_queueable_daily_budget(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        [$article, $revision] = $this->draft();
        $provider = $this->provider('Spent Provider', true, 'healthy', 100);
        $model = $this->model($provider);

        $oldJob = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $oldJob,
            'provider_id' => $provider->id,
            'job_type' => 'editorial-art',
            'idempotency_key' => 'completed:'.Str::uuid(),
            'payload_hash' => str_repeat('b', 64),
            'status' => 'completed',
            'attempt_count' => 1,
            'max_attempts' => 3,
            'reserved_budget_minor' => 70,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => 'usage:'.Str::uuid(),
            'job_id' => $oldJob,
            'provider_id' => $provider->id,
            'model_key' => 'image-model-v1',
            'cost_minor' => 70,
            'cost_currency' => 'USD',
            'usage_json' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'),
        ]);

        $this->post('/admin/ai/images/queue', $this->queuePayload($revision, $model, 31))
            ->assertSessionHasErrors('estimated_cost_minor');

        $this->assertDatabaseCount('ai_jobs', 1);
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Asset;
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

    public function test_artwork_statuses_and_rights_are_localized_without_mutating_canonical_values(): void
    {
        Http::fake();
        $this->actingAs($this->account('admin'), 'web');
        [$article, $revision] = $this->draft('artwork-localized-status');
        $model = $this->model($this->provider());
        $asset = Asset::create([
            'asset_key' => 'quality-artwork-unverified',
            'mime_type' => 'image/png',
            'storage_key' => 'private/INTERNAL-ARTWORK-PATH.png',
            'sha256' => str_repeat('a', 64),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);

        $this->post('/admin/ai/images/queue?lang=en',
            $this->queuePayload($revision, $model, 25))
            ->assertRedirect()->assertSessionHasNoErrors();
        $job = DB::table('ai_jobs')->where('job_type', 'editorial-art')->first();
        $this->assertNotNull($job);

        $this->get('/admin/ai/images?lang=en')->assertOk()
            ->assertSee('id="content" tabindex="-1"', false)
            ->assertSee('<td>queued</td>', false)
            ->assertSee('Rights status')
            ->assertSee('unverified')
            ->assertSee('ai-generated')
            ->assertDontSee('INTERNAL-ARTWORK-PATH')
            ->assertDontSee('PRIVATE-IMAGE-PROVIDER-SECRET');

        $this->get('/admin/ai/images?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('id="content" tabindex="-1"', false)
            ->assertSee('href="#content"', false)
            ->assertSee('<td>في الطابور</td>', false)
            ->assertSee('مولدة بالذكاء الاصطناعي')
            ->assertSee('غير متحقق منها')
            ->assertSee('<code dir="ltr">'.$job->id.'</code>', false)
            ->assertDontSee('INTERNAL-ARTWORK-PATH');

        foreach (['retry' => 'بانتظار إعادة المحاولة', 'failed' => 'فشلت'] as $state => $label) {
            DB::table('ai_jobs')->where('id', $job->id)
                ->update(['status' => $state, 'last_error_code' => 'provider_unavailable']);
            $this->get('/admin/ai/images?lang=ar')->assertOk()
                ->assertSee('<td>'.$label.'</td>', false)
                ->assertSee('<code dir="ltr">provider_unavailable</code>', false);
            $this->assertSame($state,
                DB::table('ai_jobs')->where('id', $job->id)->value('status'));
        }

        $this->assertSame('unverified', $asset->fresh()->rights_status);
        $this->assertFalse($asset->fresh()->isPubliclyUsable());
        Http::assertNothingSent();
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

    public function test_competing_image_drafts_cannot_reserve_more_than_the_daily_provider_cap(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        [$firstArticle, $firstRevision] = $this->draft('first-image-request');
        [$secondArticle, $secondRevision] = $this->draft('second-image-request');
        $provider = $this->provider('Shared Budget Provider', true, 'healthy', 100);
        $model = $this->model($provider);

        // Verify the production MariaDB lock used to serialize competing
        // requests, not just sequential balance arithmetic in the fixture.
        $providerQueries = [];
        DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$providerQueries): void {
            if (str_contains(strtolower($query->sql), 'ai_providers')) {
                $providerQueries[] = strtolower($query->sql);
            }
        });

        $this->post('/admin/ai/images/queue?lang=en',
            $this->queuePayload($firstRevision, $model, 65))
            ->assertRedirect(route('admin.media.index', ['lang' => 'en']))
            ->assertSessionHas('status');

        $this->post('/admin/ai/images/queue?lang=ar',
            $this->queuePayload($secondRevision, $model, 36))
            ->assertRedirect()->assertSessionHasErrors('estimated_cost_minor');
        $this->assertDatabaseCount('ai_jobs', 1);

        $this->post('/admin/ai/images/queue?lang=en',
            $this->queuePayload($secondRevision, $model, 35))
            ->assertRedirect(route('admin.media.index', ['lang' => 'en']))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('ai_jobs', 2);
        $this->assertSame(100, (int) DB::table('ai_jobs')
            ->where('provider_id', $provider->id)
            ->whereIn('status', ['queued', 'retry', 'running'])
            ->sum('reserved_budget_minor'));
        $this->assertDatabaseCount('ai_job_steps', 2);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertTrue(collect($providerQueries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'for update')
            ), 'Image budget admission must lock the provider row on MariaDB.');
        }
        Http::assertNothingSent();
    }

    public function test_image_queue_rejects_provider_and_model_disabled_after_the_form_was_loaded(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        [$article, $revision] = $this->draft('model-health-downgrade');
        $provider = $this->provider();
        $model = $this->model($provider);
        $this->get('/admin/ai/images?lang=ar')->assertOk()
            ->assertSee($model->id);

        $model->forceFill(['enabled' => false])->save();
        $this->post('/admin/ai/images/queue?lang=ar',
            $this->queuePayload($revision, $model, 20))
            ->assertRedirect()->assertSessionHasErrors('model_id');
        $model->forceFill(['enabled' => true])->save();

        $provider->forceFill(['health_status' => 'unhealthy'])->save();
        $this->post('/admin/ai/images/queue?lang=en',
            $this->queuePayload($revision, $model, 20))
            ->assertRedirect()->assertSessionHasErrors('model_id');

        $this->assertDatabaseCount('ai_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_artwork_history_and_assets_have_independent_bilingual_pages_without_secrets(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $provider = $this->provider('History Provider');
        $jobIds = [];

        for ($index = 0; $index < 43; $index++) {
            $id = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
            $jobIds[] = $id;
            DB::table('ai_jobs')->insert([
                'id' => $id,
                'provider_id' => $provider->id,
                'job_type' => 'editorial-art',
                'idempotency_key' => 'media:history:'.$index,
                'payload_hash' => str_repeat('a', 64),
                'status' => $index % 2 === 0 ? 'queued' : 'retry',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'reserved_budget_minor' => $index + 1,
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        for ($index = 0; $index < 13; $index++) {
            $asset = \App\Models\Asset::create([
                'asset_key' => 'media-history-'.$index,
                'mime_type' => 'image/png',
                'storage_key' => 'private/history-'.$index.'.png',
                'sha256' => str_repeat('a', 64),
                'origin' => 'owner-supplied',
                'rights_status' => 'unverified',
                'publishable' => false,
            ]);
            $asset->forceFill([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ])->save();
        }

        $first = $this->get('/admin/ai/images?lang=en')->assertOk()
            ->assertSee('Artwork queue status (43)')
            ->assertSee('Artwork queue pages')
            ->assertSee('jobs_page=2', false)
            ->assertSee($jobIds[0])
            ->assertSee($jobIds[19])
            ->assertDontSee($jobIds[20])
            ->assertDontSee($jobIds[42])
            ->assertSee('Registered assets (13)')
            ->assertSee('media-history-0')
            ->assertDontSee('media-history-12')
            ->assertDontSee('PRIVATE-IMAGE-PROVIDER-SECRET')
            ->assertDontSee($provider->base_url)
            ->assertDontSee('idempotency_key');
        $this->assertSame(20, substr_count($first->getContent(), '<td><code>'));

        $second = $this->get('/admin/ai/images?lang=en&jobs_page=2&page=1')->assertOk()
            ->assertSee($jobIds[20])
            ->assertSee($jobIds[39])
            ->assertDontSee($jobIds[0])
            ->assertDontSee($jobIds[40])
            ->assertSee('jobs_page=3', false)
            ->assertSee('page=2', false);
        $this->assertSame(20, substr_count($second->getContent(), '<td><code>'));

        $last = $this->get('/admin/ai/images?lang=ar&jobs_page=3&page=2')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('صفحات مهام توليد الصور')
            ->assertSee('حالة مهام الصور (43)')
            ->assertSee($jobIds[40])
            ->assertSee($jobIds[42])
            ->assertDontSee($jobIds[39])
            ->assertSee('media-history-12')
            ->assertDontSee('media-history-0')
            ->assertSee('jobs_page=3', false)
            ->assertSee('page=1', false);
        $this->assertSame(3, substr_count($last->getContent(), '<td><code>'));
        Http::assertNothingSent();

        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/ai/images?jobs_page=2')->assertForbidden();
    }

    public function test_artwork_history_rejects_invalid_page_input_before_querying(): void
    {
        $this->actingAs($this->account('admin'), 'web');
        foreach ([
            'jobs_page[]=2', 'jobs_page=0', 'jobs_page=-1',
            'jobs_page=10001', 'jobs_page=abc',
        ] as $invalid) {
            $this->get('/admin/ai/images?lang=ar&'.$invalid)
                ->assertRedirect()
                ->assertSessionHasErrors('jobs_page');
        }
    }
}

<?php

namespace Tests\Feature\Web;

use App\AI\MobileDiagnosisAdmission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiModelTaskJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::random(12).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function provider(string $name, string $type = 'ollama-compatible',
        string $currency = 'USD'): AiProvider
    {
        return AiProvider::create([
            'display_name' => $name,
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434' : 'https://provider.example.test/v1',
            'secret_ciphertext' => $type === 'openai-compatible' ? 'very-private-model-secret' : null,
            'enabled' => true,
            'health_status' => 'healthy',
            'currency' => $currency,
            'daily_budget_minor' => 1000,
            'routing_priority' => 10,
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
        ]);
    }

    private function createModel(AiProvider $provider, string $upstream,
        bool $text = true): AiModel
    {
        $this->post('/admin/ai/models?lang=ar', [
            'provider_id' => $provider->id,
            'provider_model_id' => $upstream,
            'enabled' => '1',
            'supports_text' => $text ? '1' : '0',
        ])->assertRedirect(route('admin.models.index', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم تسجيل النموذج.');

        return AiModel::query()->where('provider_id', $provider->id)
            ->where('provider_model_id', $upstream)->firstOrFail();
    }

    private function saveTask(AiModel $primary, array $fallbacks = [],
        string $currency = 'USD', int $reserve = 0, int $minute = 4,
        int $day = 40): array
    {
        return [
            'primary_model_id' => $primary->id,
            'fallback_model_ids' => array_map(static fn (AiModel $m): string => $m->id, $fallbacks),
            'currency' => $currency,
            'reservation_minor' => $reserve,
            'per_minute' => $minute,
            'per_day' => $day,
        ];
    }

    public function test_guest_and_reader_cannot_access_model_and_task_management(): void
    {
        Http::fake();
        $provider = $this->provider('Permission test');
        $this->get('/admin/ai/model-routes')->assertRedirect('/admin/login');
        $this->post('/admin/ai/models', [
            'provider_id' => $provider->id, 'provider_model_id' => 'forbidden',
            'enabled' => '1', 'supports_text' => '1',
        ])->assertRedirect('/admin/login');
        $this->put('/admin/ai/task-routes/mobile-ai-diagnose', [])
            ->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web');
        $this->get('/admin/ai/model-routes')->assertForbidden();
        $this->post('/admin/ai/models', [
            'provider_id' => $provider->id, 'provider_model_id' => 'forbidden',
            'enabled' => '1', 'supports_text' => '1',
        ])->assertForbidden();
        $this->put('/admin/ai/task-routes/mobile-ai-diagnose', [])
            ->assertForbidden();
        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseCount('ai_routes', 0);
        Http::assertNothingSent();
    }

    public function test_full_local_model_onboarding_route_save_readiness_and_disable_lifecycle(): void
    {
        Http::fake();
        $admin = $this->account();
        $this->actingAs($admin, 'web');
        $response = $this->get('/admin/ai/model-routes?lang=ar')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('dir="rtl"', false)
            ->assertSee('لا يوجد مزود مسجل')
            ->assertSee('غير متاح حاليًا');
        $this->assertStringContainsString('noindex,nofollow', $response->getContent());

        $provider = $this->provider('Local inference provider');
        $this->get('/admin/ai/routing?lang=ar')->assertOk()
            ->assertSee('تسجيل النماذج وربط المهمة');
        $this->get('/admin/ai/providers/'.$provider->id.'?lang=en')->assertOk()
            ->assertSee('Provider models and task routes');

        $primary = $this->createModel($provider, 'diagnosis-primary');
        $fallback = $this->createModel($provider, 'diagnosis-fallback');
        $this->assertSame(['text'], $primary->capabilities_json);
        $this->assertSame([], $primary->cost_policy_json);

        $input = $this->saveTask($primary, [$fallback], reserve: 0, minute: 3, day: 30);
        $this->put('/admin/ai/task-routes/mobile-ai-diagnose?lang=ar', $input)
            ->assertRedirect(route('admin.models.index', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم حفظ مسار التشخيص وحدوده.');

        $route = AiRoute::query()->where('task_type', MobileDiagnosisAdmission::TASK_TYPE)
            ->firstOrFail();
        $this->assertSame($primary->id, $route->primary_model_id);
        $this->assertSame([$fallback->id], $route->fallback_model_ids_json);
        $this->assertSame(['currency' => 'USD', 'reservation_minor' => 0], $route->budget_json);
        $this->assertSame(['per_minute' => 3, 'per_day' => 30], $route->rate_limits_json);
        $this->assertSame($primary->id, app(MobileDiagnosisAdmission::class)->select()['model']->id);

        $this->get('/admin/ai/model-routes?lang=ar')->assertOk()
            ->assertSee('Local inference provider')
            ->assertSee('diagnosis-primary')
            ->assertSee('diagnosis-fallback')
            ->assertSee('يوجد نموذج مؤهل')
            ->assertDontSee('very-private-model-secret');

        $this->patch('/admin/ai/models/'.$primary->id.'?lang=ar', ['enabled' => '0'])
            ->assertRedirect(route('admin.models.index', ['lang' => 'ar']));
        $this->assertSame($fallback->id, app(MobileDiagnosisAdmission::class)->select()['model']->id);

        $this->patch('/admin/ai/models/'.$fallback->id.'?lang=ar', ['enabled' => '0'])
            ->assertRedirect(route('admin.models.index', ['lang' => 'ar']));
        $this->assertNull(app(MobileDiagnosisAdmission::class)->select());
        $this->get('/admin/ai/model-routes?lang=en')->assertOk()
            ->assertSee('Currently unavailable under real admission rules.');

        $this->patch('/admin/ai/models/'.$primary->id.'?lang=ar', ['enabled' => '1'])
            ->assertRedirect();
        $this->assertSame($primary->id, app(MobileDiagnosisAdmission::class)->select()['model']->id);
        Http::assertNothingSent();
    }

    public function test_paid_currency_reservation_and_fallback_validation_protect_saved_route(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $usd = $this->provider('Managed USD', 'openai-compatible', 'USD');
        $kwd = $this->provider('Managed KWD', 'openai-compatible', 'KWD');
        $primary = $this->createModel($usd, 'managed-primary');
        $other = $this->createModel($kwd, 'managed-other');
        $this->assertSame('USD', $primary->cost_policy_json['currency']);
        $this->assertSame('KWD', $other->cost_policy_json['currency']);

        $valid = $this->saveTask($primary, currency: 'USD', reserve: 25);
        $this->put('/admin/ai/task-routes/mobile-ai-diagnose', $valid)->assertRedirect();
        $savedId = AiRoute::query()->firstOrFail()->primary_model_id;
        $this->assertSame($primary->id, $savedId);
        $this->assertSame($primary->id, app(MobileDiagnosisAdmission::class)->select()['model']->id);

        foreach ([
            $this->saveTask($primary, [$other], 'USD', 25),
            $this->saveTask($primary, [], 'KWD', 25),
            $this->saveTask($primary, [], 'USD', 0),
        ] as $invalid) {
            $this->put('/admin/ai/task-routes/mobile-ai-diagnose', $invalid)
                ->assertSessionHasErrors('currency');
            $this->assertSame($savedId, AiRoute::query()->firstOrFail()->primary_model_id);
            $this->assertSame(25, AiRoute::query()->firstOrFail()->budget_json['reservation_minor']);
        }

        foreach ([
            $this->saveTask($primary, [$primary], 'USD', 25),
            $this->saveTask($primary, [$primary, $primary], 'USD', 25),
            $this->saveTask($primary, [], 'USD', 25, minute: 30, day: 5),
            array_merge($valid, ['secret' => 'never-write']),
            array_merge($valid, ['primary_model_id' => 'not-uuid']),
        ] as $invalid) {
            $this->put('/admin/ai/task-routes/mobile-ai-diagnose', $invalid)
                ->assertRedirect()->assertSessionHasErrors();
            $this->assertSame($savedId, AiRoute::query()->firstOrFail()->primary_model_id);
        }
        $this->assertDatabaseCount('ai_routes', 1);
        Http::assertNothingSent();
    }

    public function test_registration_rejects_duplicates_archived_providers_invalid_ids_and_unknown_input(): void
    {
        Http::fake();
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider('Valid registration provider');
        $first = $this->createModel($provider, 'local-1');

        $valid = [
            'provider_id' => $provider->id,
            'provider_model_id' => 'local-1',
            'enabled' => '1',
            'supports_text' => '1',
        ];
        $this->post('/admin/ai/models', $valid)->assertSessionHasErrors('provider_model_id');
        $this->post('/admin/ai/models', array_merge($valid, ['provider_model_id' => '../bad']))
            ->assertSessionHasErrors('provider_model_id');
        $this->post('/admin/ai/models', array_merge($valid, [
            'provider_model_id' => 'local-2', 'secret' => 'danger',
        ]))->assertSessionHasErrors('model');
        $this->patch('/admin/ai/models/'.$first->id, ['enabled' => '1', 'cost_policy_json' => []])
            ->assertSessionHasErrors('model');
        $this->get('/admin/ai/model-routes?lang=xx')
            ->assertRedirect()->assertSessionHasErrors('lang');
        $provider->delete();
        $this->post('/admin/ai/models', array_merge($valid, [
            'provider_model_id' => 'local-2',
        ]))->assertSessionHasErrors('provider_id');
        $this->patch('/admin/ai/models/'.$first->id, ['enabled' => '0'])->assertNotFound();
        $this->assertDatabaseCount('ai_models', 1);
        Http::assertNothingSent();
    }

    public function test_model_page_two_preserves_language_and_reports_only_real_saved_rows(): void
    {
        $this->actingAs($this->account(), 'web');
        $provider = $this->provider('Paginated local provider');
        for ($i = 1; $i <= 27; $i++) {
            AiModel::create([
                'provider_id' => $provider->id,
                'provider_model_id' => 'batch-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'enabled' => true,
                'capabilities_json' => ['text'],
                'cost_policy_json' => [],
            ]);
        }

        $this->get('/admin/ai/model-routes?lang=ar')->assertOk()
            ->assertSee('lang=ar&amp;page=2', false)
            ->assertSee('batch-01')
            ->assertDontSee('<td>batch-27</td>', false);
        $this->get('/admin/ai/model-routes?lang=ar&page=2')->assertOk()
            ->assertSee('batch-27')
            ->assertSee('dir="rtl"', false);
    }
}

<?php

namespace Tests\Feature\Web;

use App\AI\MobileDiagnosisAdmission;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class T016AdminControlPlaneAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function createProvider(array $overrides): AiProvider
    {
        $payload = array_merge([
            'display_name' => 'Acceptance provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.openai.com/v1',
            'secret' => 'acceptance-managed-secret',
            'enabled' => '1',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 1200,
            'currency' => 'USD',
            'routing_priority' => 20,
        ], $overrides);

        $this->post('/admin/ai/providers', $payload)
            ->assertRedirect('/admin/ai/providers')
            ->assertSessionHasNoErrors();

        return AiProvider::query()
            ->where('display_name', $payload['display_name'])
            ->firstOrFail();
    }

    public function test_all_t016_control_plane_surfaces_fail_closed_for_guest_and_reader(): void
    {
        foreach ([
            '/admin',
            '/admin/ai/providers',
            '/admin/ai/routing',
            '/admin/ai/model-routes',
            '/admin/ai/usage',
        ] as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }

        $reader = $this->account('reader');
        $this->actingAs($reader, 'web');

        foreach ([
            '/admin',
            '/admin/ai/providers',
            '/admin/ai/routing',
            '/admin/ai/model-routes',
            '/admin/ai/usage',
        ] as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->post('/admin/ai/providers', [
            'display_name' => 'Forbidden provider',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'enabled' => '1',
        ])->assertForbidden();

        $this->post('/admin/ai/models', [
            'provider_id' => (string) Str::uuid(),
            'provider_model_id' => 'blocked-model',
            'enabled' => '1',
            'supports_text' => '1',
        ])->assertForbidden();

        $this->put('/admin/ai/task-routes/mobile-ai-diagnose', [
            'primary_model_id' => (string) Str::uuid(),
            'currency' => 'USD',
            'reservation_minor' => 1,
            'per_minute' => 1,
            'per_day' => 1,
        ])->assertForbidden();

        $this->assertDatabaseCount('ai_providers', 0);
        $this->assertDatabaseCount('ai_models', 0);
        $this->assertDatabaseCount('ai_routes', 0);
    }

    public function test_owner_can_complete_local_to_managed_provider_lifecycle_with_real_limits_receipts_and_redacted_audit(): void
    {
        $owner = $this->account('owner');
        $this->actingAs($owner, 'web');

        Http::fake([
            'http://127.0.0.1:11434/api/tags' => Http::response([
                'models' => [['name' => 'qwen2.5:7b']],
            ], 200),
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [['id' => 'gpt-4.1-mini']],
            ], 200),
        ]);

        $local = $this->createProvider([
            'display_name' => 'Local Ollama',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret' => '',
            'daily_budget_minor' => 0,
            'routing_priority' => 5,
        ]);
        $managed = $this->createProvider([
            'display_name' => 'Managed AI',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.openai.com/v1',
            'secret' => 'acceptance-managed-secret',
            'daily_budget_minor' => 1200,
            'routing_priority' => 20,
        ]);

        // Credentials remain encrypted at rest and write-only in every UI.
        $this->assertSame('acceptance-managed-secret', $managed->secret_ciphertext);
        $this->assertNotSame(
            'acceptance-managed-secret',
            DB::table('ai_providers')->where('id', $managed->id)
                ->value('secret_ciphertext')
        );

        $this->get('/admin/ai/providers?lang=en')->assertOk()
            ->assertSee('Local Ollama')
            ->assertSee('Managed AI')
            ->assertDontSee('acceptance-managed-secret');
        $this->get('/admin/ai/providers?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مزودو الذكاء الاصطناعي')
            ->assertDontSee('acceptance-managed-secret');

        // Health is an explicit operator action; reads above caused no I/O.
        Http::assertNothingSent();
        $this->post('/admin/ai/providers/'.$local->id.'/test')
            ->assertRedirect('/admin/ai/providers');
        $this->post('/admin/ai/providers/'.$managed->id.'/test')
            ->assertRedirect('/admin/ai/providers');
        Http::assertSentCount(2);
        $this->assertSame('healthy', $local->fresh()->health_status);
        $this->assertSame('healthy', $managed->fresh()->health_status);

        $this->post('/admin/ai/models', [
            'provider_id' => $local->id,
            'provider_model_id' => 'qwen2.5:7b',
            'enabled' => '1',
            'supports_text' => '1',
        ])->assertRedirect(route('admin.models.index'))
            ->assertSessionHas('status', 'Model registered.');
        $this->post('/admin/ai/models', [
            'provider_id' => $managed->id,
            'provider_model_id' => 'gpt-4.1-mini',
            'enabled' => '1',
            'supports_text' => '1',
        ])->assertRedirect(route('admin.models.index'))
            ->assertSessionHas('status', 'Model registered.');

        $localModel = AiModel::query()
            ->where('provider_id', $local->id)->firstOrFail();
        $managedModel = AiModel::query()
            ->where('provider_id', $managed->id)->firstOrFail();
        $this->assertSame(['text'], $localModel->capabilities_json);
        $this->assertSame('USD', $managedModel->cost_policy_json['currency']);

        $this->put('/admin/ai/task-routes/mobile-ai-diagnose?lang=en', [
            'primary_model_id' => $localModel->id,
            'fallback_model_ids' => [$managedModel->id],
            'currency' => 'USD',
            'reservation_minor' => 25,
            'per_minute' => 3,
            'per_day' => 30,
        ])->assertRedirect(route('admin.models.index'))
            ->assertSessionHas('status', 'Diagnosis route and limits saved.');

        $route = AiRoute::query()
            ->where('task_type', MobileDiagnosisAdmission::TASK_TYPE)
            ->firstOrFail();
        $this->assertSame($localModel->id, $route->primary_model_id);
        $this->assertSame([$managedModel->id], $route->fallback_model_ids_json);
        $this->assertSame([
            'currency' => 'USD',
            'reservation_minor' => 25,
        ], $route->budget_json);
        $this->assertSame([
            'per_minute' => 3,
            'per_day' => 30,
        ], $route->rate_limits_json);

        // The same admission selector consumed by the mobile API chooses the
        // healthy local primary at zero paid reserve.
        $selected = app(MobileDiagnosisAdmission::class)->select();
        $this->assertSame($local->id, $selected['provider']->id);
        $this->assertSame($localModel->id, $selected['model']->id);
        $this->assertSame(0, $selected['reserve_minor']);

        // Disabling the local provider invalidates its health and causes a
        // real failover to the healthy managed fallback with the saved reserve.
        $this->patch('/admin/ai/providers/'.$local->id, [
            'enabled' => '0',
        ])->assertRedirect('/admin/ai/providers');
        $selected = app(MobileDiagnosisAdmission::class)->select();
        $this->assertSame($managed->id, $selected['provider']->id);
        $this->assertSame($managedModel->id, $selected['model']->id);
        $this->assertSame(25, $selected['reserve_minor']);
        $this->assertSame(3, $selected['per_minute']);
        $this->assertSame(30, $selected['per_day']);

        // Persist a real receipt row; dashboards must read it rather than
        // inventing traffic, conversion or cross-currency aggregates.
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $jobId,
            'provider_id' => $managed->id,
            'job_type' => MobileDiagnosisAdmission::TASK_TYPE,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('ai_usage')->insert([
            'id' => (string) Str::uuid(),
            'usage_key' => (string) Str::uuid(),
            'job_id' => $jobId,
            'provider_id' => $managed->id,
            'cost_minor' => 7,
            'cost_currency' => 'USD',
            'created_at' => now('UTC'),
        ]);

        $this->get('/admin?lang=en')->assertOk()
            ->assertSee('Administration overview')
            ->assertSee('Persisted AI jobs')
            ->assertSee('Today’s usage (UTC)')
            ->assertSee('USD')
            ->assertSee('7')
            ->assertDontSee('acceptance-managed-secret');
        $this->get('/admin/ai/usage?lang=en')->assertOk()
            ->assertSee('Managed AI')
            ->assertSee('USD')
            ->assertSee('<td>7</td>', false)
            ->assertDontSee('acceptance-managed-secret');
        $this->get('/admin/ai/usage?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('Managed AI')
            ->assertSee('عدد الإيصالات اليوم')
            ->assertDontSee('acceptance-managed-secret');

        $this->get('/admin/ai/model-routes?lang=en')->assertOk()
            ->assertSee('AI models and diagnosis routing')
            ->assertSee('qwen2.5:7b')
            ->assertSee('gpt-4.1-mini')
            ->assertSee('Managed AI')
            ->assertSee('A model is eligible under current recorded state:')
            ->assertDontSee('acceptance-managed-secret');
        $this->get('/admin/ai/model-routes?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('نماذج AI وتوجيه التشخيص')
            ->assertDontSee('acceptance-managed-secret');

        // Provider audit exposes only allowlisted field names, never values or
        // actor PII. All GETs after the two explicit health checks are offline.
        $audit = $this->get('/admin/ai/providers/'.$managed->id.'/audit?lang=en')
            ->assertOk()
            ->assertSee('Recorded change events')
            ->assertSee('Credential replaced')
            ->assertSee('Connection tested')
            ->assertDontSee('acceptance-managed-secret')
            ->assertDontSee($owner->email);
        $this->assertStringContainsString('no-store', $audit->headers->get('Cache-Control'));
        Http::assertSentCount(2);

        $this->assertGreaterThanOrEqual(
            2,
            DB::table('ai_provider_audit_events')
                ->where('provider_id', $managed->id)->count()
        );
    }
}

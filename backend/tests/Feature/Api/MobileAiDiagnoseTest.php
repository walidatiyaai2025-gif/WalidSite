<?php

namespace Tests\Feature\Api;

use App\AI\DurableAiQueue;
use App\AI\MobileDiagnosisDispatcher;
use App\Models\AiDiagnosisRequest;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileAiDiagnoseTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $status = 'active'): User
    {
        return User::create([
            'email' => Str::random(12).'@example.test',
            'status' => $status,
        ]);
    }

    private function token(User $user, string $ability = 'mobile'): string
    {
        return $user->createToken('mobile-diagnosis-test', [$ability], now()->addHour())
            ->plainTextToken;
    }

    private function provider(string $type = 'ollama-compatible', int $budget = 0): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Diagnosis '.Str::random(8),
            'provider_type' => $type,
            'base_url' => $type === 'ollama-compatible'
                ? 'http://127.0.0.1:11434'
                : 'https://provider.example.test/v1',
            'secret_ciphertext' => $type === 'openai-compatible' ? 'fixture-credential' : null,
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
            'daily_budget_minor' => $budget,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    private function model(AiProvider $provider): AiModel
    {
        return AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'technical-selector',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
    }

    private function configure(
        string $type = 'ollama-compatible',
        int $reservation = 0,
        int $budget = 0,
        int $perMinute = 4,
        int $perDay = 40,
    ): array {
        $provider = $this->provider($type, $budget);
        $model = $this->model($provider);
        AiRoute::create([
            'task_type' => 'mobile-ai-diagnose',
            'primary_model_id' => $model->id,
            'fallback_model_ids_json' => [],
            'budget_json' => ['reservation_minor' => $reservation],
            'rate_limits_json' => [
                'per_minute' => $perMinute,
                'per_day' => $perDay,
            ],
        ]);

        return [$provider, $model];
    }

    private function reviewedRunbook(string $slug = 'reviewed-recovery'): string
    {
        $id = (string) Str::uuid();
        $body = '# Reviewed recovery\nUse a bounded, reviewed recovery sequence.';
        $title = 'Reviewed recovery';
        $validated = now()->subMinute();

        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'version' => 1,
            'validated_at' => $validated,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => $validated->copy()->subMinute(),
        ]);

        return $id;
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'question' => 'Why does this database recovery fail after restart?',
            'technology' => 'SQL Server',
            'code_snippet' => 'RESTORE DATABASE sample WITH RECOVERY;',
            'consent_to_process' => true,
        ], $extra);
    }

    public function test_mobile_user_gets_only_reviewed_solution_from_local_ai_selection(): void
    {
        [$provider] = $this->configure();
        $runbook = $this->reviewedRunbook();
        $user = $this->user();
        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode(['runbook_id' => $runbook], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $response = $this->withToken($this->token($user))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percent', 100)
            ->assertJsonPath('data.result_url', '/api/v1/mobile/solutions/'.$runbook)
            ->assertJsonPath('data.retry_after_seconds', null);

        $jobId = $response->json('data.id');
        $this->assertTrue(Str::isUuid($jobId));
        $this->assertDatabaseHas('ai_jobs', [
            'id' => $jobId,
            'provider_id' => $provider->id,
            'job_type' => 'mobile-diagnosis',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('ai_diagnosis_requests', [
            'user_id' => $user->id,
            'job_id' => $jobId,
            'selected_runbook_id' => $runbook,
            'request_blob' => null,
        ]);
        $this->assertSame(0, (int) DB::table('ai_usage')->where('job_id', $jobId)
            ->sum('cost_minor'));
        $this->assertStringNotContainsString('RESTORE DATABASE', $response->getContent());
        $this->assertStringNotContainsString('SQL Server', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        Http::assertSentCount(1);
    }

    public function test_guest_wrong_token_inactive_and_session_only_access_fail_closed(): void
    {
        $this->configure();
        $this->reviewedRunbook();
        Http::fake();

        $this->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertUnauthorized();

        $adminOnly = $this->user();
        $this->withToken($this->token($adminOnly, 'admin'))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertForbidden();

        $inactive = $this->user('inactive');
        $this->withToken($this->token($inactive))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertForbidden();

        $session = $this->user();
        $this->actingAs($session, 'web')
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_diagnosis_requests', 0);
    }

    public function test_consent_unknown_fields_and_bounds_are_rejected_before_ai(): void
    {
        $this->configure();
        $this->reviewedRunbook();
        $user = $this->user();
        Http::fake();

        foreach ([
            $this->payload(['consent_to_process' => false]),
            $this->payload(['unexpected' => 'not-allowed']),
            $this->payload(['question' => 'x']),
            $this->payload(['technology' => str_repeat('t', 121)]),
            $this->payload(['code_snippet' => str_repeat('x', 8001)]),
        ] as $invalid) {
            $this->withToken($this->token($user))
                ->postJson('/api/v1/mobile/ai/diagnose', $invalid)
                ->assertUnprocessable();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_diagnosis_requests', 0);
    }

    public function test_unconfigured_route_or_missing_reviewed_solutions_is_honestly_unavailable(): void
    {
        $user = $this->user();
        Http::fake();

        $this->withToken($this->token($user))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('message', 'AI diagnosis is not currently available.');

        $this->configure();
        $this->withToken($this->token($user))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('message', 'No reviewed solutions are currently available for diagnosis.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_jobs', 0);
    }

    public function test_route_quota_is_per_user_and_blocks_second_request_before_ai(): void
    {
        $this->configure(perMinute: 1, perDay: 5);
        $runbook = $this->reviewedRunbook();
        $firstUser = $this->user();
        $secondUser = $this->user();
        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode(['runbook_id' => $runbook], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $this->withToken($this->token($firstUser))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertOk();
        $this->withToken($this->token($firstUser))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertStatus(429);

        Auth::forgetGuards();
        $this->withToken($this->token($secondUser))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertOk();

        Http::assertSentCount(2);
        $this->assertSame(2, AiDiagnosisRequest::query()->count());
    }

    public function test_paid_provider_records_configured_reservation_and_never_returns_credentials(): void
    {
        [$provider] = $this->configure('openai-compatible', 25, 100);
        $runbook = $this->reviewedRunbook('paid-reviewed');
        $user = $this->user();
        Http::fake([
            'https://provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['runbook_id' => $runbook], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]),
        ]);

        $response = $this->withToken($this->token($user))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $jobId = $response->json('data.id');
        $this->assertSame(25, (int) DB::table('ai_usage')
            ->where('job_id', $jobId)->sum('cost_minor'));
        $this->assertSame($provider->id, DB::table('ai_usage')
            ->where('job_id', $jobId)->value('provider_id'));
        $this->assertStringNotContainsString('fixture-credential', $response->getContent());
        $this->assertStringNotContainsString('provider.example.test', $response->getContent());
        Http::assertSentCount(1);
    }

    public function test_unknown_ai_selection_fails_without_exposing_generated_content(): void
    {
        $this->configure();
        $this->reviewedRunbook();
        $user = $this->user();
        $unknown = (string) Str::uuid();
        Http::fake([
            'http://127.0.0.1:11434/api/generate' => Http::response([
                'response' => json_encode(['runbook_id' => $unknown], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $response = $this->withToken($this->token($user))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.result_url', null)
            ->assertJsonPath('data.progress_percent', 100);

        $this->assertStringNotContainsString($unknown, $response->getContent());
        $this->assertDatabaseHas('ai_diagnosis_requests', [
            'user_id' => $user->id,
            'selected_runbook_id' => null,
            'request_blob' => null,
        ]);
    }

    public function test_bootstrap_advertises_route_only_when_real_diagnosis_policy_is_eligible(): void
    {
        $this->getJson('/api/v1/public/app-bootstrap')
            ->assertOk()
            ->assertJsonPath('data.availability.authenticated_ai_diagnosis', false)
            ->assertJsonPath('data.routes.ai_diagnosis', '/api/v1/mobile/ai/diagnose');

        $this->configure();

        $this->getJson('/api/v1/public/app-bootstrap')
            ->assertOk()
            ->assertJsonPath('data.availability.authenticated_ai_diagnosis', true);
    }

    public function test_expired_private_input_cleanup_never_prints_or_retains_payload(): void
    {
        [, $model] = $this->configure();
        $user = $this->user();
        $private = 'private-code-fragment';
        $request = AiDiagnosisRequest::create([
            'user_id' => $user->id,
            'model_id' => $model->id,
            'request_blob' => Crypt::encryptString(json_encode([
                'question' => 'private question',
                'technology' => 'private stack',
                'code_snippet' => $private,
            ], JSON_THROW_ON_ERROR)),
            'expires_at' => now()->subMinute(),
        ]);

        $raw = DB::table('ai_diagnosis_requests')->where('id', $request->id)
            ->value('request_blob');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($private, $raw);

        $this->assertSame(0, Artisan::call('ai:purge-expired-diagnosis-inputs'));
        $this->assertStringNotContainsString($private, Artisan::output());
        $this->assertNull(DB::table('ai_diagnosis_requests')
            ->where('id', $request->id)->value('request_blob'));
    }

    public function test_queued_diagnosis_refuses_revoked_provider_and_purges_private_input(): void
    {
        [$provider, $model] = $this->configure();
        $user = $this->user();
        $request = AiDiagnosisRequest::create([
            'user_id' => $user->id,
            'model_id' => $model->id,
            'request_blob' => Crypt::encryptString(json_encode([
                'question' => 'Sensitive diagnosis question',
                'technology' => 'SQL Server',
                'code_snippet' => 'private snippet',
            ], JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addHour(),
        ]);
        $request->forceFill(['job_id' => app(DurableAiQueue::class)->enqueue(
            $provider, 'mobile-diagnosis', 'mobile-diagnosis:'.$request->id,
            ['classify'], 0, 1
        )])->save();
        $provider->forceFill(['enabled' => false])->save();
        Http::fake();

        try {
            app(MobileDiagnosisDispatcher::class)->execute((object) [
                'id' => $request->job_id,
                'provider_id' => $provider->id,
            ], 'classify', 'test-worker');
            $this->fail('A revoked provider must not process a queued diagnosis.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($request->fresh()->request_blob);
        $this->assertNull($request->fresh()->selected_runbook_id);
    }

    public function test_expired_queued_diagnosis_clears_ciphertext_without_provider_call(): void
    {
        [$provider, $model] = $this->configure();
        $user = $this->user();
        $request = AiDiagnosisRequest::create([
            'user_id' => $user->id,
            'model_id' => $model->id,
            'request_blob' => Crypt::encryptString('expired-private-input'),
            'expires_at' => now()->subMinute(),
        ]);
        $request->forceFill(['job_id' => app(DurableAiQueue::class)->enqueue(
            $provider, 'mobile-diagnosis', 'mobile-diagnosis:'.$request->id,
            ['classify'], 0, 1
        )])->save();
        Http::fake();

        try {
            app(MobileDiagnosisDispatcher::class)->execute((object) [
                'id' => $request->job_id,
            ], 'classify', 'test-worker');
            $this->fail('Expired diagnosis must not execute.');
        } catch (\DomainException $exception) {
            $this->assertSame('Mobile diagnosis request is unavailable.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($request->fresh()->request_blob);
        $this->assertNull($request->fresh()->selected_runbook_id);
    }

    public function test_diagnosis_expiring_during_catalog_lookup_never_reaches_provider(): void
    {
        [$provider, $model] = $this->configure();
        $user = $this->user();
        $this->reviewedRunbook();
        $request = AiDiagnosisRequest::create([
            'user_id' => $user->id,
            'model_id' => $model->id,
            'request_blob' => Crypt::encryptString(json_encode([
                'question' => 'Sensitive diagnostic question',
                'technology' => 'SQL Server',
                'code_snippet' => 'private-code',
            ], JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addSeconds(2),
        ]);
        $request->forceFill(['job_id' => app(DurableAiQueue::class)->enqueue(
            $provider, 'mobile-diagnosis', 'mobile-diagnosis:'.$request->id,
            ['classify'], 0, 1
        )])->save();
        Http::fake();

        // The initial validity check passes; time advances while catalog data
        // is read, so the final outbound-boundary check must reject the input.
        $runbooks = $this->mock(\App\Http\Controllers\Api\PublicRunbookController::class);
        $runbooks->shouldReceive('index')->once()->andReturnUsing(function () {
            $this->travel(5)->seconds();
            return response()->json(['data' => [[
                'id' => (string) Str::uuid(),
                'title' => 'Reviewed recovery',
                'markdown' => '# Reviewed recovery',
            ]]]);
        });

        try {
            app(MobileDiagnosisDispatcher::class)->execute((object) [
                'id' => $request->job_id,
                'provider_id' => $provider->id,
            ], 'classify', 'test-worker');
            $this->fail('Expired diagnosis must never reach the provider.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mobile diagnosis failed safely.', $exception->getMessage());
        } finally {
            $this->travelBack();
        }

        Http::assertNothingSent();
        $this->assertNull($request->fresh()->request_blob);
        $this->assertNull($request->fresh()->selected_runbook_id);
    }
}

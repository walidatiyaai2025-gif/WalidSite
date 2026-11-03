<?php

namespace Tests\Feature\Api;

use App\Models\AiDiagnosisRequest;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileAiIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'email' => Str::random(12).'@example.test',
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('dedupe-client', ['mobile'], now()->addHour())
            ->plainTextToken;
    }

    private function setupPaidDiagnosis(int $minuteLimit = 1): void
    {
        $provider = AiProvider::create([
            'display_name' => 'Paid fixture provider',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://provider.example.test/v1',
            'secret_ciphertext' => 'fixture-credential',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 2,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 1000,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'paid-diagnosis',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
        AiRoute::create([
            'task_type' => 'mobile-ai-diagnose',
            'primary_model_id' => $model->id,
            'fallback_model_ids_json' => [],
            'budget_json' => ['reservation_minor' => 25, 'currency' => 'USD'],
            'rate_limits_json' => ['per_minute' => $minuteLimit, 'per_day' => 10],
        ]);

        $runbook = (string) Str::uuid();
        $body = '# Reviewed solution'."\n".'A safe recovery checklist.';
        $validated = now()->subMinute();
        DB::table('runbooks')->insert([
            'id' => $runbook,
            'slug' => 'paid-dedupe-fixture',
            'title' => 'Reviewed solution',
            'body_md' => $body,
            'version' => 1,
            'validated_at' => $validated,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $runbook,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => 'Reviewed solution',
            'checksum' => hash('sha256', $body),
            'created_at' => $validated->copy()->subMinute(),
        ]);
        Http::fake([
            'https://provider.example.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => [
                    'content' => json_encode(['runbook_id' => $runbook], JSON_THROW_ON_ERROR),
                ]]],
            ]),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'question' => 'Why does my database fail after restart?',
            'technology' => 'SQL Server',
            'code_snippet' => 'RESTORE DATABASE test WITH RECOVERY;',
            'consent_to_process' => true,
        ], $overrides);
    }

    private function submit(string $token, string $key, ?array $payload = null)
    {
        Auth::forgetGuards();

        return $this->withToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mobile/ai/diagnose', $payload ?? $this->payload());
    }

    public function test_lost_response_identical_retry_replays_one_paid_job_even_after_quota(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $token = $this->token($user);
        $key = (string) Str::uuid();

        // Simulate accepted POST with acknowledgement discarded by the client.
        $accepted = $this->submit($token, $key)->assertOk();
        $id = $accepted->json('data.id');
        $retry = $this->submit($token, $key)
            ->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame($accepted->json('data.status'), $retry->json('data.status'));
        $this->assertDatabaseCount('ai_diagnosis_requests', 1);
        $this->assertDatabaseCount('ai_jobs', 1);
        $this->assertSame(25, (int) DB::table('ai_usage')->sum('cost_minor'));
        $this->assertSame(1, DB::table('ai_usage')->count());
        Http::assertSentCount(1);
        $raw = DB::table('ai_diagnosis_requests')->first();
        $this->assertNotSame($key, $raw->idempotency_key_hash);
        $this->assertSame(64, strlen((string) $raw->idempotency_key_hash));
        $this->assertSame(64, strlen((string) $raw->request_fingerprint));
        $this->assertStringNotContainsString('RESTORE DATABASE', $retry->getContent());
        $this->assertStringContainsString('no-store', $retry->headers->get('Cache-Control', ''));
    }

    public function test_same_key_with_changed_payload_and_withdrawn_consent_fails_closed(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $token = $this->token($user);
        $key = (string) Str::uuid();

        $this->submit($token, $key)->assertOk();
        $this->submit($token, $key, $this->payload(['question' => 'This is another paid diagnosis']))
            ->assertStatus(409)->assertDontSee('RESTORE DATABASE');
        $this->submit($token, $key, $this->payload(['consent_to_process' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('consent_to_process');
        $this->assertDatabaseCount('ai_diagnosis_requests', 1);
        $this->assertDatabaseCount('ai_jobs', 1);
        Http::assertSentCount(1);
    }

    public function test_another_account_cannot_reuse_opaque_key_or_observe_existing_job(): void
    {
        $this->setupPaidDiagnosis();
        $first = $this->user();
        $other = $this->user();
        $key = (string) Str::uuid();
        $accepted = $this->submit($this->token($first), $key)->assertOk();
        $id = $accepted->json('data.id');

        $this->submit($this->token($other), $key)->assertStatus(409)
            ->assertDontSee($id)->assertDontSee('SQL Server');
        $this->assertDatabaseCount('ai_diagnosis_requests', 1);
        Http::assertSentCount(1);
    }

    public function test_revoked_bearer_and_disabled_account_do_not_replay_paid_job(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $token = $user->createToken('revocable-diagnosis', ['mobile'], now()->addHour());
        $key = (string) Str::uuid();
        $this->submit($token->plainTextToken, $key)->assertOk();

        $user->forceFill(['status' => 'inactive'])->save();
        $this->submit($token->plainTextToken, $key)->assertForbidden();
        $user->forceFill(['status' => 'active'])->save();
        $token->accessToken->delete();
        $this->submit($token->plainTextToken, $key)->assertUnauthorized();
        $this->assertDatabaseCount('ai_diagnosis_requests', 1);
        Http::assertSentCount(1);
    }

    public function test_existing_completed_failed_and_queued_states_are_read_only_replays(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $token = $this->token($user);
        $key = (string) Str::uuid();
        $id = $this->submit($token, $key)->assertOk()->json('data.id');
        foreach (['queued', 'failed', 'completed'] as $expected) {
            DB::table('ai_jobs')->where('id', $id)->update(['status' => $expected]);
            $this->submit($token, $key)->assertOk()
                ->assertJsonPath('data.id', $id)
                ->assertJsonPath('data.status', $expected);
        }
        $this->assertDatabaseCount('ai_jobs', 1);
        $this->assertSame(25, (int) DB::table('ai_usage')->sum('cost_minor'));
        Http::assertSentCount(1);
    }

    public function test_database_unique_constraint_prevents_parallel_cross_account_key_admissions(): void
    {
        $this->setupPaidDiagnosis();
        $first = $this->user();
        $second = $this->user();
        $model = AiModel::query()->firstOrFail();
        $digest = hash_hmac('sha256', (string) Str::uuid(), (string) config('app.key'));

        AiDiagnosisRequest::create([
            'user_id' => $first->id,
            'model_id' => $model->id,
            'idempotency_key_hash' => $digest,
            'request_fingerprint' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        AiDiagnosisRequest::create([
            'user_id' => $second->id,
            'model_id' => $model->id,
            'idempotency_key_hash' => $digest,
            'request_fingerprint' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_malformed_header_is_rejected_before_paid_admission(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $this->submit($this->token($user), 'short')->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_jobs', 0);
    }

    public function test_legacy_unkeyed_submission_remains_supported_until_w3_rollout(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $this->withToken($this->token($user))
            ->postJson('/api/v1/mobile/ai/diagnose', $this->payload())
            ->assertOk();
        $this->assertDatabaseCount('ai_diagnosis_requests', 1);
        $this->assertNull(DB::table('ai_diagnosis_requests')->value('idempotency_key_hash'));
    }

    public function test_query_parameters_cannot_supply_required_ai_consent_or_opaque_key(): void
    {
        $this->setupPaidDiagnosis();
        $user = $this->user();
        $token = $this->token($user);
        $key = (string) Str::uuid();
        $payload = $this->payload();
        unset($payload['consent_to_process']);

        Auth::forgetGuards();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mobile/ai/diagnose?lang=ar&consent_to_process=1', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('query');

        Auth::forgetGuards();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mobile/ai/diagnose?lang=en', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('consent_to_process');

        Auth::forgetGuards();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mobile/ai/diagnose?lang[]=en', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('query');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_jobs', 0);
    }

    public function test_distinct_users_with_distinct_keys_get_independent_paid_jobs(): void
    {
        $this->setupPaidDiagnosis();
        $first = $this->user();
        $second = $this->user();

        $a = $this->submit($this->token($first), (string) Str::uuid())
            ->assertOk()->json('data.id');
        $b = $this->submit($this->token($second), (string) Str::uuid())
            ->assertOk()->json('data.id');

        $this->assertNotSame($a, $b);
        $this->assertDatabaseCount('ai_diagnosis_requests', 2);
        $this->assertSame(50, (int) DB::table('ai_usage')->sum('cost_minor'));
        Http::assertSentCount(2);
    }

}

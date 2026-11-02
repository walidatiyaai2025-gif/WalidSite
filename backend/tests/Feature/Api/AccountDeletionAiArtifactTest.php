<?php

namespace Tests\Feature\Api;

use App\Models\AiDiagnosisRequest;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountDeletionAiArtifactTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'PrivacyArtifactStrongPassword.123!';

    private function account(string $label): User
    {
        return User::create([
            'email' => $label.'-'.Str::lower(Str::random(10)).'@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
        ]);
    }

    /** @return array{AiProvider,AiModel} */
    private function configuredModel(): array
    {
        $provider = AiProvider::create([
            'display_name' => 'Account deletion fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.test/v1',
            'secret_ciphertext' => 'fixture-only-not-a-real-credential',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 10000,
            'currency' => 'USD',
            'routing_priority' => 1,
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'privacy-fixture-model',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);

        return [$provider, $model];
    }

    /** Inserts an account-bound diagnosis with a retained opaque job and checkpoint ref. */
    private function diagnosis(
        User $owner,
        AiProvider $provider,
        AiModel $model,
        string $status
    ): string {
        $id = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $id,
            'provider_id' => $provider->id,
            'job_type' => 'mobile-diagnosis',
            'idempotency_key' => 'privacy-erasure-'.$id,
            'payload_hash' => str_repeat('c', 64),
            'status' => $status,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'reserved_budget_minor' => 99,
            'result_ref' => 'private/account-result/'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_job_steps')->insert([
            'id' => (string) Str::uuid(),
            'job_id' => $id,
            'step_key' => 'classify',
            'position' => 0,
            'status' => $status === 'queued' ? 'pending' : 'completed',
            'output_ref' => 'private/checkpoint/'.$id,
            'attempt_no' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($status === 'running') {
            DB::table('job_leases')->insert([
                'id' => (string) Str::uuid(),
                'job_id' => $id,
                'owner_key' => 'privacy-worker',
                'expires_at' => now()->addMinute(),
                'lease_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        AiDiagnosisRequest::create([
            'user_id' => $owner->id,
            'job_id' => $id,
            'model_id' => $model->id,
            'request_blob' => 'encrypted-test-private-diagnosis',
            'expires_at' => now()->addHour(),
        ]);

        return $id;
    }

    public function test_deletion_scrubs_completed_and_inflight_refs_without_erasing_other_accounts_or_billing(): void
    {
        [$provider, $model] = $this->configuredModel();
        $owner = $this->account('delete');
        $other = $this->account('keep');
        $token = $owner->createToken('privacy-delete', ['mobile'], now()->addHour())
            ->plainTextToken;
        $owner->createToken('second-device', ['mobile'], now()->addHour());

        $owned = [];
        foreach (['queued', 'running', 'retry', 'completed', 'failed', 'cancelled'] as $status) {
            $owned[$status] = $this->diagnosis($owner, $provider, $model, $status);
        }
        $foreign = $this->diagnosis($other, $provider, $model, 'completed');

        foreach ([$owned['completed'], $foreign] as $id) {
            DB::table('ai_usage')->insert([
                'id' => (string) Str::uuid(),
                'usage_key' => 'privacy-billed-'.$id,
                'job_id' => $id,
                'provider_id' => $provider->id,
                'cost_currency' => 'USD',
                'cost_minor' => 19,
                'created_at' => now(),
            ]);
        }

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ])->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.cancelled_ai_jobs', 3)
            ->assertJsonPath('data.tokens_revoked', true);

        foreach ($owned as $oldStatus => $id) {
            $expectedStatus = in_array($oldStatus, ['queued', 'running', 'retry'], true)
                ? 'cancelled' : $oldStatus;
            $this->assertDatabaseHas('ai_jobs', [
                'id' => $id,
                'status' => $expectedStatus,
                'result_ref' => null,
            ]);
            $this->assertDatabaseHas('ai_job_steps', [
                'job_id' => $id,
                'output_ref' => null,
            ]);
            $this->assertDatabaseMissing('job_leases', ['job_id' => $id]);
        }
        $this->assertDatabaseHas('ai_jobs', [
            'id' => $foreign,
            'status' => 'completed',
            'result_ref' => 'private/account-result/'.$foreign,
        ]);
        $this->assertDatabaseHas('ai_job_steps', [
            'job_id' => $foreign,
            'output_ref' => 'private/checkpoint/'.$foreign,
        ]);
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $owned['completed'],
            'cost_minor' => 19,
        ]);
        $this->assertDatabaseHas('ai_usage', [
            'job_id' => $foreign,
            'cost_minor' => 19,
        ]);
        $this->assertDatabaseMissing('ai_diagnosis_requests', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('ai_diagnosis_requests', ['user_id' => $other->id]);
        $this->assertSame('deleted', $owner->fresh()->status);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertDatabaseCount('account_deletion_events', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export')
            ->assertUnauthorized();
    }

    public function test_failed_reauthentication_and_foreign_id_do_not_scrub_any_artifacts(): void
    {
        [$provider, $model] = $this->configuredModel();
        $owner = $this->account('blocked');
        $other = $this->account('unrelated');
        $token = $owner->createToken('privacy-delete', ['mobile'], now()->addHour())
            ->plainTextToken;
        $ownId = $this->diagnosis($owner, $provider, $model, 'completed');
        $otherId = $this->diagnosis($other, $provider, $model, 'completed');

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => 'wrong-password',
                'confirmation' => 'DELETE',
            ])->assertUnprocessable();
        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'user_id' => $other->id,
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ])->assertUnprocessable();

        foreach ([$ownId, $otherId] as $id) {
            $this->assertDatabaseHas('ai_jobs', [
                'id' => $id,
                'status' => 'completed',
                'result_ref' => 'private/account-result/'.$id,
            ]);
            $this->assertDatabaseHas('ai_job_steps', [
                'job_id' => $id,
                'output_ref' => 'private/checkpoint/'.$id,
            ]);
        }
        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertDatabaseCount('account_deletion_events', 0);
    }
}

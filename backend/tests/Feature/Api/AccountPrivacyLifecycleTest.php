<?php

namespace Tests\Feature\Api;

use App\Domain\Privacy\NotificationConsentGate;
use App\Models\AiDiagnosisRequest;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountPrivacyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'VeryLongPrivacyPassword.123!';

    private function user(string $label, string $status = 'active'): User
    {
        return User::create([
            'email' => $label.'-'.Str::lower(Str::random(8)).'@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => $status,
        ]);
    }

    private function mobileToken(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken(
            'privacy-test',
            ['mobile'],
            $expiresAt ?? now()->addHour()
        )->plainTextToken;
    }

    public function test_export_is_bounded_to_the_authenticated_owner_and_excludes_secrets(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $token = $this->mobileToken($owner);

        DB::table('saved_resources')->insert([
            [
                'id' => (string) Str::uuid(),
                'user_id' => $owner->id,
                'resource_type' => 'article',
                'resource_id' => (string) Str::uuid(),
                'saved_at' => now()->subMinute(),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $other->id,
                'resource_type' => 'runbook',
                'resource_id' => (string) Str::uuid(),
                'saved_at' => now(),
            ],
        ]);
        NotificationPreference::create([
            'user_id' => $owner->id,
            'purpose' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
            'consented_at' => now()->subHour(),
        ]);
        NotificationPreference::create([
            'user_id' => $other->id,
            'purpose' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
            'consented_at' => now()->subHour(),
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export');

        $response->assertOk()
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="walidsite-account-export.json"'
            )
            ->assertJsonPath('data.profile.id', $owner->id)
            ->assertJsonPath('data.profile.email', $owner->email)
            ->assertJsonCount(1, 'data.saved_resources')
            ->assertJsonPath('data.notification_preferences.0.purpose', 'product_updates')
            ->assertJsonPath('meta.secrets_excluded', true)
            ->assertJsonMissingPath('data.profile.password_hash');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
        $this->assertStringNotContainsString($other->email, $response->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());

        $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export?user_id='.$other->id)
            ->assertUnprocessable();
    }

    public function test_notification_consent_is_default_deny_and_revocation_is_immediate(): void
    {
        $user = $this->user('consent');
        $token = $this->mobileToken($user);
        $gate = app(NotificationConsentGate::class);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/notification-preferences')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('meta.default_deny', true);

        $this->assertFalse($gate->allows($user, 'product_updates', 'push'));

        $this->withToken($token)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [[
                    'purpose' => 'product_updates',
                    'channel' => 'push',
                    'enabled' => true,
                ]],
            ])->assertOk();

        $user->refresh();
        $this->assertTrue($gate->allows($user, 'product_updates', 'push'));

        $this->withToken($token)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [[
                    'purpose' => 'product_updates',
                    'channel' => 'push',
                    'enabled' => false,
                ]],
            ])->assertOk();

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'product_updates')
            ->where('channel', 'push')
            ->firstOrFail();

        $this->assertFalse($preference->enabled);
        $this->assertNotNull($preference->revoked_at);
        $this->assertFalse($gate->allows($user->refresh(), 'product_updates', 'push'));

        $user->forceFill(['status' => 'inactive'])->save();
        $this->assertFalse($gate->allows($user->refresh(), 'product_updates', 'push'));
        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/mobile/notification-preferences')
            ->assertForbidden();
    }

    public function test_account_delete_reauth_erases_owned_pii_cancels_ai_and_revokes_all_tokens(): void
    {
        $user = $this->user('delete');
        $originalEmail = $user->email;
        $other = $this->user('keep');
        $token = $this->mobileToken($user);
        $user->createToken('second-device', ['mobile'], now()->addHour());

        DB::table('saved_resources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'resource_type' => 'article',
            'resource_id' => (string) Str::uuid(),
            'saved_at' => now(),
        ]);
        DB::table('saved_resources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $other->id,
            'resource_type' => 'article',
            'resource_id' => (string) Str::uuid(),
            'saved_at' => now(),
        ]);

        NotificationPreference::create([
            'user_id' => $user->id,
            'purpose' => 'editorial_updates',
            'channel' => 'email',
            'enabled' => true,
            'consented_at' => now(),
        ]);

        $provider = AiProvider::create([
            'display_name' => 'Privacy race provider '.Str::random(6),
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.test/v1',
            'secret_ciphertext' => 'test-secret',
            'enabled' => true,
            'health_status' => 'healthy',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 1000,
            'currency' => 'USD',
            'routing_priority' => 1,
        ]);
        $model = AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => 'privacy-model',
            'capabilities_json' => ['text'],
            'enabled' => true,
            'cost_policy_json' => ['currency' => 'USD'],
        ]);
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id' => $jobId,
            'provider_id' => $provider->id,
            'job_type' => 'mobile-diagnosis',
            'idempotency_key' => 'privacy-delete-'.$jobId,
            'payload_hash' => str_repeat('a', 64),
            'status' => 'running',
            'attempt_count' => 1,
            'max_attempts' => 3,
            'reserved_budget_minor' => 10,
            'result_ref' => 'private/result/ref',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('job_leases')->insert([
            'id' => (string) Str::uuid(),
            'job_id' => $jobId,
            'owner_key' => 'privacy-test-worker',
            'expires_at' => now()->addMinute(),
            'lease_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AiDiagnosisRequest::create([
            'user_id' => $user->id,
            'job_id' => $jobId,
            'model_id' => $model->id,
            'request_blob' => 'encrypted-private-question',
            'expires_at' => now()->addHour(),
        ]);

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.tokens_revoked', true)
            ->assertJsonPath('data.cancelled_ai_jobs', 1);

        $deleted = User::query()->findOrFail($user->id);
        $this->assertSame('deleted', $deleted->status);
        $this->assertNotSame($originalEmail, $deleted->email);
        $this->assertNull($deleted->password_hash);

        $this->assertDatabaseMissing('users', ['email' => $originalEmail]);
        $this->assertDatabaseMissing('saved_resources', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('ai_diagnosis_requests', ['user_id' => $user->id]);
        $this->assertDatabaseHas('ai_jobs', [
            'id' => $jobId,
            'status' => 'cancelled',
            'result_ref' => null,
            'last_error_code' => 'account_deleted',
        ]);
        $this->assertDatabaseMissing('job_leases', ['job_id' => $jobId]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('account_deletion_events', 1);
        $this->assertDatabaseHas('saved_resources', ['user_id' => $other->id]);

        $audit = json_encode(
            DB::table('account_deletion_events')->first(),
            JSON_THROW_ON_ERROR
        );
        $this->assertStringNotContainsString($originalEmail, $audit);
        $this->assertStringNotContainsString(self::PASSWORD, $audit);

        Auth::forgetGuards();
        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ])->assertUnauthorized();
        $this->assertDatabaseCount('account_deletion_events', 1);
    }

    public function test_delete_rejects_idor_wrong_password_expired_tokens_and_rate_abuse(): void
    {
        $user = $this->user('guarded');
        $other = $this->user('foreign');
        $token = $this->mobileToken($user);

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'user_id' => $other->id,
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ])->assertUnprocessable();

        $this->assertSame('active', $other->fresh()->status);

        // The preceding IDOR-shaped request consumes one attempt on the same
        // authenticated throttle key, leaving two validation attempts.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->withToken($token)
                ->deleteJson('/api/v1/mobile/account', [
                    'password' => 'wrong-password',
                    'confirmation' => 'DELETE',
                ])->assertUnprocessable();
        }

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => 'wrong-password',
                'confirmation' => 'DELETE',
            ])->assertTooManyRequests();

        $expiredUser = $this->user('expired');
        $expired = $this->mobileToken($expiredUser, now()->subMinute());
        Auth::forgetGuards();
        $this->withToken($expired)
            ->getJson('/api/v1/mobile/account/export')
            ->assertUnauthorized();
    }

    public function test_admin_audience_counts_distinct_active_opted_in_users_only(): void
    {
        $admin = $this->user('admin');
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $adminToken = $admin->createToken('admin', ['admin'], now()->addHour())->plainTextToken;

        $consented = $this->user('consented');
        foreach (['email', 'push'] as $channel) {
            NotificationPreference::create([
                'user_id' => $consented->id,
                'purpose' => 'product_updates',
                'channel' => $channel,
                'enabled' => true,
                'consented_at' => now(),
            ]);
        }

        $revoked = $this->user('revoked');
        NotificationPreference::create([
            'user_id' => $revoked->id,
            'purpose' => 'product_updates',
            'channel' => 'email',
            'enabled' => false,
            'consented_at' => now()->subHour(),
            'revoked_at' => now(),
        ]);

        $inactive = $this->user('inactive', 'inactive');
        NotificationPreference::create([
            'user_id' => $inactive->id,
            'purpose' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
            'consented_at' => now(),
        ]);

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/audience')
            ->assertOk()
            ->assertJsonPath('data.notifications.consent_storage_available', true)
            ->assertJsonPath('data.notifications.consented_recipients', 1)
            ->assertJsonPath('data.notifications.delivery_enabled', false);
    }
}

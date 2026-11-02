<?php

namespace Tests\Feature\Api;

use App\Domain\Privacy\NotificationConsentGate;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationConsentEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $label, string $status = 'active'): User
    {
        return User::create([
            'email' => $label.'-'.Str::lower(Str::random(10)).'@example.test',
            'status' => $status,
            'password_hash' => null,
        ]);
    }

    private function preference(
        User $user,
        string $purpose = 'product_updates',
        string $channel = 'email',
        bool $enabled = true,
        ?\DateTimeInterface $consentedAt = null,
        ?\DateTimeInterface $revokedAt = null,
    ): NotificationPreference {
        return NotificationPreference::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'channel' => $channel,
            'enabled' => $enabled,
            'consented_at' => $consentedAt,
            'revoked_at' => $revokedAt,
        ]);
    }

    public function test_consent_gate_denies_missing_future_revoked_disabled_unknown_and_stale_accounts(): void
    {
        $user = $this->account('consent');
        $gate = app(NotificationConsentGate::class);

        $this->assertFalse($gate->allows($user, 'product_updates', 'email'));
        $preference = $this->preference($user, consentedAt: now('UTC')->addDay());
        $this->assertFalse($gate->allows($user, 'product_updates', 'email'));

        $preference->forceFill(['consented_at' => now('UTC')->subMinute()])->save();
        $this->assertTrue($gate->allows($user, 'product_updates', 'email'));
        $this->assertFalse($gate->allows($user, 'product_updates', 'push'));
        $this->assertFalse($gate->allows($user, 'unknown', 'email'));
        $this->assertFalse($gate->allows($user, 'product_updates', 'sms'));

        $preference->forceFill(['revoked_at' => now('UTC')])->save();
        $this->assertFalse($gate->allows($user, 'product_updates', 'email'));

        $preference->forceFill(['revoked_at' => null, 'enabled' => false])->save();
        $this->assertFalse($gate->allows($user, 'product_updates', 'email'));

        $preference->forceFill(['enabled' => true])->save();
        $this->assertTrue($gate->allows($user, 'product_updates', 'email'));

        // The caller still holds the original active User model.
        DB::table('users')->where('id', $user->id)->update(['status' => 'inactive']);
        $this->assertSame('active', $user->status);
        $this->assertFalse($gate->allows($user, 'product_updates', 'email'));

        DB::table('users')->where('id', $user->id)->update(['status' => 'deleted']);
        $this->assertFalse($gate->allows($user, 'product_updates', 'email'));
    }

    public function test_admin_json_audience_counts_only_current_consent_of_active_users(): void
    {
        $admin = $this->account('owner');
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $token = $admin->createToken('admin-audience', ['admin'], now()->addHour())
            ->plainTextToken;

        $eligible = $this->account('eligible');
        $this->preference($eligible, consentedAt: now('UTC')->subHour());
        $this->preference($eligible, channel: 'push', consentedAt: now('UTC')->subMinute());

        $future = $this->account('future');
        $this->preference($future, consentedAt: now('UTC')->addDay());

        $missing = $this->account('missing');
        $this->preference($missing);

        $revoked = $this->account('revoked');
        $this->preference($revoked,
            consentedAt: now('UTC')->subHour(), revokedAt: now('UTC'));

        $disabled = $this->account('disabled');
        $this->preference($disabled, enabled: false,
            consentedAt: now('UTC')->subHour());

        $inactive = $this->account('inactive', 'inactive');
        $this->preference($inactive, consentedAt: now('UTC')->subHour());

        $response = $this->withToken($token)->getJson('/api/v1/admin/audience');
        $response->assertOk()
            ->assertJsonPath('data.notifications.consent_storage_available', true)
            ->assertJsonPath('data.notifications.consented_recipients', 1)
            ->assertJsonPath('data.notifications.delivery_enabled', false)
            ->assertJsonPath('meta.no_push_is_sent_by_this_read_endpoint', true)
            ->assertDontSee($eligible->email)
            ->assertDontSee($future->email);
    }

    public function test_explicit_mobile_reopt_in_repairs_future_timestamp_then_revoke_is_immediate(): void
    {
        $user = $this->account('reconsent');
        $token = $user->createToken('mobile-consent', ['mobile'], now()->addHour())
            ->plainTextToken;
        $original = $this->preference($user,
            consentedAt: now('UTC')->addDay());

        $this->withToken($token)->getJson('/api/v1/mobile/notification-preferences')
            ->assertOk()
            ->assertJsonPath('meta.default_deny', true)
            ->assertJsonPath('data.4.purpose', 'product_updates')
            ->assertJsonPath('data.4.channel', 'email')
            ->assertJsonPath('data.4.enabled', false);

        $payload = ['preferences' => [[
            'purpose' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
        ]]];
        $this->withToken($token)
            ->putJson('/api/v1/mobile/notification-preferences', $payload)
            ->assertOk()->assertJsonPath('data.4.enabled', true);

        $record = $original->fresh();
        $this->assertNotNull($record->consented_at);
        $this->assertTrue($record->consented_at->lessThanOrEqualTo(now('UTC')));
        $this->assertNull($record->revoked_at);
        $this->assertTrue(app(NotificationConsentGate::class)
            ->allows($user, 'product_updates', 'email'));

        $this->withToken($token)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [[
                    'purpose' => 'product_updates',
                    'channel' => 'email',
                    'enabled' => false,
                ]],
            ])->assertOk()->assertJsonPath('data.4.enabled', false);

        $this->assertNotNull($record->fresh()->revoked_at);
        $this->assertFalse(app(NotificationConsentGate::class)
            ->allows($user, 'product_updates', 'email'));
    }
}

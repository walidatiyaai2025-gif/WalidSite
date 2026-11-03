<?php

namespace Tests\Feature\Api;

use App\Domain\Privacy\NotificationConsentGate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationConsentTransitionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        return User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password_hash' => Hash::make('fixture-only-strong-password-1!'),
            'status' => 'active',
        ]);
    }

    private function change(string $bearer, bool $enabled): void
    {
        $this->withToken($bearer)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [[
                    'purpose' => 'editorial_updates',
                    'channel' => 'push',
                    'enabled' => $enabled,
                ]],
            ])->assertOk();
    }

    public function test_grant_revoke_regrant_and_idempotent_replays(): void
    {
        $user = $this->account();
        $bearer = $user->createToken('audit-fixture', ['mobile'], now()->addHour())
            ->plainTextToken;
        $gate = app(NotificationConsentGate::class);

        $this->change($bearer, false);
        $this->assertDatabaseCount('notification_consent_events', 0);
        $this->travel(1)->seconds();
        $this->change($bearer, true);
        $this->assertTrue($gate->allows($user, 'editorial_updates', 'push'));
        $this->travel(1)->seconds();
        $this->change($bearer, true);
        $this->assertDatabaseCount('notification_consent_events', 1);
        $this->travel(1)->seconds();
        $this->change($bearer, false);
        $this->assertFalse($gate->allows($user, 'editorial_updates', 'push'));
        $this->travel(1)->seconds();
        $this->change($bearer, false);
        $this->assertDatabaseCount('notification_consent_events', 2);
        $this->travel(1)->seconds();
        $this->change($bearer, true);
        $this->assertTrue($gate->allows($user, 'editorial_updates', 'push'));

        $events = DB::table('notification_consent_events')
            ->where('user_id', $user->id)->orderBy('occurred_at')->get();
        $this->assertSame(['granted', 'revoked', 'granted'],
            $events->pluck('transition')->all());
        $this->assertCount(3, $events);
        $this->assertStringNotContainsString(
            $user->email, json_encode($events, JSON_THROW_ON_ERROR)
        );
    }

    public function test_invalid_batch_does_not_change_preferences_or_audit(): void
    {
        $user = $this->account();
        $bearer = $user->createToken('invalid-fixture', ['mobile'], now()->addHour())
            ->plainTextToken;
        $this->withToken($bearer)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [
                    ['purpose' => 'product_updates', 'channel' => 'email', 'enabled' => true],
                    ['purpose' => 'unrecognized', 'channel' => 'push', 'enabled' => true],
                ],
            ])->assertUnprocessable();
        $this->assertDatabaseCount('notification_preferences', 0);
        $this->assertDatabaseCount('notification_consent_events', 0);
    }

    public function test_account_erasure_removes_only_its_consent_timeline(): void
    {
        $subject = $this->account();
        $other = $this->account();
        $subjectBearer = $subject->createToken('erase-fixture', ['mobile'], now()->addHour())
            ->plainTextToken;
        $otherBearer = $other->createToken('keep-fixture', ['mobile'], now()->addHour())
            ->plainTextToken;
        $this->change($subjectBearer, true);
        $this->change($subjectBearer, false);
        $this->change($subjectBearer, true);
        $this->change($otherBearer, true);
        $this->assertSame(3, DB::table('notification_consent_events')
            ->where('user_id', $subject->id)->count());

        $this->withToken($subjectBearer)->deleteJson('/api/v1/mobile/account', [
            'password' => 'fixture-only-strong-password-1!',
            'confirmation' => 'DELETE',
        ])->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('notification_consent_events', [
            'user_id' => $subject->id,
        ]);
        $this->assertDatabaseHas('notification_consent_events', [
            'user_id' => $other->id,
            'transition' => 'granted',
        ]);
    }
}

<?php

namespace Tests\Feature\Web;

use App\Domain\Privacy\AudienceConsentPreview;
use App\Domain\Privacy\NotificationConsentGate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADMIN-10 / G-16: live account opt-in/out -> privacy-safe Admin preview.
 * Neither this test nor the shipped view executes a delivery provider.
 */
final class AdminAudienceConsentPreviewJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
    }

    private function user(string $role = 'reader', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'password_hash' => null,
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function imported(User $user, bool $enabled, ?string $consentedAt, ?string $revokedAt): void
    {
        DB::table('notification_preferences')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'purpose' => 'editorial_updates',
            'channel' => 'push',
            'enabled' => $enabled,
            'consented_at' => $consentedAt,
            'revoked_at' => $revokedAt,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function change(User $user, bool $enabled): void
    {
        Auth::forgetGuards();
        $bearer = $user->createToken('preview-fixture', ['mobile'], now()->addHour())->plainTextToken;
        $this->withToken($bearer)->putJson('/api/v1/mobile/notification-preferences', [
            'preferences' => [[
                'purpose' => 'editorial_updates',
                'channel' => 'push',
                'enabled' => $enabled,
            ]],
        ])->assertOk();
    }

    private function assertBuckets(array $expected, string $lang): void
    {
        $page = $this->get('/admin/audience?lang='.$lang.'&purpose=editorial_updates&channel=push')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('dir="'.($lang === 'ar' ? 'rtl' : 'ltr').'"', false)
            ->assertSee('Delivery is disabled', false, $lang === 'en')
            ->assertDontSee('PRIVATE_DELIVERY_TOKEN');
        foreach ($expected as $bucket => $count) {
            $this->assertStringContainsString(
                'data-testid="audience-'.$bucket.'">'.$count.'</strong>',
                $page->getContent(),
            );
        }
    }

    public function test_mobile_grant_revoke_and_regrant_change_only_live_eligible_admin_counts(): void
    {
        $owner = $this->user('owner');
        $granted = $this->user();
        $revoked = $this->user();
        $disabled = $this->user();
        $future = $this->user();
        $missing = $this->user();
        $invalid = $this->user();
        $suspended = $this->user('reader', 'suspended');

        // Production account endpoint is the only source of a new opt-in.
        $this->change($granted, true);
        $this->change($revoked, true);
        $this->change($revoked, false);

        $this->imported($disabled, false, now('UTC')->subDay()->toDateTimeString(), null);
        $this->imported($future, true, now('UTC')->addDay()->toDateTimeString(), null);
        $this->imported($invalid, true, null, null);
        $this->imported($suspended, true, now('UTC')->subDay()->toDateTimeString(), null);

        $gate = app(NotificationConsentGate::class);
        $this->assertTrue($gate->allows($granted, 'editorial_updates', 'push'));
        foreach ([$revoked, $disabled, $future, $missing, $invalid, $suspended] as $account) {
            $this->assertFalse($gate->allows($account, 'editorial_updates', 'push'));
        }

        Auth::forgetGuards();
        $this->withoutHeader('Authorization')->actingAs($owner, 'web');
        $expected = [
            'active' => 7, 'consented' => 1, 'suppressed' => 6,
            'revoked' => 1, 'disabled' => 1, 'future' => 1, 'missing' => 3,
        ];
        foreach (['en', 'ar'] as $locale) {
            $this->assertBuckets($expected, $locale);
        }
        $html = $this->get('/admin/audience')->assertOk()->getContent();
        foreach ([$granted, $revoked, $disabled, $future, $missing, $invalid, $suspended] as $account) {
            $this->assertStringNotContainsString($account->email, $html);
            $this->assertStringNotContainsString($account->id, $html);
        }
        $this->assertStringNotContainsString('PRIVATE_DELIVERY_TOKEN', $html);

        // Changing filter must never attribute Push opt-in to email.
        $email = $this->get('/admin/audience?channel=email&purpose=editorial_updates')->assertOk();
        $this->assertStringContainsString('data-testid="audience-consented">0</strong>', $email->getContent());
        $this->assertStringContainsString('data-testid="audience-missing">7</strong>', $email->getContent());

        // Regrant through the authenticated Mobile API: owner cannot opt
        // somebody in, but the next Admin request must observe the real change.
        Auth::forgetGuards();
        $this->change($revoked, true);
        $this->withoutHeader('Authorization');
        Auth::forgetGuards();
        $this->actingAs($owner, 'web');
        $this->assertBuckets([
            'active' => 7, 'consented' => 2, 'suppressed' => 5,
            'revoked' => 0, 'disabled' => 1, 'future' => 1, 'missing' => 3,
        ], 'en');
        $this->assertSame(3, DB::table('notification_consent_events')
            ->where('user_id', $revoked->id)->count());
        Http::assertNothingSent();
    }

    public function test_audience_snapshot_is_exclusive_read_only_and_rejects_invalid_segments(): void
    {
        $owner = $this->user('owner');
        $this->user('reader');
        $preview = app(AudienceConsentPreview::class);
        $this->assertSame([
            'active_accounts' => 2, 'consented' => 0, 'revoked' => 0,
            'disabled' => 0, 'future' => 0, 'missing' => 2, 'suppressed' => 2,
        ], $preview->forSegment('product_updates', 'email'));
        $this->expectException(\InvalidArgumentException::class);
        $preview->forSegment('unspecified', 'email');
    }

    public function test_preview_is_admin_only_and_cannot_send_or_look_up_an_account(): void
    {
        $reader = $this->user('reader');
        $owner = $this->user('owner');

        $this->get('/admin/audience')->assertRedirect('/admin/login');
        $this->actingAs($reader, 'web')->get('/admin/audience')->assertForbidden();
        $this->actingAs($owner, 'web');
        $this->get('/admin/audience?purpose[]=editorial_updates')
            ->assertRedirect()->assertSessionHasErrors('purpose');
        $this->get('/admin/audience?channel=webhook')
            ->assertRedirect()->assertSessionHasErrors('channel');
        $this->post('/admin/audience', ['recipient' => $reader->id])
            ->assertStatus(405);
        $this->get('/admin/audience?channel=push&purpose=editorial_updates')->assertOk()
            ->assertSee('Delivery is disabled')
            ->assertDontSee($reader->email)
            ->assertDontSee($reader->id);
        Http::assertNothingSent();
    }
}

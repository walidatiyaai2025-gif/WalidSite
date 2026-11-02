<?php

namespace Tests\Feature\Web;

use App\Domain\Privacy\NotificationConsentGate;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G-16: prove the public privacy journey describes functioning, secure
 * account controls without silently converting contact consent to marketing.
 */
final class PrivacyConsentRealJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'VerifiedPrivacyPassword.123!';

    private function account(string $role = 'reader'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(14)).'@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    public function test_bilingual_disclosures_link_actual_mobile_controls_and_remain_unapproved_drafts(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $ar = $locale === 'ar';
            $privacy = $this->get('/privacy?lang='.$locale)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('<meta name="robots" content="noindex,follow">', false)
                ->assertSee('id="account-controls"', false)
                ->assertSee('dir="'.($ar ? 'rtl' : 'ltr').'"', false)
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $locale, 'topic' => 'privacy',
                ])).'"', false)
                ->assertDontSee('name="privacy_consent" value="1" checked', false);

            $privacy->assertSee($ar ? 'الخصوصية والموافقات وبياناتي' : 'Privacy, consent and my data')
                ->assertSee($ar ? 'موافقة' : 'consent')
                ->assertSee('DELETE')
                ->assertSee($ar ? 'غير معتمدة قانونيًا' : 'not legally approved')
                ->assertDontSee('password_hash')
                ->assertDontSee('Bearer ');

            $this->get('/terms?lang='.$locale)->assertOk()
                ->assertSee($ar ? 'إلغاء موافقات' : 'notification-consent revocation')
                ->assertSee($ar ? 'مسودة وليست شروطًا قانونية نافذة' : 'not effective legal terms')
                ->assertSee('<meta name="robots" content="noindex,follow">', false);

            $this->get('/contact?lang='.$locale.'&topic=privacy')->assertOk()
                ->assertSee('href="'.e(route('public.privacy', [
                    'lang' => $locale,
                ])).'#account-controls"', false)
                ->assertSee($ar ? 'لا تشترك بك' : 'does not subscribe you')
                ->assertSee('name="privacy_consent"', false)
                ->assertDontSee('value="1" checked', false);
        }

        $this->get('/sitemap.xml')->assertOk()
            ->assertDontSee('/privacy?')
            ->assertDontSee('/terms?');
    }

    public function test_contact_consent_is_not_notification_opt_in_even_when_mail_accepts_it(): void
    {
        config([
            'mail.contact_recipient' => 'reviewed-owner@example.test',
            'mail.default' => 'smtp',
        ]);
        Mail::fake();
        $payload = [
            'name' => 'Privacy Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Consent clarification',
            'message' => 'This is a private contact question, not a subscription.',
            'privacy_consent' => '1',
        ];
        $this->post('/contact?lang=en', array_diff_key($payload, ['privacy_consent' => 1]))
            ->assertRedirect();
        Mail::assertNothingSent();

        $this->post('/contact?lang=en', $payload)->assertRedirect('/contact?lang=en')
            ->assertSessionHas('contact_delivered', true);
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('notification_preferences', 0);

        // No public visitor may read or change authenticated preferences.
        $this->getJson('/api/v1/mobile/notification-preferences')
            ->assertUnauthorized();
        $this->putJson('/api/v1/mobile/notification-preferences', [
            'preferences' => [[
                'purpose' => 'product_updates',
                'channel' => 'email',
                'enabled' => true,
            ]],
        ])->assertUnauthorized();
    }

    public function test_mobile_opt_in_revoke_and_admin_audience_use_one_persisted_consent_source(): void
    {
        $reader = $this->account();
        $admin = $this->account('admin');
        $token = $reader->createToken('privacy-web-journey', ['mobile'], now()->addHour())
            ->plainTextToken;
        $gate = app(NotificationConsentGate::class);

        $this->assertFalse($gate->allows($reader, 'product_updates', 'email'));
        $this->withToken($token)
            ->getJson('/api/v1/mobile/notification-preferences')->assertOk()
            ->assertJsonPath('meta.default_deny', true)
            ->assertJsonCount(6, 'data');

        $this->withToken($token)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [[
                    'purpose' => 'product_updates',
                    'channel' => 'email',
                    'enabled' => true,
                ]],
            ])->assertOk();
        $this->assertTrue($gate->allows($reader->refresh(), 'product_updates', 'email'));

        // Audience is read-only, scoped and cannot opt a reader in for them.
        $this->actingAs($admin, 'web');
        $this->get('/admin/audience?purpose=product_updates&channel=email')->assertOk()
            ->assertSee('Consented recipients')
            ->assertSee('<strong>1</strong>', false)
            ->assertDontSee($reader->email);

        Auth::forgetGuards();
        $this->withToken($token)
            ->putJson('/api/v1/mobile/notification-preferences', [
                'preferences' => [[
                    'purpose' => 'product_updates',
                    'channel' => 'email',
                    'enabled' => false,
                ]],
            ])->assertOk();

        $choice = NotificationPreference::query()->where('user_id', $reader->id)
            ->where('purpose', 'product_updates')->where('channel', 'email')
            ->firstOrFail();
        $this->assertFalse($choice->enabled);
        $this->assertNotNull($choice->revoked_at);
        $this->assertFalse($gate->allows($reader->refresh(), 'product_updates', 'email'));
        $this->actingAs($admin, 'web');
        $this->get('/admin/audience?purpose=product_updates&channel=email')->assertOk()
            ->assertSee('<strong>0</strong>', false);
    }

    public function test_signed_in_export_and_confirmed_deletion_are_not_public_web_claims(): void
    {
        $reader = $this->account();
        $token = $reader->createToken('privacy-export', ['mobile'], now()->addHour())
            ->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export')->assertOk()
            ->assertJsonPath('data.profile.id', $reader->id)
            ->assertJsonPath('meta.secrets_excluded', true)
            ->assertJsonMissingPath('data.profile.password_hash');

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => 'incorrect',
                'confirmation' => 'DELETE',
            ])->assertUnprocessable();
        $this->assertSame('active', $reader->fresh()->status);

        $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ])->assertOk()
            ->assertJsonPath('data.tokens_revoked', true)
            ->assertJsonPath('data.notification_consent_revoked', true);
        $this->assertSame('deleted', $reader->fresh()->status);
        $this->assertDatabaseCount('account_deletion_events', 1);
        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export')->assertUnauthorized();

        // Public policy pages never expose an unauthenticated delete/export form.
        $this->get('/privacy?lang=en')->assertOk()
            ->assertDontSee('<form method="delete"', false)
            ->assertDontSee($reader->email);
    }
}

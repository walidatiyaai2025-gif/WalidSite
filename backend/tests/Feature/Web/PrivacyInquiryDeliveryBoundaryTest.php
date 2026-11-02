<?php

namespace Tests\Feature\Web;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * #480: a public privacy inquiry is just a consented CONTACT message.
 * It must never silently change an authenticated account's privacy state.
 */
final class PrivacyInquiryDeliveryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_inquiry_never_performs_deletion_export_or_notification_revocation(): void
    {
        config([
            'mail.contact_recipient' => 'reviewer@example.test',
            'mail.default' => 'smtp',
        ]);
        Mail::fake();

        $email = 'privacy-inquiry@example.test';
        $user = User::create([
            'email' => $email,
            'password_hash' => Hash::make('SafePrivacyPassword.123!'),
            'status' => 'active',
        ]);
        NotificationPreference::create([
            'user_id' => $user->id,
            'purpose' => 'product_updates',
            'channel' => 'email',
            'enabled' => true,
            'consented_at' => now(),
        ]);

        $fields = [
            'name' => 'Privacy Visitor',
            'email' => $email,
            'subject' => 'Notification consent revocation request',
            'message' => 'Please review my request to revoke optional notifications.',
        ];

        // Merely navigating to a rights request is not consent to send data.
        $this->get('/privacy?lang=en')->assertOk()
            ->assertSee('Revoke notification consent')
            ->assertSee('Request deletion');
        $this->get('/contact?lang=en&topic=privacy&request=revoke')->assertOk()
            ->assertSee('value="Notification consent revocation inquiry"', false)
            ->assertSee('id="privacy-request-disclosure"', false)
            ->assertSee('name="privacy_consent"', false);
        Mail::assertNothingSent();

        // A legitimate but unconsented request cannot leave the server.
        $this->from('/contact?lang=en')
            ->post('/contact?lang=en', $fields)
            ->assertSessionHasErrors('privacy_consent');
        Mail::assertNothingSent();

        // Contact consent is limited to the inquiry. It is NOT a permission to
        // revoke preferences, delete the account, or generate an export.
        $this->post('/contact?lang=en', [
            ...$fields, 'privacy_consent' => '1',
        ])->assertRedirect('/contact?lang=en')
            ->assertSessionHas('contact_delivered', true);
        Mail::assertSentCount(1);
        Mail::assertSent(Mailable::class, static fn (Mailable $message): bool =>
            $message->subject === 'WalidSite contact: Notification consent revocation request'
            && str_contains((string) ($message->viewData['messageBody'] ?? ''), $email)
        );

        $this->assertSame('active', $user->fresh()->status);
        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'product_updates')
            ->where('channel', 'email')->firstOrFail();
        $this->assertTrue($preference->enabled);
        $this->assertNull($preference->revoked_at);
    }

    public function test_rights_link_does_not_bypass_honeypot_or_contact_transport_gate(): void
    {
        config(['mail.contact_recipient' => 'reviewer@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        $fields = [
            'name' => 'Privacy Visitor',
            'email' => 'someone@example.test',
            'subject' => 'Personal data deletion request',
            'message' => 'Please review this account deletion inquiry carefully.',
            'privacy_consent' => '1',
        ];

        // A privacy topic must not bypass the generic contact anti-bot gate.
        $this->post('/contact?lang=ar&topic=privacy&request=deletion', [
            ...$fields, 'website' => ['injected'],
        ])->assertRedirect('/contact?lang=ar');
        Mail::assertNothingSent();

        // Without a configured transport, even accepted consent cannot
        // produce a delivery-success claim.
        config(['mail.contact_recipient' => null]);
        $this->post('/contact?lang=en&topic=privacy&request=deletion', $fields)
            ->assertStatus(503)
            ->assertDontSee('someone@example.test');
        Mail::assertNothingSent();
    }
}

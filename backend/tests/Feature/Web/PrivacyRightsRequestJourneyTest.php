<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PrivacyRightsRequestJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const RIGHTS = [
        'access' => ['en' => 'Personal data access request', 'ar' => 'طلب الاطلاع على بياناتي'],
        'export' => ['en' => 'Personal data export request', 'ar' => 'طلب تصدير بياناتي'],
        'correction' => ['en' => 'Personal data correction request', 'ar' => 'طلب تصحيح بياناتي'],
        'deletion' => ['en' => 'Personal data deletion request', 'ar' => 'طلب حذف بياناتي'],
        'revoke' => ['en' => 'Notification consent revocation inquiry', 'ar' => 'طلب سحب الموافقة على الإشعارات'],
    ];

    public function test_all_five_rights_are_linked_to_consent_based_bilingual_contact(): void
    {
        config(['mail.contact_recipient' => 'legal-reviewed@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        foreach (['en', 'ar'] as $lang) {
            $page = $this->get('/privacy?lang='.$lang)->assertOk()
                ->assertSee('name="robots" content="noindex,follow"', false)
                ->assertSee('lang="'.$lang.'"', false)
                ->assertSee('id="privacy-rights-heading"', false);

            foreach (self::RIGHTS as $right => $labels) {
                $url = route('public.contact', [
                    'lang' => $lang, 'topic' => 'privacy', 'request' => $right,
                ]);
                $page->assertSee('href="'.e($url).'"', false);
                $this->get($url)->assertOk()
                    ->assertSee('value="'.e($labels[$lang]).'"', false)
                    ->assertSee('id="privacy-request-disclosure"', false)
                    ->assertSee('name="privacy_consent"', false)
                    ->assertDontSee('name="privacy_consent" value="1" checked', false)
                    ->assertSee('href="'.e(route('public.contact', [
                        'lang' => $lang === 'en' ? 'ar' : 'en',
                        'topic' => 'privacy', 'request' => $right,
                    ])).'"', false);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_malicious_or_ambiguous_query_cannot_override_an_evidence_context(): void
    {
        config(['mail.contact_recipient' => 'legal-reviewed@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        $this->get('/contact?lang=en&topic=privacy&request=evil<script>')->assertOk()
            ->assertDontSee('evil<script>', false)
            ->assertDontSee('id="privacy-request-disclosure"', false)
            ->assertSee('value="Privacy or personal data request"', false);
        $this->get('/contact?lang=en&topic=privacy&request%5B%5D=deletion')->assertOk()
            ->assertDontSee('id="privacy-request-disclosure"', false);
        $this->get('/contact?lang=en&topic%5B%5D=privacy&request=deletion')->assertOk()
            ->assertDontSee('id="privacy-request-disclosure"', false);
        // An attacker cannot attach an unverified rights subject to another
        // public article/project inquiry, even if its slug is invalid.
        $this->get('/contact?lang=en&topic=privacy&request=deletion&article=unknown')->assertOk()
            ->assertDontSee('id="privacy-request-disclosure"', false)
            ->assertDontSee('Personal data deletion request');
        $this->get('/contact?lang=en&topic=privacy&request=deletion&project=unknown')->assertOk()
            ->assertDontSee('id="privacy-request-disclosure"', false)
            ->assertDontSee('Personal data deletion request');
        Mail::assertNothingSent();
    }

    public function test_mail_unavailable_and_consent_missing_never_claim_fulfillment(): void
    {
        config(['mail.contact_recipient' => null]);
        $this->get('/contact?lang=en&topic=privacy&request=deletion')->assertOk()
            ->assertSee('Contact delivery is unavailable')
            ->assertSee('not proof of deletion')
            ->assertDontSee('<form method="post"', false);

        config(['mail.contact_recipient' => 'legal-reviewed@example.test', 'mail.default' => 'smtp']);
        Mail::fake();
        $this->post('/contact?lang=en', [
            'name' => 'Data Reader',
            'email' => 'reader@example.test',
            'subject' => 'Personal data deletion request',
            'message' => 'Please review my account data rights request.',
            'privacy_consent' => '0',
            'website' => '',
        ])->assertSessionHasErrors('privacy_consent');

        Mail::assertNothingSent();
    }

    public function test_explicitly_consented_inquiry_sends_real_mail_but_never_claims_data_erasure(): void
    {
        config(['mail.contact_recipient' => 'legal-reviewed@example.test', 'mail.default' => 'smtp']);
        Mail::fake();
        $this->post('/contact?lang=en', [
            'name' => 'Data Reader',
            'email' => 'reader@example.test',
            'subject' => 'Notification consent revocation inquiry',
            'message' => 'Please review my notification preference request.',
            'privacy_consent' => '1',
            'website' => '',
        ])->assertRedirect(route('public.contact', ['lang' => 'en']))
            ->assertSessionHas('contact_delivered', true);

        Mail::assertSent(Mailable::class, 1);
        $this->withSession(['contact_delivered' => true])
            ->get('/contact?lang=en')->assertOk()
            ->assertSee('handed to the configured mail transport')
            ->assertDontSee('was deleted');
    }
}

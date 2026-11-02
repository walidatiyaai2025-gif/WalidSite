<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class PrivacyPolicyJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilingual_disclosure_pages_are_accessible_linked_and_not_legally_published(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $isArabic = $lang === 'ar';
            foreach (['privacy', 'terms'] as $page) {
                $response = $this->get('/'.$page.'?lang='.$lang)->assertOk()
                    ->assertHeader('Cache-Control', 'no-store, private')
                    ->assertHeader('X-Content-Type-Options', 'nosniff')
                    ->assertSee('lang="'.$lang.'"', false)
                    ->assertSee('dir="'.($isArabic ? 'rtl' : 'ltr').'"', false)
                    ->assertSee('<meta name="robots" content="noindex,follow">', false)
                    ->assertSee('href="'.route('public.privacy', ['lang' => $lang]).'"', false)
                    ->assertSee('href="'.route('public.terms', ['lang' => $lang]).'"', false)
                    ->assertSee('href="#main-content"', false)
                    ->assertSee('tabindex="-1"', false)
                    ->assertSee('rel="canonical" href="'.route('public.'.$page, ['lang' => $lang]).'"', false)
                    ->assertSee('hreflang="ar"', false)
                    ->assertSee('hreflang="en"', false)
                    ->assertSee('href="'.route('public.'.$page, [
                        'lang' => $isArabic ? 'en' : 'ar',
                    ]).'"', false);
                $response->assertSee($page === 'privacy'
                    ? ($isArabic ? 'غير معتمدة' : 'not legally approved')
                    : ($isArabic ? 'مسودة وليست شروطًا قانونية نافذة' : 'not effective legal terms'));
            }
            $this->get('/privacy?lang='.$lang)->assertSee(
                'href="'.e(route('public.contact', [
                    'lang' => $lang, 'topic' => 'privacy',
                ])).'"', false
            );
        }
    }

    public function test_bilingual_account_self_service_disclosure_is_truthful_and_navigable(): void
    {
        foreach (['ar', 'en'] as $lang) {
            $response = $this->get('/privacy?lang='.$lang)->assertOk()
                ->assertSee('id="account-controls"', false)
                ->assertSee('href="#account-controls"', false)
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $lang, 'topic' => 'privacy',
                ])).'"', false)
                ->assertDontSee('type="password"', false)
                ->assertDontSee('Authorization: Bearer', false);

            if ($lang === 'ar') {
                $response->assertSee('معطلة افتراضيًا')
                    ->assertSee('إعادة إدخال كلمة المرور')
                    ->assertSee('إتاحة التطبيق وخادم الإنتاج لم تُتحقق بعد');
                $this->get('/terms?lang=ar')->assertOk()
                    ->assertSee('إتاحتها على خادم الإنتاج لم تُتحقق بعد');
            } else {
                $response->assertSee('default to off')
                    ->assertSee('password reauthentication')
                    ->assertSee('Production app/server availability is not yet verified');
                $this->get('/terms?lang=en')->assertOk()
                    ->assertSee('production availability has not been verified');
            }
        }
    }

    public function test_public_policy_cannot_expose_private_account_actions_without_authentication(): void
    {
        $this->getJson('/api/v1/mobile/account/export')->assertUnauthorized();
        $this->getJson('/api/v1/mobile/notification-preferences')->assertUnauthorized();
        $this->putJson('/api/v1/mobile/notification-preferences', [
            'preferences' => [[
                'purpose' => 'product_updates', 'channel' => 'email', 'enabled' => true,
            ]],
        ])->assertUnauthorized();
        $this->deleteJson('/api/v1/mobile/account', [
            'password' => 'not-authorized', 'confirmation' => 'DELETE',
        ])->assertUnauthorized();
    }

    public function test_privacy_contact_request_cannot_send_on_refusal_or_honeypot(): void
    {
        config(['mail.contact_recipient' => 'approved@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        $data = [
            'name' => 'Visitor', 'email' => 'visitor@example.test',
            'subject' => 'Privacy or personal data request',
            'message' => 'Please explain the handling of my information.',
        ];
        foreach (['en', 'ar'] as $index => $lang) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(110 + $index)]);
            $this->from('/contact?lang='.$lang)->post('/contact?lang='.$lang, $data)
                ->assertSessionHasErrors('privacy_consent');
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(120 + $index)]);
            $this->from('/contact?lang='.$lang)->post('/contact?lang='.$lang,
                $data + ['privacy_consent' => '0'])
                ->assertSessionHasErrors('privacy_consent');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.130']);
        $this->post('/contact?lang=en', $data + [
            'privacy_consent' => '1', 'website' => 'automated-spam.example',
        ])->assertRedirect('/contact?lang=en');
        Mail::assertNothingSent();
    }

    public function test_unapproved_policies_are_not_discovered_in_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertDontSee('/privacy?')
            ->assertDontSee('/terms?');
    }

    public function test_only_safe_known_locale_is_accepted_and_no_untrusted_parameters_are_reflected(): void
    {
        $this->withHeader('Accept', 'application/json')->get('/privacy?lang=bad')->assertUnprocessable();
        $this->withHeader('Accept', 'application/json')->get('/terms?lang%5B%5D=ar')->assertUnprocessable();
        $this->get('/privacy?lang=en&secret=do-not-echo')->assertOk()
            ->assertDontSee('do-not-echo');
        $this->get('/terms?lang=ar&secret=do-not-echo')->assertOk()
            ->assertDontSee('do-not-echo');
    }

    public function test_public_pages_link_data_requests_without_collecting_consent_implicitly(): void
    {
        config(['mail.contact_recipient' => 'reviewed@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        $this->get('/contact?lang=en&topic=privacy')->assertOk()
            ->assertSee('value="Privacy or personal data request"', false)
            ->assertSee('name="privacy_consent"', false)
            ->assertDontSee('name="privacy_consent" value="1" checked', false)
            ->assertSee('Data handling details')
            ->assertSee('href="'.e(route('public.contact', [
                'lang' => 'ar', 'topic' => 'privacy',
            ])).'"', false);
        $this->get('/contact?lang=ar&topic=privacy')->assertOk()
            ->assertSee('طلب متعلق بالخصوصية أو البيانات')
            ->assertSee('href="'.e(route('public.contact', [
                'lang' => 'en', 'topic' => 'privacy',
            ])).'"', false);
        $this->get('/contact?lang=en&topic%5B%5D=privacy')->assertOk()
            ->assertDontSee('value="Privacy or personal data request"', false);
        $this->get('/contact?lang=en&topic=unsafe-injected')->assertOk()
            ->assertDontSee('unsafe-injected');
        Mail::assertNothingSent();
    }

    public function test_contact_disabled_state_is_truthful_and_data_request_links_remain_available(): void
    {
        config(['mail.contact_recipient' => null]);
        $this->get('/contact?lang=en&topic=privacy')->assertOk()
            ->assertSee('Contact delivery is unavailable')
            ->assertSee('Read the privacy and data disclosure')
            ->assertDontSee('<form method="post"', false);
        $this->get('/privacy?lang=ar')->assertOk()
            ->assertSee('لم تُعتمد مدد احتفاظ رقمية');
    }
}

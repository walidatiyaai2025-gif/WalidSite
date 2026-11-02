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
    public function test_privacy_disclosure_distinguishes_authenticated_controls_from_contact_consent(): void
    {
        $this->get('/privacy?lang=en')->assertOk()
            ->assertSee('independently opt in to account activity')
            ->assertSee('does not subscribe you to marketing or notifications')
            ->assertSee('after re-entering your password and typing DELETE')
            ->assertSee('An inquiry does not automatically delete or export data');
        $this->get('/privacy?lang=ar')->assertOk()
            ->assertSee('لا تنشئ اشتراكًا ولا تمنح موافقة على التسويق')
            ->assertSee('بعد إعادة إدخال كلمة المرور وكتابة DELETE')
            ->assertSee('لا ينفذ إرسال الاستفسار حذفًا أو تصديرًا تلقائيًا');
    }


    public function test_privacy_request_actions_are_allowlisted_bilingual_and_non_submitting(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => 'reviewed@example.test', 'mail.default' => 'smtp']);
        $rights = [
            'access' => ['Personal data access request', 'طلب الاطلاع على بياناتي'],
            'correction' => ['Personal data correction request', 'طلب تصحيح بياناتي'],
            'export' => ['Personal data export request', 'طلب تصدير بياناتي'],
            'deletion' => ['Personal data deletion request', 'طلب حذف بياناتي'],
            'revoke' => ['Notification consent revocation inquiry', 'طلب سحب الموافقة على الإشعارات'],
            'notifications' => ['Privacy notification consent revocation request', 'طلب إلغاء الموافقة على الإشعارات'],
        ];
        foreach ($rights as $right => [$englishSubject, $arabicSubject]) {
            foreach (['en' => $englishSubject, 'ar' => $arabicSubject] as $locale => $subject) {
                $url = route('public.contact', [
                    'lang' => $locale, 'topic' => 'privacy', 'request' => $right,
                ]);
                $this->get('/privacy?lang='.$locale)->assertOk()
                    ->assertSee('href="'.e($url).'"', false);
                $this->get($url)->assertOk()
                    ->assertSee('value="'.e($subject).'"', false)
                    ->assertSee('id="privacy-request-disclosure"', false)
                    ->assertSee('name="privacy_consent"', false);
            }
        }
        $this->get('/contact?lang=en&topic=privacy&request=deletion&article=unknown')
            ->assertOk()->assertDontSee('id="privacy-request-disclosure"', false);
        $this->get('/contact?lang=en&topic%5B%5D=privacy&request=export')
            ->assertOk()->assertDontSee('id="privacy-request-disclosure"', false);
        Mail::assertNothingSent();
    }

    public function test_rights_inquiries_keep_category_on_locale_change_without_fulfilling_any_request(): void
    {
        config(['mail.contact_recipient' => 'reviewed@example.test', 'mail.default' => 'smtp']);
        Mail::fake();
        foreach (['access', 'correction', 'export', 'deletion', 'revoke', 'notifications'] as $right) {
            foreach (['en', 'ar'] as $locale) {
                $other = $locale === 'en' ? 'ar' : 'en';
                $this->get(route('public.contact', [
                    'lang' => $locale, 'topic' => 'privacy', 'request' => $right,
                ]))->assertOk()
                    ->assertSee('id="privacy-request-disclosure"', false)
                    ->assertSee('href="'.e(route('public.contact', [
                        'lang' => $other, 'topic' => 'privacy', 'request' => $right,
                    ])).'"', false)
                    ->assertSee('name="privacy_consent"', false)
                    ->assertDontSee('name="privacy_consent" value="1" checked', false)
                    ->assertSee($locale === 'ar'
                        ? 'إرسال الرسالة لا يثبت تنفيذ الطلب'
                        : 'sending a message is not proof of deletion');
            }
        }
        $this->get('/contact?lang=en&topic=privacy&request[]=deletion')->assertOk()
            ->assertDontSee('id="privacy-request-disclosure"', false);
        $this->get('/contact?lang=en&topic=privacy&request=deletion&article=unknown')->assertOk()
            ->assertDontSee('id="privacy-request-disclosure"', false);
        Mail::assertNothingSent();
    }

}

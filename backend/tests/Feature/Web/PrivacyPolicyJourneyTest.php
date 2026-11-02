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
                    ->assertHeader('Cache-Control', 'no-store')
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
                ]).'"', false
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
            ]).'"', false);
        $this->get('/contact?lang=ar&topic=privacy')->assertOk()
            ->assertSee('طلب متعلق بالخصوصية أو البيانات')
            ->assertSee('href="'.e(route('public.contact', [
                'lang' => 'en', 'topic' => 'privacy',
            ]).'"', false);
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

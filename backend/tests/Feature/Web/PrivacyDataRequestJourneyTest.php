<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PrivacyDataRequestJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECTS = [
        'access' => [
            'en' => 'Personal data access request',
            'ar' => 'طلب الاطلاع على بياناتي',
        ],
        'correction' => [
            'en' => 'Personal data correction request',
            'ar' => 'طلب تصحيح بياناتي',
        ],
        'export' => [
            'en' => 'Personal data export request',
            'ar' => 'طلب تصدير بياناتي',
        ],
        'deletion' => [
            'en' => 'Personal data deletion request',
            'ar' => 'طلب حذف بياناتي',
        ],
        'notifications' => [
            'en' => 'Privacy notification consent revocation request',
            'ar' => 'طلب إلغاء الموافقة على الإشعارات',
        ],
    ];

    public function test_each_privacy_request_has_a_real_accessible_bilingual_contact_destination(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => 'consent-review@example.test', 'mail.default' => 'smtp']);

        foreach (['ar', 'en'] as $lang) {
            $page = $this->get('/privacy?lang='.$lang)->assertOk()
                ->assertSee('href="#main-content"', false)
                ->assertSee('type', false)
                ->assertSee('lang="'.$lang.'"', false);
            foreach (self::SUBJECTS as $kind => $subjects) {
                $url = route('public.contact', [
                    'lang' => $lang,
                    'topic' => 'privacy',
                    'request' => $kind,
                ]);
                $page->assertSee('href="'.e($url).'"', false);

                $response = $this->get($url)->assertOk()
                    ->assertSee('value="'.$subjects[$lang].'"', false)
                    ->assertSee('name="privacy_consent" value="1"', false)
                    ->assertSee($lang === 'ar' ? 'نموذج استفسار' : 'not an authenticated action')
                    ->assertSee('href="'.e(route('public.contact', [
                        'lang' => $lang === 'ar' ? 'en' : 'ar',
                        'topic' => 'privacy', 'request' => $kind,
                    ])).'"', false);
                $response->assertDontSee('name="privacy_consent" value="1" checked', false);
            }
        }

        Mail::assertNothingSent();
    }

    public function test_forged_nested_and_cross_context_request_types_do_not_preselect_data_actions(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => 'consent-review@example.test', 'mail.default' => 'smtp']);

        foreach ([
            '/contact?lang=en&topic=privacy&request=malicious-private-metadata',
            '/contact?lang=en&topic=privacy&request[]=deletion',
            '/contact?lang=en&topic=privacy&request[attempt]=export',
            '/contact?lang=en&topic[]=privacy&request=deletion',
            '/contact?lang=en&topic=privacy&request=deletion&article=unauthorized',
            '/contact?lang=en&topic=privacy&request=deletion&project=unauthorized',
            '/contact?lang=en&topic=other&request=export',
        ] as $uri) {
            $response = $this->get($uri)->assertOk()
                ->assertDontSee('value="Personal data deletion request"', false)
                ->assertDontSee('value="Personal data export request"', false)
                ->assertDontSee('Data export inquiry')
                ->assertDontSee('Account deletion inquiry')
                ->assertDontSee('malicious-private-metadata');
            $response->assertDontSee('&amp;request=deletion', false);
        }

        $this->get('/contact?lang=en&topic=privacy&request=invalid')
            ->assertOk()->assertSee('value="Privacy or personal data request"', false);
        $this->get('/contact?lang=en&topic=privacy&request[]=export')
            ->assertOk()->assertSee('value="Privacy or personal data request"', false);
        Mail::assertNothingSent();
    }

    public function test_contact_inquiry_requires_separate_consent_and_mail_provider_never_claims_self_service(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => 'consent-review@example.test', 'mail.default' => 'smtp']);

        $input = [
            'name' => 'Privacy Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Personal data deletion request',
            'message' => 'Please tell me how to verify my identity and delete my account.',
            'website' => '',
        ];

        $this->post('/contact?lang=en', $input)
            ->assertRedirect()->assertSessionHasErrors('privacy_consent');
        Mail::assertNothingSent();

        $this->post('/contact?lang=en', $input + ['privacy_consent' => '1'])
            ->assertRedirect(route('public.contact', ['lang' => 'en']))
            ->assertSessionHas('contact_delivered', true);

        Mail::assertSent(\Illuminate\Mail\Mailable::class, 1);
        $this->get('/contact?lang=en')->assertOk()
            ->assertSee('handed to the configured mail transport')
            ->assertDontSee('account deleted')
            ->assertDontSee('notification consent revoked');
    }

    public function test_genuine_account_privacy_endpoints_remain_auth_scoped_and_not_public_browser_links(): void
    {
        // The W1 #477 implementation provides the actual account lifecycle.
        // This public form is *not* a surrogate for its authenticated API.
        foreach ([
            'api.mobile.account.export',
            'api.mobile.account.delete',
            'api.mobile.notification-preferences',
            'api.mobile.notification-preferences.update',
        ] as $name) {
            $this->assertTrue(Route::has($name), 'Missing real account endpoint: '.$name);
        }

        $this->withHeader('Accept', 'application/json')
            ->get(route('api.mobile.account.export'))->assertUnauthorized();
        $this->withHeader('Accept', 'application/json')
            ->get(route('api.mobile.notification-preferences'))->assertUnauthorized();
        $this->withHeader('Accept', 'application/json')
            ->delete(route('api.mobile.account.delete'))->assertUnauthorized();
        $this->withHeader('Accept', 'application/json')
            ->put(route('api.mobile.notification-preferences.update'), [
                'preferences' => [['purpose' => 'product_updates', 'channel' => 'push', 'enabled' => true]],
            ])->assertUnauthorized();

        $this->get('/privacy?lang=en')->assertOk()
            ->assertSee('Authenticated app accounts')
            ->assertSee('identity must be verified')
            ->assertDontSee('/api/mobile/account/export')
            ->assertDontSee('ACCESS_TOKEN');
    }

    public function test_unconfigured_email_is_honest_for_every_privacy_intent(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => null]);
        foreach (array_keys(self::SUBJECTS) as $kind) {
            $response = $this->get(route('public.contact', [
                'lang' => 'ar', 'topic' => 'privacy', 'request' => $kind,
            ]))->assertOk()
                ->assertSee('استفسار عن', false)
                ->assertSee('role="status"', false)
                ->assertSee('استقبال الرسائل غير مفعل', false)
                ->assertDontSee('<form method="post"', false);
            $response->assertDontSee('contact_delivered');
        }
        Mail::assertNothingSent();
    }

    public function test_unconfigured_contact_delivery_rejects_post_even_with_affirmative_consent(): void
    {
        Mail::fake();
        config(['mail.contact_recipient' => null, 'mail.default' => 'smtp']);

        $this->post('/contact?lang=en&topic=privacy&request=deletion', [
            'name' => 'Privacy Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Personal data deletion request',
            'message' => 'Please review my privacy inquiry.',
            'privacy_consent' => '1',
            'website' => '',
        ])->assertStatus(503);

        Mail::assertNothingSent();
    }

    public function test_english_and_arabic_terms_distinguish_auth_actions_from_external_notification_delivery(): void
    {
        $this->get('/terms?lang=en')->assertOk()
            ->assertSee('Authenticated app account endpoints support data export')
            ->assertSee('Actual notification delivery')
            ->assertSee('not proof of successful execution');
        $this->get('/terms?lang=ar')->assertOk()
            ->assertSee('تتيح واجهات الحساب المصادق عليها')
            ->assertSee('ظهور الوظيفة في الواجهة لا يثبت نجاح التنفيذ');
    }
}

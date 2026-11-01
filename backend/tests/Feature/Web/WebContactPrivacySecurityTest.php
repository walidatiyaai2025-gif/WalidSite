<?php

namespace Tests\Feature\Web;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class WebContactPrivacySecurityTest extends TestCase
{
    private function deliveryReady(): void
    {
        config([
            'mail.contact_recipient' => 'reviewed-owner@example.test',
            'mail.default' => 'smtp',
        ]);
    }

    private function message(array $changes = []): array
    {
        return array_replace([
            'name' => 'Technical Visitor',
            'email' => 'sender@example.test',
            'subject' => 'Verified project discussion',
            'message' => 'I would like to discuss the public portfolio.',
            'privacy_consent' => '1',
        ], $changes);
    }

    public function test_contact_consent_is_unchecked_accessible_and_translated(): void
    {
        $this->deliveryReady();

        $this->get('/contact?lang=en')->assertOk()
            ->assertSee('name="privacy_consent"', false)
            ->assertSee('id="contact-privacy-consent"', false)
            ->assertSee('I consent to using my name, email address')
            ->assertDontSee('checked', false);

        $this->get('/contact?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('أوافق على استخدام اسمي وبريدي الإلكتروني');
    }

    public function test_missing_or_refused_consent_cannot_send_and_errors_link_to_control(): void
    {
        $this->deliveryReady();
        Mail::fake();

        foreach (['en', 'ar'] as $lang) {
            $withoutConsent = $this->message();
            unset($withoutConsent['privacy_consent']);
            // Follow the validation redirect: Laravel flashes errors to
            // precisely this next response, not to a later standalone GET.
            $this->from('/contact?lang='.$lang)
                ->followingRedirects()
                ->post('/contact?lang='.$lang, $withoutConsent)
                ->assertOk()
                ->assertSee('aria-describedby="contact-privacy-consent-error"', false)
                ->assertSee('id="contact-privacy-consent-error"', false);
        }

        $this->from('/contact')->followingRedirects()
            ->post('/contact', $this->message(['privacy_consent' => '0']))
            ->assertOk()
            ->assertSee('id="contact-privacy-consent-error"', false);
        Mail::assertNothingSent();
    }

    public function test_consented_submission_uses_real_mail_transport_and_honeypot_still_drops(): void
    {
        $this->deliveryReady();
        Mail::fake();

        $this->post('/contact?lang=en', $this->message(['website' => 'spam.example.test']))
            ->assertRedirect('/contact?lang=en');
        Mail::assertNothingSent();

        $this->post('/contact?lang=ar', $this->message())
            ->assertRedirect('/contact?lang=ar')
            ->assertSessionHas('contact_delivered', true);

        Mail::assertSentCount(1);
        Mail::assertSent(Mailable::class, static fn (Mailable $mail): bool =>
            $mail->subject === 'WalidSite contact: Verified project discussion'
            && str_contains($mail->viewData['messageBody'] ?? '', 'sender@example.test')
        );
    }

    public function test_production_plain_http_rejects_contact_pi_i_before_csrf_or_validation(): void
    {
        $this->deliveryReady();
        Mail::fake();
        $priorEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            $this->get('http://localhost/contact')->assertOk();

            // Missing CSRF, missing consent and invalid values must all be
            // stopped by the transport guard before web middleware processes
            // any of this private data or flashes it to the session.
            $this->post('http://localhost/contact', [
                'name' => 'A',
                'email' => 'sender@example.test',
                'message' => 'private submission',
            ])->assertForbidden();
            $this->post('http://localhost/contact', $this->message())
                ->assertForbidden();
            Mail::assertNothingSent();

            // HTTPS continues to work with genuine session CSRF protection.
            $csrf = str_repeat('v', 40);
            $this->withSession(['_token' => $csrf]);
            $this->post('https://localhost/contact?lang=en', array_merge(
                $this->message(), ['_token' => $csrf]
            ))->assertRedirect('/contact?lang=en')
                ->assertSessionHas('contact_delivered', true);
            Mail::assertSentCount(1);
        } finally {
            $this->app->instance('env', $priorEnvironment);
        }
    }

    public function test_failed_mail_transport_never_reports_sender_body_or_smtp_secret(): void
    {
        $this->deliveryReady();
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(
            new \RuntimeException('smtp-credential-private-and-message-body')
        );

        $this->post('/contact', $this->message())->assertStatus(503)
            ->assertDontSee('smtp-credential-private-and-message-body')
            ->assertDontSee('sender@example.test');

        Log::shouldHaveReceived('warning')->once()->with(
            'public.contact.web.delivery_failed',
            ['type' => \RuntimeException::class]
        );
    }
}

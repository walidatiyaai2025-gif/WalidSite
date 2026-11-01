<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactValidationLocaleTest extends TestCase
{
    public function test_invalid_arabic_contact_submission_has_localized_errors_without_sending_mail(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);
        Mail::fake();

        $this->from('/contact?lang=ar')->post('/contact?lang=ar', [
            'name' => 'A',
            'email' => 'invalid',
            'subject' => 'ok',
            'message' => 'short',
            'privacy_consent' => '0',
        ])->assertRedirect('/contact?lang=ar')
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message', 'privacy_consent']);

        $errors = session('errors');
        $this->assertStringContainsString('الاسم', $errors->first('name'));
        $this->assertStringContainsString('بريد إلكتروني صحيح', $errors->first('email'));
        $this->assertStringContainsString('سياسة الخصوصية', $errors->first('privacy_consent'));
        Mail::assertNothingSent();
    }

    public function test_invalid_english_contact_submission_keeps_default_english_errors(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);
        Mail::fake();

        $this->from('/contact?lang=en')->post('/contact?lang=en', [
            'name' => 'A',
            'email' => 'invalid',
            'subject' => 'ok',
            'message' => 'short',
            'privacy_consent' => '0',
        ])->assertRedirect('/contact?lang=en')->assertSessionHasErrors(['email', 'privacy_consent']);

        $this->assertStringContainsString('email', strtolower(session('errors')->first('email')));
        Mail::assertNothingSent();
    }

    public function test_array_shaped_old_contact_inputs_render_safely_after_bilingual_validation(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);
        Mail::fake();
        // The test exercises form re-rendering, not the independent 4/min throttle.
        $this->withoutMiddleware(\\Illuminate\\Routing\\Middleware\\ThrottleRequests::class);

        foreach (['en', 'ar'] as $locale) {
            $this->from('/contact?lang='.$locale)
                ->post('/contact?lang='.$locale, [
                    'name' => ['PRIVATE_NAME_MARKER'],
                    'email' => ['not-an-email'],
                    'subject' => ['PRIVATE_SUBJECT_MARKER'],
                    'message' => ['PRIVATE_MESSAGE_MARKER'],
                    'privacy_consent' => '1',
                ])->assertRedirect('/contact?lang='.$locale)
                ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

            $this->get('/contact?lang='.$locale)->assertOk()
                ->assertSee('name="name" value=""', false)
                ->assertSee('name="email" value=""', false)
                ->assertSee('name="subject" value=""', false)
                ->assertSee('name="message"', false)
                ->assertDontSee('PRIVATE_NAME_MARKER')
                ->assertDontSee('PRIVATE_SUBJECT_MARKER')
                ->assertDontSee('PRIVATE_MESSAGE_MARKER');
        }
        Mail::assertNothingSent();
    }

    public function test_contact_retry_preserves_safe_scalar_values_without_sending_mail(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);
        Mail::fake();
        $this->withoutMiddleware(\\Illuminate\\Routing\\Middleware\\ThrottleRequests::class);

        foreach (['en', 'ar'] as $locale) {
            $this->from('/contact?lang='.$locale)
                ->post('/contact?lang='.$locale, [
                    'name' => 'Verified Sender',
                    'email' => 'sender@example.test',
                    'subject' => 'A real question',
                    'message' => 'Please review these technical instructions.',
                    'privacy_consent' => '0',
                ])->assertRedirect('/contact?lang='.$locale)
                ->assertSessionHasErrors('privacy_consent');

            $this->get('/contact?lang='.$locale)->assertOk()
                ->assertSee('name="name" value="Verified Sender"', false)
                ->assertSee('name="email" value="sender@example.test"', false)
                ->assertSee('name="subject" value="A real question"', false)
                ->assertSee('Please review these technical instructions.')
                ->assertDontSee('contact_delivered');
        }
        Mail::assertNothingSent();
    }
}

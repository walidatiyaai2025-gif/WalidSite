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
}

<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactHoneypotEarlyExitTest extends TestCase
{
    public function test_populated_honeypot_is_silently_discarded_even_with_invalid_sender_fields(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);
        Mail::fake();

        foreach (['en', 'ar'] as $locale) {
            $this->from('/contact?lang='.$locale)
                ->post('/contact?lang='.$locale, [
                    'website' => 'https://bot.example',
                    'name' => 'A',
                    'email' => 'invalid',
                    'message' => 'short',
                    'privacy_consent' => '0',
                ])
                ->assertRedirect('/contact?lang='.$locale)
                ->assertSessionHasNoErrors()
                ->assertSessionMissing('contact_delivered');
        }

        Mail::assertNothingSent();
    }

    public function test_blank_honeypot_does_not_bypass_normal_validation(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);
        Mail::fake();

        $this->from('/contact?lang=en')
            ->post('/contact?lang=en', ['website' => '   ', 'email' => 'invalid'])
            ->assertRedirect('/contact?lang=en')
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message', 'privacy_consent']);

        Mail::assertNothingSent();
    }
}

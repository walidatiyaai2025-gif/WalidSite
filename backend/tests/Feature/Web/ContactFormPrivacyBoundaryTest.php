<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormPrivacyBoundaryTest extends TestCase
{
    public function test_bilingual_contact_pages_prevent_private_inquiry_caching_and_match_server_limits(): void
    {
        config(['mail.contact_recipient' => 'contact@example.test', 'mail.default' => 'smtp']);

        foreach (['ar', 'en'] as $locale) {
            $response = $this->get('/contact?lang='.$locale.'&topic=privacy&request=export')
                ->assertOk()
                ->assertHeader('Cache-Control', 'private, no-store')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertSee('name="name"', false)
                ->assertSee('maxlength="100"', false)
                ->assertSee('maxlength="254"', false)
                ->assertSee('maxlength="140"', false)
                ->assertSee('maxlength="3000"', false);
            $this->assertSame(1, preg_match('/<input[^>]*name="name"[^>]*maxlength="100"/', $response->getContent()));
        }
    }

    public function test_name_over_server_limit_never_reaches_mail_transport(): void
    {
        config(['mail.contact_recipient' => 'contact@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        $this->post('/contact?lang=en', [
            'name' => str_repeat('A', 101),
            'email' => 'visitor@example.test',
            'subject' => 'Technical inquiry',
            'message' => 'This is a legitimate contact inquiry.',
            'privacy_consent' => '1',
        ])->assertSessionHasErrors(['name']);

        Mail::assertNothingSent();
    }
}

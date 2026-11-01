<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class ContactValidationSummaryTest extends TestCase
{
    public function test_bilingual_error_summary_links_to_invalid_fields_only(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);

        foreach (['en' => 'Please correct the following fields', 'ar' => 'يرجى تصحيح الحقول التالية'] as $locale => $heading) {
            $this->from('/contact?lang='.$locale)
                ->post('/contact?lang='.$locale, [
                    'name' => 'Valid Sender',
                    'email' => 'invalid-email',
                    'subject' => 'Valid inquiry',
                    'message' => 'A valid inquiry message long enough.',
                    'privacy_consent' => '0',
                ])->assertRedirect('/contact?lang='.$locale)
                ->assertSessionHasErrors(['email', 'privacy_consent']);

            $this->get('/contact?lang='.$locale)->assertOk()
                ->assertSee($heading)
                ->assertSee('role="alert"', false)
                ->assertSee('href="#contact-email"', false)
                ->assertSee('href="#contact-privacy-consent"', false)
                ->assertDontSee('href="#contact-name"', false);
        }
    }

    public function test_clean_contact_form_has_no_validation_summary(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);

        $this->get('/contact?lang=en')->assertOk()
            ->assertDontSee('id="contact-error-summary-heading"', false);
    }
}

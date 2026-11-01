<?php

namespace Tests\Feature\Web;

use Tests\TestCase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

class ContactValidationSummaryTest extends TestCase
{
    public function test_bilingual_error_summary_links_to_invalid_fields_only(): void
    {
        config(['mail.contact_recipient' => 'contact@example.org', 'mail.default' => 'smtp']);

        foreach (['en' => 'Please correct the following fields', 'ar' => 'يرجى تصحيح الحقول التالية'] as $locale => $heading) {
            $this->app['request']->query->set('lang', $locale);
            $errors = new ViewErrorBag;
            $errors->put('default', new MessageBag([
                'email' => ['Invalid email'],
                'privacy_consent' => ['Consent required'],
            ]));
            $view = $this->view('public.contact', [
                'available' => true,
                'errors' => $errors,
            ]);
            $view
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

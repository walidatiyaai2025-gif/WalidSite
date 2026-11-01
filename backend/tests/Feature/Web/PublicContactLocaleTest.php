<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicContactLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_page_renders_in_english_and_arabic_without_mail_configuration(): void
    {
        config()->set('mail.contact_recipient', null);

        $this->get('/contact?lang=en')->assertOk()->assertSee('lang="en"', false)
            ->assertSee('Contact delivery is unavailable');
        $this->get('/contact?lang=ar')->assertOk()->assertSee('dir="rtl"', false)
            ->assertSee('استقبال الرسائل غير مفعل');
    }

    public function test_contact_page_rejects_unsupported_and_array_locale(): void
    {
        $this->getJson('/contact?lang=fr')->assertUnprocessable()->assertJsonValidationErrors('lang');
        $this->getJson('/contact?lang%5B%5D=ar')->assertUnprocessable()->assertJsonValidationErrors('lang');
    }
}

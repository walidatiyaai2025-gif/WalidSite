<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class ContactSuccessFollowupTest extends TestCase
{
    public function test_success_notice_offers_locale_preserving_clean_contact_link(): void
    {
        foreach (['en' => 'Send another message', 'ar' => 'إرسال رسالة أخرى'] as $locale => $label) {
            $this->withSession(['contact_delivered' => true])
                ->get('/contact?lang='.$locale)->assertOk()
                ->assertSee($label)
                ->assertSee('href="'.route('public.contact', ['lang' => $locale]).'"', false)
                ->assertDontSee('name="message"', false);
        }
    }

    public function test_normal_and_unavailable_contact_pages_do_not_offer_success_followup(): void
    {
        $this->get('/contact?lang=en')->assertOk()
            ->assertDontSee('Send another message');
        $this->get('/contact?lang=ar')->assertOk()
            ->assertDontSee('إرسال رسالة أخرى');
    }
}

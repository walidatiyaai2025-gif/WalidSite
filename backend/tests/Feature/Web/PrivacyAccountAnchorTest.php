<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

final class PrivacyAccountAnchorTest extends TestCase
{
    public function test_privacy_anchor_is_focusable_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $this->get('/privacy?lang='.$locale)
                ->assertOk()
                ->assertSee('id="account-controls" tabindex="-1"', false)
                ->assertSee('href="#account-controls"', false)
                ->assertSee('<ol>', false)
                ->assertSee('</ol>', false)
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $locale,
                    'topic' => 'privacy',
                ])).'"', false);
        }
    }
}

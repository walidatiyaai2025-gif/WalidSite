<?php

namespace Tests\Feature\Web;

use Tests\TestCase;

class PublicLayoutTest extends TestCase
{
    public function test_english_landing_has_accessible_light_site_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('Waleed Atiya')
            ->assertSee('Platform foundation in development')
            ->assertSee('href="'.route('home', ['lang' => 'ar']).'"', false);
    }

    public function test_arabic_landing_uses_rtl_and_real_english_switch(): void
    {
        $this->get('/?lang=ar')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('المنصة قيد التطوير والتحقق')
            ->assertSee('href="'.route('home', ['lang' => 'en']).'"', false);
    }

    public function test_unrecognized_locale_falls_back_to_english(): void
    {
        $this->get('/?lang=xx')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Platform foundation in development');
    }

    public function test_portrait_references_only_vetted_owner_photo(): void
    {
        $this->assertFileExists(base_path('../assets/portraits/portrait-light-derived.jpg'));

        $this->get('/')
            ->assertOk()
            ->assertSee(
                'src="https://raw.githubusercontent.com/walidatiyaai2025-gif/WalidSite/main/assets/portraits/portrait-light-derived.jpg"',
                false,
            )
            ->assertSee('Approved real portrait of Waleed Atiya');
    }
}

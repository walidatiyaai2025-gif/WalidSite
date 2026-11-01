<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_landing_has_accessible_light_site_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('Waleed Atiya')
            ->assertSee('Software, Database &amp; AI Engineering', false)
            ->assertSee('Engineering projects &amp; case studies', false)
            ->assertSee('href="'.route('public.projects', ['lang' => 'en']).'"', false)
            ->assertSee('href="'.route('home', ['lang' => 'ar']).'"', false);
    }

    public function test_arabic_landing_uses_rtl_and_real_english_switch(): void
    {
        $this->get('/?lang=ar')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('هندسة البرمجيات وقواعد البيانات وحلول الذكاء الاصطناعي')
            ->assertSee('استكشف المشاريع')
            ->assertSee('href="'.route('home', ['lang' => 'en']).'"', false);
    }

    public function test_unrecognized_locale_falls_back_to_english(): void
    {
        $this->get('/?lang=xx')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Explore projects');
    }

    public function test_portrait_references_only_vetted_owner_photo(): void
    {
        $this->assertFileExists(base_path('../assets/portraits/portrait-light-derived.jpg'));

        foreach (['/', '/?lang=ar'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('src="'.route('public.portrait').'"', false)
                ->assertDontSee('raw.githubusercontent.com');
        }
        $this->get('/')->assertSee('Approved real portrait of Waleed Atiya');
    }

    public function test_portrait_is_served_locally_with_image_security_headers(): void
    {
        $portraitPath = base_path('../assets/portraits/portrait-light-derived.jpg');
        $response = $this->get('/media/portrait-light.jpg');
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(
            realpath($portraitPath),
            realpath($response->baseResponse->getFile()->getPathname()),
        );
    }

    public function test_google_fonts_are_available_on_public_and_sign_in_pages(): void
    {
        foreach (['/', '/?lang=ar', '/admin/login'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('fonts.googleapis.com/css2?family=Inter', false)
                ->assertSee('family=Tajawal', false)
                ->assertSee('display=swap', false)
                ->assertSee('font-family: var(--wa-font-ui)', false);
        }
    }
}

<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-01 owner-directed November 2026 source-level acceptance.
 * A structural test is not a pixel-difference result or staging screenshot.
 */
class Web01HomeScreenAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_landing_has_bilingual_real_navigation_and_responsive_device_layouts(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $response = $this->get('/?lang='.$locale)->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('class="w26-desktop"', false)
                ->assertSee('class="w26-mobile"', false)
                ->assertSee('class="w26-devices"', false)
                ->assertSee('class="w26-laptop"', false)
                ->assertSee('class="w26-phone"', false)
                ->assertSee('class="w26-tablet"', false)
                ->assertSee('class="w26-github"', false)
                ->assertSee('class="w26-ribbon"', false)
                ->assertSee('href="#main-content"', false)
                ->assertSee('id="main-content" tabindex="-1"', false)
                ->assertSee('aria-current="page"', false);

            foreach (['home', 'public.projects', 'public.expertise',
                'public.ai-engineering', 'public.lab', 'public.blog',
                'public.resume', 'public.contact', 'public.github',
                'public.search'] as $destination) {
                $response->assertSee('href="'.route($destination, ['lang' => $locale]).'"', false);
            }

            $response->assertSee($locale === 'ar'
                ? 'قيادة تقنية المؤسسات وهندسة حلول الذكاء الاصطناعي'
                : 'Enterprise Technology &amp; AI Solutions Architecture', false);
            $response->assertSee($locale === 'ar' ? 'استكشف المشاريع' : 'Explore projects');
        }
    }

    public function test_owner_home_radio_language_is_beside_brand_on_desktop_and_mobile(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $page = $this->get('/?lang='.$locale)->assertOk()
                ->assertSee('data-testid="home-header-locale"', false)
                ->assertSee('data-testid="home-mobile-header-locale"', false)
                ->assertSee('name="lang" id="w26-desktop-en" value="en"', false)
                ->assertSee('name="lang" id="w26-desktop-ar" value="ar"', false)
                ->assertSee('name="lang" id="w26-mobile-en" value="en"', false)
                ->assertSee('name="lang" id="w26-mobile-ar" value="ar"', false)
                ->assertSee('class="w26-locale-choice"', false)
                ->assertSee('onchange="this.requestSubmit()"', false)
                ->assertSee('action="'.route('home').'"', false)
                ->assertSee('aria-label="'.($locale === 'ar' ? 'لغة الموقع' : 'Site language').'"', false);
            // Every viewport has a real keyboard-operable native radio group;
            // the active locale is selected by the rendered HTTP query.
            $page->assertSee('value="'.$locale.'" checked', false)
                ->assertSee('name="lang" id="w26-desktop-en"', false)
                ->assertSee('name="lang" id="w26-mobile-ar"', false);
        }
        $this->get('/?lang=unexpected')->assertOk()
            ->assertSee('value="en" checked', false)
            ->assertSee('lang="en"', false);
    }

    public function test_owner_landing_uses_approved_portrait_not_design_screenshot_as_an_image(): void
    {
        $this->get('/?lang=en')->assertOk()
            ->assertSee('class="w26-portrait"', false)
            ->assertSee('src="'.route('public.owner-portrait').'"', false)
            ->assertSee('Approved real portrait of Waleed Atiya')
            ->assertSee('@media(max-width:900px)', false)
            ->assertDontSee('src="assets/screen-reference/web_home.png"', false);

        $this->get('/about?lang=en')->assertOk()
            ->assertSee('class="wa-about about-screen"', false);
    }

    public function test_owner_landing_keeps_professional_claims_and_demo_numbers_unpublished(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $response = $this->get('/?lang='.$locale)->assertOk();
            $response->assertDontSee('Government &amp; enterprise systems', false)
                ->assertDontSee('PMP®')
                ->assertDontSee('20+ Years')
                ->assertDontSee('1,248')
                ->assertDontSee('3,920')
                ->assertDontSee('Verified projects &amp; case studies', false)
                ->assertDontSee('Latest source-checked articles')
                ->assertDontSee('No evidence-verified public case studies')
                ->assertDontSee('No source-verified published articles')
                ->assertSee('body:has(.w26-landing) .site-footer { display: none; }', false);
        }
    }
}

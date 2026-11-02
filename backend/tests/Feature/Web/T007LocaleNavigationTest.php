<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T007LocaleNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expertise_language_switch_preserves_only_canonical_categories(): void
    {
        foreach (['database', 'integration', 'security', 'delivery'] as $category) {
            $this->get('/expertise?lang=en&category='.$category)
                ->assertOk()
                ->assertSee('href="'.route('public.expertise', ['lang' => 'ar', 'category' => $category]).'"', false);
        }

        foreach (['all', 'unknown', ''] as $category) {
            $this->get('/expertise?lang=en&category='.$category)
                ->assertOk()
                ->assertSee('href="'.route('public.expertise', ['lang' => 'ar']).'"', false);
        }

        $this->get('/expertise?lang=en&category[]=database')
            ->assertOk()
            ->assertSee('href="'.route('public.expertise', ['lang' => 'ar']).'"', false);
    }

    public function test_expertise_filter_survives_switching_back_to_english(): void
    {
        $this->get('/expertise?lang=ar&category=security')
            ->assertOk()
            ->assertSee('href="'.route('public.expertise', ['lang' => 'en', 'category' => 'security']).'"', false);

        $this->get('/expertise?lang=ar&category[]=security')
            ->assertOk()
            ->assertSee('href="'.route('public.expertise', ['lang' => 'en']).'"', false);
    }

    public function test_recruiter_language_switch_preserves_only_safe_tracks(): void
    {
        foreach (['database', 'software', 'delivery'] as $track) {
            $this->get('/hire?lang=ar&track='.$track)
                ->assertOk()
                ->assertSee('href="'.route('public.hire', ['lang' => 'en', 'track' => $track]).'"', false);
        }

        $this->get('/hire?lang=ar&track[]=database')
            ->assertOk()
            ->assertSee('href="'.route('public.hire', ['lang' => 'en', 'track' => 'all']).'"', false);
    }

    public function test_page_navigation_landmark_has_bilingual_accessible_name(): void
    {
        $this->get('/about?lang=en')
            ->assertOk()
            ->assertSee('<nav class="t007-nav-links" aria-label="Site pages">', false);

        $this->get('/about?lang=ar')
            ->assertOk()
            ->assertSee('<nav class="t007-nav-links" aria-label="روابط صفحات الموقع">', false);
    }

    public function test_language_switch_is_visible_and_announces_destination_language(): void
    {
        $this->get('/about?lang=en')
            ->assertOk()
            ->assertSee('class="t007-contact t007-language-switch" lang="ar" hreflang="ar"', false)
            ->assertSee('href="'.route('public.about', ['lang' => 'ar']).'"', false);

        $this->get('/about?lang=ar')
            ->assertOk()
            ->assertSee('class="t007-contact t007-language-switch" lang="en" hreflang="en"', false)
            ->assertSee('href="'.route('public.about', ['lang' => 'en']).'"', false);
    }
}

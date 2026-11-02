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
                ->assertSee('href="'.e(route('public.expertise', ['lang' => 'ar', 'category' => $category])).'"', false);
        }

        foreach (['all', 'unknown', ''] as $category) {
            $this->get('/expertise?lang=en&category='.$category)
                ->assertOk()
                ->assertSee('href="'.e(route('public.expertise', ['lang' => 'ar'])).'"', false);
        }

        $this->get('/expertise?lang=en&category[]=database')
            ->assertOk()
            ->assertSee('href="'.e(route('public.expertise', ['lang' => 'ar'])).'"', false);
    }

    public function test_expertise_filter_survives_switching_back_to_english(): void
    {
        $this->get('/expertise?lang=ar&category=security')
            ->assertOk()
            ->assertSee('href="'.e(route('public.expertise', ['lang' => 'en', 'category' => 'security'])).'"', false);

        $this->get('/expertise?lang=ar&category[]=security')
            ->assertOk()
            ->assertSee('href="'.e(route('public.expertise', ['lang' => 'en'])).'"', false);
    }

    public function test_recruiter_language_switch_preserves_only_safe_tracks(): void
    {
        foreach (['database', 'software', 'delivery'] as $track) {
            $this->get('/hire?lang=ar&track='.$track)
                ->assertOk()
                ->assertSee('href="'.e(route('public.hire', ['lang' => 'en', 'track' => $track])).'"', false);
        }

        $this->get('/hire?lang=ar&track[]=database')
            ->assertOk()
            ->assertSee('href="'.e(route('public.hire', ['lang' => 'en', 'track' => 'all'])).'"', false);
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
            ->assertDontSee('<a class="t007-contact t007-language-switch"', false)
            ->assertSee('class="language-link t007-footer-language-switch"', false)
            ->assertSee('lang="ar"', false)
            ->assertSee('hreflang="ar"', false)
            ->assertSee('href="'.route('public.about', ['lang' => 'ar']).'"', false);

        $this->get('/about?lang=ar')
            ->assertOk()
            ->assertDontSee('<a class="t007-contact t007-language-switch"', false)
            ->assertSee('class="language-link t007-footer-language-switch"', false)
            ->assertSee('lang="en"', false)
            ->assertSee('hreflang="en"', false)
            ->assertSee('href="'.route('public.about', ['lang' => 'en']).'"', false);
    }

    public function test_t007_hero_and_evidence_gated_cards_render_bilingual_rtl_content(): void
    {
        $screens = [
            ['/', 'الهوية المهنية', 'وليد عطية', 'مشاريع تم التحقق منها', 'PERSONAL BRAND'],
            ['/about', 'المسيرة المهنية', 'نبذة', 'تُعرض فقط السجلات المهنية', 'CAREER STORY'],
            ['/expertise', 'القدرات المهنية', 'الخبرات', 'قواعد البيانات والموثوقية', 'CAPABILITIES'],
            ['/ai-engineering', 'تطبيقات الذكاء الاصطناعي', 'هندسة الذكاء الاصطناعي', 'تصميم سير عمل الذكاء الاصطناعي', 'AI PRACTICE'],
            ['/hire', 'عرض التوظيف', 'مساحة التوظيف', 'المهارات المنشورة بالأدلة فقط', 'HIRING VIEW'],
        ];
        foreach ($screens as [$route, $pill, $title, $details, $englishPill]) {
            $this->get($route.'?lang=ar')->assertOk()
                ->assertSee('dir="rtl"', false)
                ->assertSee('class="t007-reference-stage"', false)
                ->assertSee($pill)
                ->assertSee($title)
                ->assertSee($details)
                ->assertDontSee($englishPill);
            $this->get($route.'?lang=en')->assertOk()
                ->assertSee('dir="ltr"', false)
                ->assertSee($englishPill);
        }
    }

    public function test_t007_arabic_cards_link_to_real_localized_routes_without_unverified_cv_claims(): void
    {
        $this->get('/?lang=ar')->assertOk()
            ->assertSee('دراسات الحالة المنشورة فقط')
            ->assertSee('href="'.route('public.projects', ['lang' => 'ar']).'"', false);
        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('لا توجد فترات مهنية اجتازت التحقق والنشر بعد.')
            ->assertDontSee('Government & enterprise systems');
        $this->get('/hire?lang=ar')->assertOk()
            ->assertSee('href="'.route('public.resume', ['lang' => 'ar']).'"', false)
            ->assertSee('السيرة العامة حسب المسار المختار');
    }


    public function test_recruiter_resume_link_preserves_vetted_focus_and_locale(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach (['database', 'software', 'delivery'] as $track) {
                $this->get('/hire?lang='.$locale.'&track='.$track)->assertOk()
                    ->assertSee('href="'.e(route('public.resume', ['lang' => $locale, 'track' => $track])).'"', false);
                $this->get('/resume?lang='.$locale.'&track='.$track)->assertOk()
                    ->assertSee('aria-current="page"', false);
            }

            foreach (['unsupported', '', 'all'] as $invalid) {
                $this->get('/hire?lang='.$locale.'&track='.$invalid)->assertOk()
                    ->assertSee('href="'.e(route('public.resume', ['lang' => $locale, 'track' => 'all'])).'"', false);
            }

            $this->get('/hire?lang='.$locale.'&track[]=database')->assertOk()
                ->assertSee('href="'.e(route('public.resume', ['lang' => $locale, 'track' => 'all'])).'"', false)
                ->assertDontSee('track%5B0%5D=', false);
        }
    }

    public function test_supplemental_navigation_is_localized_and_keyboard_discoverable_on_all_five_screens(): void
    {
        $screens = ['/', '/about', '/expertise', '/ai-engineering', '/hire'];
        foreach ($screens as $screen) {
            foreach (['en', 'ar'] as $locale) {
                $page = $this->get($screen.'?lang='.$locale)->assertOk();
                $page->assertSee('id="t007-primary-content" tabindex="-1"', false)
                    ->assertSee('href="#t007-primary-content"', false)
                    ->assertSee('.t007-sr-only:focus-visible', false)
                    ->assertSee('href="'.route('public.lab', ['lang' => $locale]).'"', false)
                    ->assertSee('href="'.route('public.search', ['lang' => $locale]).'"', false)
                    ->assertSee($locale === 'ar' ? 'المختبر التقني' : 'Tech lab')
                    ->assertSee($locale === 'ar' ? 'البحث في الموقع' : 'Site search');
            }
        }
    }
}

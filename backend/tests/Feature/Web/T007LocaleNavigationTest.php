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
            ['/ai-engineering', 'تطبيقات الذكاء الاصطناعي', 'هندسة الذكاء الاصطناعي', 'سلامة الذكاء الاصطناعي', 'AI PRACTICE'],
            ['/hire', 'عرض التوظيف', 'مساحة التوظيف', 'المهارات المنشورة بالأدلة فقط', 'HIRING VIEW'],
        ];
        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('class="wa-about about-screen"', false)
            ->assertSee('المسيرة المهنية')->assertSee('نبذة')
            ->assertDontSee('CAREER STORY');
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

    public function test_expertise_keyboard_rail_scrolls_focused_link_without_changing_frozen_design(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->get('/expertise?lang='.$locale)->assertOk()
                ->assertSee("rail.addEventListener('focusin'", false)
                ->assertSee("link.scrollIntoView({behavior: 'instant', block: 'nearest', inline: 'nearest'})", false)
                ->assertSee('class="t007-nav-links"', false);
        }

        // Other pages continue to use their existing navigation behaviour.
        $this->get('/about?lang=en')->assertOk()
            ->assertDontSee("rail.addEventListener('focusin'", false);
    }

    public function test_new_owner_expertise_four_state_hero_supersedes_legacy_reference_stage(): void
    {
        $this->get('/expertise?lang=en')->assertOk()
            ->assertSee('CAPABILITIES')
            ->assertSee('Expertise')
            ->assertSee('Database Administration &amp; Reliability', false)
            ->assertSee('class="expertise-tab-nav"', false)
            ->assertDontSee('class="t007-reference-stage"', false);

        $this->get('/expertise?lang=ar')->assertOk()
            ->assertSee('القدرات المهنية')
            ->assertSee('الخبرات')
            ->assertSee('إدارة قواعد البيانات والموثوقية')
            ->assertDontSee('class="t007-reference-stage"', false);
    }

    public function test_new_owner_home_supersedes_old_reference_stage_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/?lang='.$locale)->assertOk()
                ->assertSee('class="w26-desktop"', false)
                ->assertSee('class="w26-mobile"', false)
                ->assertDontSee('class="t007-reference-stage"', false);
            $page->assertSee($locale === 'ar'
                ? 'قيادة تقنية المؤسسات وهندسة حلول الذكاء الاصطناعي'
                : 'Enterprise Technology &amp; AI Solutions Architecture', false);
        }
    }

    public function test_t007_arabic_cards_link_to_real_localized_routes_without_unverified_cv_claims(): void
    {
        $this->get('/?lang=ar')->assertOk()
            ->assertSee('المشاريع ودراسات الحالة المتحقق منها')
            ->assertSee('href="'.route('public.projects', ['lang' => 'ar']).'"', false);
        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('لا توجد فترات مهنية تم التحقق منها وإتاحتها للنشر حاليًا.')
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
        $screens = ['/about', '/expertise', '/ai-engineering', '/hire'];
        foreach ($screens as $screen) {
            foreach (['en', 'ar'] as $locale) {
                $page = $this->get($screen.'?lang='.$locale)->assertOk();
                $page->assertSee('id="t007-primary-content" tabindex="-1"', false)
                    ->assertSee('href="#t007-primary-content"', false)
                    ->assertSee('.t007-sr-only:focus-visible', false)
                    ->assertSee('href="'.route('public.lab', ['lang' => $locale]).'"', false)
                    ->assertSee('href="'.route('public.search', ['lang' => $locale]).'"', false)
                    ->assertSee($locale === 'ar' ? 'المختبر التقني' : 'Tech lab')
                    ->assertSee($locale === 'ar' ? 'البحث في الموقع' : 'Search');
            }
        }
    }
    public function test_new_home_navigation_keeps_screen_reader_and_search_destinations(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/?lang='.$locale)->assertOk();
            $page->assertSee('href="#main-content"', false)
                ->assertSee('id="main-content" tabindex="-1"', false)
                ->assertSee('href="'.route('public.search', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.lab', ['lang' => $locale]).'"', false);
        }
    }

    public function test_home_primary_navigation_marks_only_current_page_and_anchors_are_functional(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $home = $this->get('/?lang='.$locale)->assertOk();
            $home->assertSee('href="'.route('home', ['lang' => $locale]).'"', false)
                ->assertSee('aria-current="page"', false);
            $home->assertSee('href="#main-content"', false)
                ->assertSee('id="main-content" tabindex="-1"', false);
        }
    }

    private function publishT007OwnerCv(): \App\Models\User
    {
        $user = \App\Models\User::create([
            'email' => 't007-cv-published@example.test',
            'status' => 'active',
        ]);
        $user->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'owner'])->id);
        \App\Models\Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Verified test owner',
            'publish_status' => 'published',
        ]);

        return $user;
    }

    public function test_about_and_recruiter_download_the_real_bilingual_evidence_gated_pdf(): void
    {
        // No fake download CTA while the server intentionally returns 404
        // for a nonexistent unpublished public owner profile.
        $this->get('/resume/pdf?lang=en')->assertNotFound();
        $this->get('/about?lang=en')->assertOk()
            ->assertDontSee('type="application/pdf" download', false);
        $this->get('/hire?lang=ar')->assertOk()
            ->assertDontSee('type="application/pdf" download', false)
            ->assertSee('السيرة الموثقة غير متاحة للنشر بعد.');

        $owner = $this->publishT007OwnerCv();
        foreach (['ar', 'en'] as $locale) {
            $this->get('/about?lang='.$locale)->assertOk()
                ->assertSee('href="'.e(route('public.resume.pdf', [
                    'lang' => $locale, 'track' => 'all',
                ])).'"', false)
                ->assertSee('type="application/pdf" download', false);
            foreach (['all', 'database', 'software', 'delivery'] as $track) {
                $this->get('/hire?lang='.$locale.'&track='.$track)->assertOk()
                    ->assertSee('href="'.e(route('public.resume.pdf', [
                        'lang' => $locale, 'track' => $track,
                    ])).'"', false);
            }
            $this->get('/hire?lang='.$locale.'&track[]=private')->assertOk()
                ->assertSee('href="'.e(route('public.resume.pdf', [
                    'lang' => $locale, 'track' => 'all',
                ])).'"', false)
                ->assertDontSee('track%5B0%5D=', false);
            $pdf = $this->get('/resume/pdf?lang='.$locale.'&track=delivery')
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $this->assertStringContainsString('attachment;', (string) $pdf->headers->get('Content-Disposition'));
            $this->assertStringContainsString('delivery-'.$locale.'.pdf',
                (string) $pdf->headers->get('Content-Disposition'));
            $this->assertSame('no-store, private', $pdf->headers->get('Cache-Control'));
        }

        // Privacy revocation immediately hides each download, not just
        // the PDF response. Public HTML preview remains the honest fallback.
        $owner->update(['status' => 'inactive']);
        $this->get('/resume/pdf?lang=en')->assertNotFound();
        $this->get('/about?lang=en')->assertOk()
            ->assertDontSee('type="application/pdf" download', false);
        $this->get('/hire?lang=en')->assertOk()
            ->assertDontSee('type="application/pdf" download', false);
    }

    public function test_recruiter_first_viewport_cards_have_distinct_real_destinations_and_direct_pdf(): void
    {
        $this->publishT007OwnerCv();
        foreach (['ar', 'en'] as $locale) {
            $html = $this->get('/hire?lang='.$locale.'&track=database')->assertOk();
            $html->assertSee('href="'.e(route('public.hire', [
                'lang' => $locale, 'track' => 'database',
            ]).'#recruiter-profile-heading').'"', false)
                ->assertSee('href="'.e(route('public.github', ['lang' => $locale])).'"', false)
                ->assertSee('href="'.e(route('public.resume.pdf', [
                    'lang' => $locale, 'track' => 'database',
                ])).'"', false)
                ->assertSee('type="application/pdf" download', false);
        }
    }

}

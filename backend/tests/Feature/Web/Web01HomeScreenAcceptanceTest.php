<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-01 source-level journey regressions. These do not certify pixel parity:
 * the real runtime/decoded-RGBA comparison remains mandatory under #481.
 */
class Web01HomeScreenAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_reference_shell_has_functioning_localized_primary_actions(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/?lang='.$locale)->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('class="t007-reference-stage"', false)
                ->assertSee('href="#t007-primary-content"', false)
                ->assertSee('id="t007-primary-content" tabindex="-1"', false);

            foreach (['public.projects', 'public.ai-engineering', 'public.lab',
                'public.blog', 'public.resume', 'public.contact'] as $destination) {
                $page->assertSee('href="'.route($destination, ['lang' => $locale]).'"', false);
            }

            $page->assertSee($locale === 'ar' ? 'عرض الأدلة' : 'View evidence')
                ->assertSee($locale === 'ar' ? 'الهوية المهنية' : 'PERSONAL BRAND');
        }
    }

    public function test_home_only_uses_approved_local_portrait_with_precise_reference_crop(): void
    {
        // Photo comes from the existing approved first-party endpoint, never
        // a design PNG or a generated face. Home-only crop fixes the vertical
        // misalignment measured in real T007 diagnostics.
        $this->get('/?lang=en')->assertOk()
            ->assertSee('class="t007-visual-stack portrait"', false)
            ->assertSee('<div class="tile three"><img src="'.route('public.owner-portrait').'"', false)
            ->assertSee('.t007-reference-stage .t007-visual-stack.portrait .three img {', false)
            ->assertSee('object-position: center 80%;', false)
            ->assertDontSee('src="assets/screen-reference/web_home.png"', false);

        // Other T007 reference screens retain their independently owned crop.
        $this->get('/about?lang=en')->assertOk()
            ->assertDontSee('object-position: center 80%;', false);
    }

    public function test_unapproved_home_claims_do_not_replace_truthful_empty_states(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/?lang='.$locale)->assertOk();
            $page->assertDontSee('Government &amp; enterprise systems', false)
                ->assertDontSee('20+ Years')
                ->assertDontSee('1,248');
            $page->assertSee($locale === 'ar'
                ? 'لا توجد دراسات حالة عامة مستوفية'
                : 'No evidence-verified public case studies are available yet.');
            $page->assertSee($locale === 'ar'
                ? 'لا توجد مقالات منشورة وموثقة'
                : 'No source-verified published articles are available yet.');
        }
    }
}

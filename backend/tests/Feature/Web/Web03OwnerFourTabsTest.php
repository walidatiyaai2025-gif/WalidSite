<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner-supplied 2026 four-state visual WEB-03 reference.
 * Feature assertions are not a screenshot pixel-parity certificate.
 */
final class Web03OwnerFourTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_section_is_deep_linkable_with_its_exact_content_groups_and_six_cards(): void
    {
        $states = [
            'overview' => [
                'count' => '1 / 4',
                'description' => 'Enterprise technology leader focused on program delivery',
                'tag' => 'Core stack / Tools',
                'first' => 'Technical Program &amp; Project Delivery',
                'last' => 'Infrastructure, Deployment &amp; Support',
                'highlight' => 'Professional highlights',
            ],
            'delivery' => [
                'count' => '2 / 4',
                'description' => 'Delivery leadership across enterprise and government technology work',
                'tag' => 'Delivery focus',
                'first' => 'Enterprise Program Leadership',
                'last' => 'FOODEX Delivery Leadership',
                'highlight' => 'Delivery highlights',
            ],
            'technology' => [
                'count' => '3 / 4',
                'description' => 'A practical technology stack centered on backend platforms',
                'tag' => 'Technology stack',
                'first' => 'Laravel / PHP Platforms',
                'last' => 'Reliability &amp; Recovery',
                'highlight' => 'Technical highlights',
            ],
            'ai' => [
                'count' => '4 / 4',
                'description' => 'Applying AI pragmatically inside real delivery environments',
                'tag' => 'AI &amp; operations',
                'first' => 'AI Product Integration',
                'last' => 'Professional Delivery Profile',
                'highlight' => 'Professional strengths',
            ],
        ];
        foreach ($states as $section => $expected) {
            $response = $this->get(route('public.expertise', [
                'lang' => 'en', 'section' => $section,
            ]))->assertOk()
                ->assertSee('id="expertise-title"', false)
                ->assertSee('id="t007-primary-content"', false)
                ->assertSee('class="expertise-tab-nav"', false)
                ->assertSee($expected['count'])
                ->assertSee($expected['description'])
                ->assertSee($expected['tag'], false)
                ->assertSee($expected['first'], false)
                ->assertSee($expected['last'], false)
                ->assertSee($expected['highlight'])
                ->assertSee('id="expertise-catalog"', false)
                ->assertSee('class="expertise-highlight-bar"', false)
                ->assertDontSee('class="t007-reference-stage"', false);

            $html = $response->getContent();
            $this->assertSame(6, substr_count($html, 'class="expertise-feature"'));
            $this->assertSame(4, substr_count($html, 'class="expertise-highlight"'));
            foreach (array_keys($states) as $target) {
                $url = route('public.expertise', ['lang' => 'en', 'section' => $target]);
                $this->assertStringContainsString('href="'.e($url).'"', $html);
            }
            $activeUrl = e(route('public.expertise', ['lang' => 'en', 'section' => $section]));
            $this->assertMatchesRegularExpression(
                '/<a href="'.preg_quote($activeUrl, '/').'" aria-current="page">/',
                $html
            );
            if ($section === 'ai') {
                $response->assertSee('Evidence-backed expertise across delivery, databases, platforms, AI, and operations.');
            } else {
                $response->assertDontSee('Evidence-backed expertise across delivery, databases, platforms, AI, and operations.');
            }
        }
    }

    public function test_four_sections_have_arabic_localization_and_keyboard_navigation(): void
    {
        $translations = [
            'overview' => 'نظرة عامة',
            'delivery' => 'التسليم',
            'technology' => 'التقنية',
            'ai' => 'الذكاء الاصطناعي والتشغيل',
        ];

        foreach ($translations as $section => $title) {
            $page = $this->get('/expertise?lang=ar&section='.$section)->assertOk()
                ->assertSee('lang="ar" dir="rtl"', false)
                ->assertSee('القدرات المهنية')
                ->assertSee('عرض الأدلة')
                ->assertSee('href="#expertise-catalog"', false)
                ->assertSee('class="expertise-tab-nav"', false)
                ->assertSee($title)
                ->assertSee('id="expertise-category"', false)
                ->assertSee('@media(max-width:700px)', false);
            foreach (array_keys($translations) as $target) {
                $page->assertSee('href="'.e(route('public.expertise', [
                    'lang' => 'ar', 'section' => $target,
                ])).'"', false);
            }
        }
    }

    public function test_invalid_tab_never_reflects_raw_query_or_discloses_unpublished_skills(): void
    {
        foreach ([
            '/expertise?lang=en&section[]=ai',
            '/expertise?lang=en&section=unrecognized',
            '/expertise?lang=en&section=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
        ] as $uri) {
            $this->get($uri)->assertOk()
                ->assertSee('1 / 4')
                ->assertSee('Technical Program &amp; Project Delivery', false)
                ->assertDontSee('unrecognized')
                ->assertDontSee('alert(1)')
                ->assertDontSee('section%5B', false)
                ->assertDontSee('20+ Years')
                ->assertDontSee('PMP®');
        }
    }

    public function test_owner_design_does_not_displace_protected_other_professional_pages(): void
    {
        $this->get('/about?lang=en')->assertOk()
            ->assertSee('class="t007-reference-stage"', false)
            ->assertDontSee('class="expertise-tab-nav"', false);
        $this->get('/hire?lang=en')->assertOk()
            ->assertSee('class="t007-reference-stage"', false)
            ->assertDontSee('class="expertise-tab-nav"', false);
    }
}

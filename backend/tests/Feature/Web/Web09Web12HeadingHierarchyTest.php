<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-09 / WEB-12: one discoverable page H1, with functional headings nested
 * beneath the existing reference-stage title. This checks rendered HTML,
 * not zero-difference visual parity or a production browser capture.
 */
final class Web09Web12HeadingHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_lab_has_one_page_h1_and_a_styled_functional_h2_in_both_languages(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
            $response = $this->get(route('public.lab', ['lang' => $lang]))
                ->assertOk()
                ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                ->assertSee('id="main-content"', false)
                ->assertSee('<h2 class="lab-page-title">', false)
                ->assertDontSee('<h1 class="lab-page-title">', false);

            $html = $response->getContent();
            preg_match_all('/<h1(?:\\s|>)/i', $html, $headings);
            $this->assertCount(1, $headings[0], 'WEB-09 must have one page H1: '.$lang);
            $this->assertSame(1, substr_count($html, '<h2 class="lab-page-title">'));
            $this->assertStringContainsString(
                '.editorial .lab-page-title{font-size:clamp(31px,4vw,48px);',
                $html,
                'WEB-09 H2 must retain the original functional-title font geometry',
            );
            $this->assertStringContainsString(
                $lang === 'ar' ? 'المختبر التقني' : 'Technology lab',
                $html,
            );
        }
    }

    public function test_recruiter_has_one_page_h1_and_original_hero_title_as_h2(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $lang => $direction) {
            foreach (['all', 'database'] as $track) {
                $response = $this->get(route('public.hire', [
                    'lang' => $lang, 'track' => $track,
                ]))
                    ->assertOk()
                    ->assertSee('<html lang="'.$lang.'" dir="'.$direction.'">', false)
                    ->assertSee('id="main-content"', false)
                    ->assertSee('<h2 class="recruiter-title" id="recruiter-title">', false)
                    ->assertDontSee('<h1 id="recruiter-title">', false);

                $html = $response->getContent();
                preg_match_all('/<h1(?:\\s|>)/i', $html, $headings);
                $this->assertCount(1, $headings[0], 'WEB-12 one page H1: '.$lang.'/'.$track);
                $this->assertSame(1, substr_count($html, 'id="recruiter-title"'));
                $this->assertStringContainsString(
                    '.recruiter-hero .recruiter-title{font-size:clamp(34px,4vw,48px);',
                    $html,
                    'WEB-12 H2 must retain the original hero-title dimensions',
                );
                $this->assertStringContainsString(
                    $lang === 'ar' ? 'وضع التوظيف' : 'Recruiter Mode',
                    $html,
                );
                $this->assertStringContainsString(
                    'name="track"',
                    $html,
                    'The functional career-track form remains available',
                );
            }
        }
    }
}

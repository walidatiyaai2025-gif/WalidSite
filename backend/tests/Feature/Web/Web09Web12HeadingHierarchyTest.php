<?php

namespace Tests\\Feature\\Web;

use Tests\\TestCase;

final class Web09Web12HeadingHierarchyTest extends TestCase
{
    /**
     * The reference stage owns the page H1. Functional content must nest
     * below it without changing the existing visual title treatment.
     */
    private function assertSingleVisiblePageH1(string $html): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \\DOMDocument('1.0', 'UTF-8');
            $this->assertTrue($document->loadHTML(
                $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            ));
            $xpath = new \\DOMXPath($document);
            $main = $xpath->query('//main[@id="main-content"]')->item(0);
            $this->assertNotNull($main);
            $headings = $xpath->query('.//h1', $main);
            $this->assertSame(1, $headings->length);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function test_tech_lab_keeps_one_reference_h1_and_a_styled_functional_h2_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/lab?lang='.$locale)
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('class="lab-page-title"', false)
                ->assertSee('<h2 class="lab-page-title">', false);

            $page->assertSee($locale === 'ar' ? 'المختبر التقني' : 'Technology lab');
            $this->assertSingleVisiblePageH1($page->getContent());
        }
    }

    public function test_recruiter_mode_keeps_one_reference_h1_and_a_styled_functional_h2_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/hire?lang='.$locale)
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('class="recruiter-title" id="recruiter-title"', false)
                ->assertSee('<h2 class="recruiter-title" id="recruiter-title">', false);

            $page->assertSee($locale === 'ar' ? 'وضع التوظيف' : 'Recruiter Mode');
            $this->assertSingleVisiblePageH1($page->getContent());
        }
    }
}

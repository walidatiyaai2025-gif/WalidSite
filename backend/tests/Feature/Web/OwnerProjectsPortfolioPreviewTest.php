<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerProjectsPortfolioPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_cv_showcase_is_opt_in_and_cannot_override_published_case_studies(): void
    {
        $preview = $this->get('/projects?preview=cv&lang=en');
        $preview->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private');

        // The publication-gated web catalog remains the default even when
        // malformed query input tries to select the owner review design.
        $this->get('/projects?lang=en')->assertOk()
            ->assertSee('No published projects currently meet the public evidence checks.')
            ->assertDontSee('CV documented');
        $this->get('/projects?preview%5B%5D=cv&lang=en')->assertOk()
            ->assertSee('No published projects currently meet the public evidence checks.');
    }

    public function test_preview_is_the_real_six_section_source_aware_design(): void
    {
        $file = public_path('portfolio-projects-preview.html');
        $this->assertFileExists($file);
        $html = file_get_contents($file);
        $this->assertIsString($html);

        foreach ([
            'Projects Portfolio', 'Government & Infrastructure',
            'Education & Student Platforms', 'Workflows & Public Systems',
            'AI & Modern Products', 'Enterprise & Client Delivery',
            'DiwanMAM', 'FOODEX', 'AI WordPress Manager', 'AIMWWeb',
            'WalidSite', 'Core Data Center', 'Fatwa Automation',
            'KNA Historical Search', 'Me6rash', 'ShareBoosting',
            'PAAET Portal', 'SafeContracts', '3Fareet',
        ] as $item) {
            $this->assertStringContainsString($item, $html);
        }
        $this->assertStringContainsString('CV)\'', $html);
        $this->assertStringContainsString('noindex,nofollow', $html);
        $this->assertStringContainsString('href="/expertise?lang=en"', $html);
        $this->assertStringContainsString('href="/contact?lang=en"', $html);
        $this->assertStringContainsString('function go(i)', $html);
        $this->assertStringContainsString('function openModal(i)', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringNotContainsString('50+ Delivered Projects', $html);
        $this->assertStringNotContainsString('20+ Years Experience', $html);
    }

    public function test_normal_projects_page_has_an_accessible_preview_link(): void
    {
        $this->get('/projects?lang=en')->assertOk()
            ->assertSee('Owner CV + GitHub project designs')
            ->assertSee('Open Projects design preview')
            ->assertSee('preview=cv', false);

        $this->get('/projects?lang=ar')->assertOk()
            ->assertSee('تصميم المشاريع من واقع السيرة الذاتية')
            ->assertSee('افتح معاينة التصاميم');
    }
}

<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WEB-06 / #658: real AI Engineering user journey and reference-screen
 * interaction contract. This is not a synthetic pixel-fidelity attestation.
 */
final class Web06AiEngineeringVisualJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilingual_screen_exposes_functional_reference_and_evidence_navigation(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/ai-engineering?lang='.$locale)
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('id="ai-evidence"', false)
                ->assertSee('href="#ai-skills"', false)
                ->assertSee('href="#ai-safety"', false)
                ->assertSee('href="#ai-public-work"', false)
                ->assertSee('id="ai-skills"', false)
                ->assertSee('id="ai-safety"', false)
                ->assertSee('id="ai-public-work"', false)
                ->assertSee('href="#t007-primary-content"', false)
                ->assertSee('href="'.route('public.projects', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.github', ['lang' => $locale]).'"', false);

            $page->assertSee($locale === 'ar'
                ? 'هندسة الذكاء الاصطناعي — المراجعة والأدلة'
                : 'AI engineering — review and evidence');
            $this->get(route('public.projects', ['lang' => $locale]))->assertOk();
            $this->get(route('public.github', ['lang' => $locale]))->assertOk();
        }
    }

    public function test_empty_public_ai_feed_never_fabricates_verified_provider_or_workflow_claims(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->getJson('/api/v1/public/expertise/ai?lang='.$locale)
                ->assertOk()
                ->assertJsonPath('data.workflows', [])
                ->assertJsonPath('data.verified_demos', []);

            $this->get('/ai-engineering?lang='.$locale)
                ->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'لا توجد قدرات ذكاء اصطناعي اجتازت بوابة الأدلة للنشر حاليًا.'
                    : 'No AI capabilities have cleared the public evidence gate yet.')
                ->assertSee($locale === 'ar'
                    ? 'لا يتم عرض عروض أو تدفقات عمل على أنها موثقة دون ارتباط أدلة مستقل.'
                    : 'No demo or workflow is presented as verified without an independently approved evidence association.')
                ->assertDontSee('Multi-provider AI')
                ->assertDontSee('Self-hosted + paid providers')
                ->assertDontSee('99.9%')
                ->assertDontSee('SECRET');
        }
    }

    public function test_visual_shell_has_responsive_accessible_navigation_without_mutating_shared_reference(): void
    {
        $page = $this->get('/ai-engineering?lang=en')->assertOk()
            ->assertSee('aria-label="AI engineering evidence sections"', false)
            ->assertSee('aria-labelledby="ai-evidence-title"', false)
            ->assertSee('aria-labelledby="ai-skills-title"', false)
            ->assertSee('aria-labelledby="ai-safety-title"', false)
            ->assertSee('aria-labelledby="ai-public-work-title"', false)
            ->assertSee('@media (max-width: 900px)', false)
            ->assertSee('@media (max-width: 600px)', false)
            ->assertSee('min-height: 44px', false)
            ->assertSee('prefers-reduced-motion: reduce', false)
            ->assertSee('.ai-evidence-panel a:focus-visible', false);
        $page->assertDontSee('No AI capabilities are available globally');
    }
}

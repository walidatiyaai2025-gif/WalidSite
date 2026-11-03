<?php

namespace Tests\Feature\Web;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WEB-02's reference-screen actions must resolve to real, focusable content.
 * These tests verify behavior; they do not claim pixel-perfect visual parity.
 */
final class Web02AboutVisualActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_hero_evidence_action_targets_the_actual_about_proof_in_both_locales(): void
    {
        Http::fake();

        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/about?lang='.$locale)->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'">', false)
                ->assertSee('class="t007-reference-action secondary" href="#about-evidence"', false)
                ->assertSee('class="t007-reference-action primary" href="#t007-primary-content"', false)
                ->assertSee('id="about-evidence" class="about-panel about-panel--proof" aria-labelledby="about-evidence-heading" tabindex="-1"', false)
                ->assertSee('id="about-career" class="about-panel" aria-labelledby="about-career-heading" tabindex="-1"', false)
                ->assertSee('id="about-certifications" class="about-panel" aria-labelledby="about-certifications-heading" tabindex="-1"', false)
                ->assertSee('section[id^="about-"]:target', false)
                ->assertSee('href="'.route('public.resume', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.projects', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.contact', ['lang' => $locale]).'"', false)
                ->assertDontSee('20+ Years')
                ->assertDontSee('PMP®');

            $page->assertSee($locale === 'ar' ? 'عرض الأدلة' : 'View evidence');
            $this->get(route('public.resume', ['lang' => $locale]))->assertOk();
        }

        // The new owner-directed WEB-01 has an independent visual hero.
        // Its project CTA must stay on Projects, never jump to About's evidence.
        $this->get('/?lang=en')->assertOk()
            ->assertSee('class="w26-laptop" href="'.route('public.projects', ['lang' => 'en']).'"', false)
            ->assertDontSee('href="#about-evidence"', false);

        Http::assertNothingSent();
    }

    public function test_evidence_target_exposes_only_approved_public_owner_sources(): void
    {
        Http::fake();

        $owner = User::create(['email' => 'web02-owner@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Reviewed owner',
            'bio_en' => 'PRIVATE-WEB02-BIO',
            'publish_status' => 'published',
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Reviewed accessible proof',
            'claim_ar' => 'دليل عام تمت مراجعته',
            'evidence_url' => 'https://docs.example.org/web02-proof',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'PRIVATE-WEB02-CLAIM',
            'claim_ar' => 'بيانات خاصة',
            'evidence_url' => 'https://127.0.0.1/private',
            'status' => 'approved',
            'is_public' => true,
        ]);

        foreach (['en', 'ar'] as $locale) {
            $this->get('/about?lang='.$locale)->assertOk()
                ->assertSee('class="t007-reference-action secondary" href="#about-evidence"', false)
                ->assertSee('id="about-evidence"', false)
                ->assertSee($locale === 'ar' ? 'دليل عام تمت مراجعته' : 'Reviewed accessible proof')
                ->assertSee('href="https://docs.example.org/web02-proof"', false)
                ->assertSee('rel="noopener noreferrer"', false)
                ->assertDontSee('PRIVATE-WEB02-CLAIM')
                ->assertDontSee('PRIVATE-WEB02-BIO')
                ->assertDontSee('127.0.0.1');
        }

        $owner->update(['status' => 'inactive']);
        $this->get('/about?lang=en')->assertOk()
            ->assertDontSee('Reviewed accessible proof');

        Http::assertNothingSent();
    }
}

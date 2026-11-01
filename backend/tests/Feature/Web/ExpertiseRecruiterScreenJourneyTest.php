<?php

namespace Tests\Feature\Web;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExpertiseRecruiterScreenJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function profile(bool $owner = true, string $status = 'active', string $published = 'published'): Profile
    {
        $user = User::create([
            'email' => uniqid('verified-owner-', true).'@example.test',
            'status' => $status,
        ]);
        if ($owner) {
            $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        }
        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Approved public owner',
            'bio_en' => 'CONFIDENTIAL-BIO',
            'publish_status' => $published,
        ]);
    }

    private function skill(Profile $profile, string $slug, string $category, bool $verified = true): void
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Verified '.$slug,
            'name_ar' => 'مهارة '.$slug,
            'category' => $category,
            'evidence_status' => $verified ? 'verified' : 'unverified',
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);
    }

    public function test_two_public_screens_render_real_navigation_bilingual_states_and_approved_portrait(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['expertise', 'hire'] as $page) {
                $response = $this->get(route('public.'.$page, ['lang' => $locale]))->assertOk()
                    ->assertSee('lang="'.$locale.'"', false)
                    ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee('Evidence-gated public profile')
                    ->assertSee('href="'.route('public.projects', ['lang' => $locale]).'"', false)
                    ->assertDontSee('CONFIDENTIAL-BIO')
                    ->assertDontSee('20+ Years')
                    ->assertDontSee('PMP®');
                if ($page === 'expertise') {
                    $response->assertSee('id="expertise-title"', false)
                        ->assertSee('href="#expertise-catalog"', false)
                        ->assertSee('id="expertise-category"', false)
                        ->assertSee('role="search"', false)
                        ->assertSee($locale === 'ar'
                            ? 'لم تُعتمد مهارات للنشر بعد.'
                            : 'No skills have been approved with verified evidence yet.');
                } else {
                    $response->assertSee('id="recruiter-title"', false)
                        ->assertSee('href="#recruiter-fit"', false)
                        ->assertSee('id="career-track"', false)
                        ->assertSee('src="'.route('public.owner-portrait').'"', false)
                        ->assertSee('href="'.e(route('public.resume', ['lang' => $locale, 'track' => 'all'])).'"', false)
                        ->assertSee('href="'.route('public.contact', ['lang' => $locale]).'"', false)
                        ->assertSee($locale === 'ar'
                            ? 'لا توجد مهارات موثقة ومتاحة'
                            : 'No evidence-verified skills are currently available');
                }
            }
        }
        $this->get(route('public.owner-portrait'))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_verified_expertise_card_routes_to_selected_recruiter_resume_and_contact_without_private_claims(): void
    {
        $profile = $this->profile();
        $this->skill($profile, 'approved-db', 'Database & Reliability');
        $this->skill($profile, 'approved-software', 'Software & Integration');
        $this->skill($profile, 'SECRET-unverified', 'Database & Reliability', false);
        $unrelated = $this->profile(false);
        $this->skill($unrelated, 'SECRET-unrelated', 'Database & Reliability');
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Public evidence statement',
            'claim_ar' => 'عبارة معتمدة للنشر',
            'evidence_url' => 'https://docs.example.org/verified',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'SECRET-private-claim',
            'claim_ar' => 'بيانات خاصة',
            'evidence_url' => 'https://docs.example.org/unauthorized?token=private',
            'status' => 'approved',
            'is_public' => true,
        ]);

        foreach (['en', 'ar'] as $locale) {
            $expertise = $this->get(route('public.expertise', [
                'lang' => $locale, 'category' => 'database',
            ]))->assertOk()
                ->assertSee($locale === 'ar' ? 'مهارة approved-db' : 'Verified approved-db')
                ->assertDontSee('approved-software')
                ->assertDontSee('SECRET-unverified')
                ->assertDontSee('SECRET-unrelated')
                ->assertSee('href="'.e(route('public.hire', [
                    'lang' => $locale, 'track' => 'database',
                ])).'"', false)
                ->assertSee('class="professional-track-link"', false)
                ->assertSee('value="database" selected', false);

            $hire = $this->get(route('public.hire', [
                'lang' => $locale, 'track' => 'database',
            ]))->assertOk()
                ->assertSee($locale === 'ar' ? 'مهارة approved-db' : 'Verified approved-db')
                ->assertSee($locale === 'ar' ? 'عبارة معتمدة للنشر' : 'Public evidence statement')
                ->assertSee('href="https://docs.example.org/verified"', false)
                ->assertSee('rel="noopener noreferrer"', false)
                ->assertSee('href="'.e(route('public.resume', [
                    'lang' => $locale, 'track' => 'database',
                ])).'"', false)
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $locale, 'track' => 'database',
                ])).'"', false)
                ->assertDontSee('approved-software')
                ->assertDontSee('SECRET-unverified')
                ->assertDontSee('SECRET-unrelated')
                ->assertDontSee('SECRET-private-claim')
                ->assertDontSee('token=private');

            $this->get(route('public.resume', [
                'lang' => $locale, 'track' => 'database',
            ]))->assertOk()->assertSee($locale === 'ar' ? 'مهارة approved-db' : 'Verified approved-db')
                ->assertSee('window.print()', false);
            $this->get(route('public.contact', [
                'lang' => $locale, 'track' => 'database',
            ]))->assertOk()->assertSee('class="contact-track-context"', false);
        }

        $profile->user->update(['status' => 'inactive']);
        $this->get('/expertise')->assertOk()->assertDontSee('approved-db');
        $this->get('/hire')->assertOk()
            ->assertDontSee('approved-db')
            ->assertDontSee('Public evidence statement');
    }

    public function test_malformed_filters_reset_to_safe_neutral_routes_without_echoing_injection(): void
    {
        foreach ([
            '/expertise?lang=en&category[]=database',
            '/expertise?lang=en&category=SECRET-untrusted',
            '/hire?lang=en&track[]=database',
            '/hire?lang=en&track=SECRET-untrusted',
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('SECRET-untrusted')
                ->assertDontSee('Array to string conversion')
                ->assertDontSee('CONFIDENTIAL-BIO');
        }
        $this->get('/hire?lang=en&track[]=database')->assertSee(
            'href="'.e(route('public.contact', ['lang' => 'en'])).'"', false
        );
    }
}

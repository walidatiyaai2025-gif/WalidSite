<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AboutCompleteScreenTest extends TestCase
{
    use RefreshDatabase;

    private function publishedOwner(): Profile
    {
        $owner = User::create(['email' => 'about-screen-owner@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Reviewed public owner',
            'bio_en' => 'Private biography must not be rendered',
            'publish_status' => 'published',
        ]);
    }

    public function test_about_has_working_sections_verified_portrait_and_printable_cv_route_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->get(route('public.about', ['lang' => $locale]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('id="about-screen-title"', false)
                ->assertSee('id="about-career"', false)
                ->assertSee('id="about-certifications"', false)
                ->assertSee('id="about-evidence"', false)
                ->assertSee('href="#about-career"', false)
                ->assertSee('href="#about-certifications"', false)
                ->assertSee('href="#about-evidence"', false)
                ->assertSee('src="'.route('public.owner-portrait').'"', false)
                ->assertSee('href="'.route('public.resume', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.projects', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.contact', ['lang' => $locale]).'"', false)
                ->assertSee('role="status"', false)
                ->assertDontSee('Private biography must not be rendered');

            $this->get(route('public.resume', ['lang' => $locale]))
                ->assertOk()
                ->assertSee('window.print()', false);
            $html->assertSee('width="285" height="356"', false);
        }

        $this->get('/about')->assertSee('No career periods have completed verification');
        $this->get('/about?lang=ar')->assertSee('لا توجد فترات مهنية');
    }

    public function test_verified_owner_career_and_approved_public_evidence_are_visible_without_private_claims(): void
    {
        $profile = $this->publishedOwner();
        CareerPosition::create([
            'profile_id' => $profile->id,
            'role_en' => 'Reviewed database engineer',
            'role_ar' => 'مهندس قواعد بيانات معتمد',
            'org_name' => 'Verified public organization',
            'started_on' => '2022-06-01',
            'ended_on' => '2024-03-01',
            'verified' => true,
            'publishable' => true,
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'role_en' => 'Private pending job',
            'role_ar' => 'وظيفة غير منشورة',
            'org_name' => 'Sensitive organization',
            'started_on' => '2025-01-01',
            'verified' => false,
            'publishable' => false,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Reviewed professional statement',
            'claim_ar' => 'عبارة مهنية موثقة',
            'evidence_url' => 'https://evidence.example.org/approved',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Blocked internal statement',
            'claim_ar' => 'عبارة خاصة',
            'evidence_url' => 'https://internal.lan/private',
            'status' => 'approved',
            'is_public' => true,
        ]);

        $english = $this->get('/about?lang=en')->assertOk()
            ->assertSee('Reviewed database engineer')
            ->assertSee('Verified public organization')
            ->assertSee('<time datetime="2022-06-01">2022</time>', false)
            ->assertSee('<time datetime="2024-03-01">2024</time>', false)
            ->assertSee('Reviewed professional statement')
            ->assertSee('href="https://evidence.example.org/approved"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('Private pending job')
            ->assertDontSee('Sensitive organization')
            ->assertDontSee('Blocked internal statement')
            ->assertDontSee('internal.lan')
            ->assertDontSee('Private biography must not be rendered');
        $english->assertSee('id="about-evidence"', false);

        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('مهندس قواعد بيانات معتمد')
            ->assertSee('عبارة مهنية موثقة')
            ->assertDontSee('وظيفة غير منشورة')
            ->assertDontSee('عبارة خاصة');

        $profile->user->update(['status' => 'pending_activation']);
        $this->get('/about')->assertOk()
            ->assertDontSee('Reviewed database engineer')
            ->assertDontSee('Reviewed professional statement');
    }

    public function test_unrelated_account_cannot_supply_about_timeline_or_claims(): void
    {
        $person = User::create(['email' => 'about-private-account@example.test', 'status' => 'active']);
        $profile = Profile::create([
            'user_id' => $person->id,
            'public_name' => 'Not the owner',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'role_en' => 'Unrelated private role',
            'role_ar' => 'دور خاص',
            'org_name' => 'Private external employer',
            'started_on' => '2024-01-01',
            'verified' => true,
            'publishable' => true,
        ]);

        $this->get('/about')->assertOk()
            ->assertDontSee('Unrelated private role')
            ->assertDontSee('Private external employer');
    }
}

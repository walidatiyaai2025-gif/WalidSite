<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicResumeTracksTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $status = 'published'): Profile
    {
        $user = User::create([
            'email' => uniqid('resume', true).'@example.test',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Public Test Owner',
            'publish_status' => $status,
        ]);
    }

    private function skill(Profile $profile, string $slug, string $name, string $category, string $evidence = 'verified'): void
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => $name,
            'name_ar' => $name,
            'category' => $category,
            'evidence_status' => $evidence,
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);
    }

    public function test_resume_focus_only_displays_published_owner_verified_skills(): void
    {
        $profile = $this->owner();
        $this->skill($profile, 'verified-database', 'Published Database Skill', 'Database & Reliability');
        $this->skill($profile, 'verified-delivery', 'Published Delivery Skill', 'Project Delivery');
        $this->skill($profile, 'unverified-database', 'Unreviewed Database Skill', 'Database & Reliability', 'owner_cv');

        $private = $this->owner('review');
        $this->skill($private, 'private-database', 'Private Database Skill', 'Database & Reliability');

        $this->get('/resume?track=database')->assertOk()
            ->assertSee('Published Database Skill')
            ->assertDontSee('Published Delivery Skill')
            ->assertDontSee('Unreviewed Database Skill')
            ->assertDontSee('Private Database Skill')
            ->assertSee('Focus changes the verified skills shown')
            ->assertSee('window.print()', false);

        $this->get('/resume?track=delivery')->assertOk()
            ->assertSee('Published Delivery Skill')
            ->assertDontSee('Published Database Skill');

        $this->get('/resume?track=unsupported')->assertOk()
            ->assertSee('Published Database Skill')
            ->assertSee('Published Delivery Skill')
            ->assertSee('value="all" selected', false);
        $this->get('/resume?track[]=database')->assertOk()
            ->assertSee('Published Database Skill')
            ->assertSee('Published Delivery Skill')
            ->assertSee('value="all" selected', false);
    }

    public function test_track_never_relabels_verified_employment_or_leaks_unapproved_positions(): void
    {
        $profile = $this->owner();
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Verified Organization',
            'role_en' => 'Verified Professional Role',
            'role_ar' => 'دور مهني موثق',
            'started_on' => '2021-01-01',
            'verified' => true,
            'publishable' => true,
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Confidential Organization',
            'role_en' => 'Unapproved Professional Role',
            'role_ar' => 'دور غير معتمد',
            'started_on' => '2022-01-01',
            'verified' => false,
            'publishable' => false,
        ]);

        $this->get('/resume?track=software')->assertOk()
            ->assertSee('Verified Professional Role')
            ->assertSee('Verified Organization')
            ->assertDontSee('Confidential Organization')
            ->assertDontSee('Unapproved Professional Role')
            ->assertSee('No skills in this focus have completed public evidence review.');
    }

    public function test_arabic_resume_preserves_focus_on_language_switch_and_hides_unpublished_owner(): void
    {
        $profile = $this->owner('review');
        $this->skill($profile, 'private-language', 'Not For Publication', 'Database & Reliability');

        $this->get('/resume?lang=ar&track=database')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مسار السيرة المهنية')
            ->assertSee('href="'.e(route('public.resume', ['lang' => 'en', 'track' => 'database'])).'"', false)
            ->assertDontSee('Not For Publication')
            ->assertDontSee('Public Test Owner');
    }
}

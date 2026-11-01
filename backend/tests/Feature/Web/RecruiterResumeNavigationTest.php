<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecruiterResumeNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_recruiter_focus_survives_language_switch_and_links_to_matching_resume(): void
    {
        $en = $this->get('/hire?lang=en&track=database')->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('View focused public resume')
            ->assertSee('href="'.e(route('public.resume', ['lang' => 'en', 'track' => 'database'])).'"', false)
            ->assertSee('href="'.e(route('public.hire', ['lang' => 'ar', 'track' => 'database'])).'"', false);

        $this->get('/hire?lang=ar&track=delivery')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('السيرة العامة حسب المجال')
            ->assertSee('href="'.e(route('public.resume', ['lang' => 'ar', 'track' => 'delivery'])).'"', false)
            ->assertSee('href="'.e(route('public.hire', ['lang' => 'en', 'track' => 'delivery'])).'"', false);
    }

    public function test_untrusted_track_values_cannot_propagate_into_cv_or_language_links(): void
    {
        foreach (['/hire?track=nonexistent', '/hire?track[]=database'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('href="'.e(route('public.resume', ['lang' => 'en', 'track' => 'all'])).'"', false)
                ->assertSee('href="'.e(route('public.hire', ['lang' => 'ar', 'track' => 'all'])).'"', false)
                ->assertDontSee('nonexistent');
        }
    }

    public function test_unpublished_profiles_stay_hidden_across_recruiter_to_resume_journey(): void
    {
        $user = User::create(['email' => 'unpublished-hire@example.test', 'status' => 'active']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id, 'public_name' => 'Private Recruiting Profile',
            'publish_status' => 'review',
        ]);
        $skill = Skill::create([
            'slug' => 'private-hire-skill', 'name_en' => 'Hidden Skill',
            'name_ar' => 'مهارة غير منشورة', 'category' => 'Database & Reliability',
            'evidence_status' => 'verified',
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);

        $this->get('/hire?track=database')->assertOk()
            ->assertDontSee('Hidden Skill')
            ->assertDontSee('Private Recruiting Profile')
            ->assertSee('View focused public resume');
        $this->get('/resume?track=database')->assertOk()
            ->assertSee('Public CV review is pending')
            ->assertDontSee('Hidden Skill');
    }

    public function test_recruiter_contact_link_opens_existing_transparently_disabled_contact_route(): void
    {
        config(['mail.contact_recipient' => null, 'mail.default' => 'log']);
        $this->get('/hire?lang=ar')->assertOk()
            ->assertSee('href="'.e(route('public.contact', ['lang' => 'ar'])).'"', false);
        $this->get('/contact?lang=ar')->assertOk()
            ->assertSee('استقبال الرسائل غير مفعل');
    }
}

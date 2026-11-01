<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExpertiseTrackNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function skill(Profile $profile, string $slug, string $category, string $name, bool $verified = true): void
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => $name,
            'name_ar' => $name,
            'category' => $category,
            'evidence_status' => $verified ? 'verified' : 'unverified',
        ]);
        DB::table('profile_skill')->insert([
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_verified_expertise_categories_open_real_bilingual_recruiter_filters(): void
    {
        $owner = User::create(['email' => 'track-owner@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Verified Track Owner',
            'publish_status' => 'published',
        ]);

        $categories = [
            ['database-skill', 'Database & Reliability', 'Confirmed Database Skill', 'database'],
            ['software-skill', 'Software & Integration', 'Confirmed Software Skill', 'software'],
            ['security-skill', 'Infrastructure & Security', 'Confirmed Security Skill', 'software'],
            ['delivery-skill', 'Project Delivery', 'Confirmed Delivery Skill', 'delivery'],
        ];
        foreach ($categories as [$slug, $category, $name]) {
            $this->skill($profile, $slug, $category, $name);
        }

        // A new, unrecognized canonical category may be displayed as verified
        // skill data, but must not be assigned an invented recruiter track.
        $this->skill($profile, 'new-category', 'Unmapped Specialty', 'Unmapped Verified Skill');
        $this->skill($profile, 'hidden-skill', 'Database & Reliability', 'Private Unreviewed Skill', false);

        foreach (['en', 'ar'] as $locale) {
            $html = $this->get(route('public.expertise', ['lang' => $locale]))
                ->assertOk()
                ->assertDontSee('Private Unreviewed Skill')
                ->assertSee('Unmapped Verified Skill')
                ->getContent();

            $this->assertSame(4, substr_count($html, 'class="professional-track-link"'));
            foreach ($categories as [, $category, $name, $track]) {
                $url = route('public.hire', ['lang' => $locale, 'track' => $track]);
                $this->assertStringContainsString('href="'.e($url).'"', $html);
                $this->assertStringContainsString($name, $html);
            }

            $this->get(route('public.hire', ['lang' => $locale, 'track' => 'database']))
                ->assertOk()
                ->assertSee('Confirmed Database Skill')
                ->assertDontSee('Confirmed Software Skill')
                ->assertDontSee('Confirmed Delivery Skill')
                ->assertDontSee('Private Unreviewed Skill');

            $this->get(route('public.hire', ['lang' => $locale, 'track' => 'software']))
                ->assertOk()
                ->assertSee('Confirmed Software Skill')
                ->assertSee('Confirmed Security Skill')
                ->assertDontSee('Confirmed Database Skill');

            $this->get(route('public.hire', ['lang' => $locale, 'track' => 'delivery']))
                ->assertOk()
                ->assertSee('Confirmed Delivery Skill')
                ->assertDontSee('Confirmed Database Skill');
        }
    }

    public function test_no_verified_profile_means_no_fake_specialty_filter_links(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->get(route('public.expertise', ['lang' => $locale]))
                ->assertOk()->getContent();
            $this->assertSame(0, substr_count($html, 'class="professional-track-link"'));
        }
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExpertiseCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $email, bool $owner, string $status = 'published'): Profile
    {
        $user = User::create(['email' => $email, 'status' => 'active']);
        if ($owner) {
            $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        }
        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Reviewed Owner',
            'publish_status' => $status,
        ]);
    }

    private function addSkill(Profile $profile, string $slug, string $category, bool $verified = true): void
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Claim '.$slug,
            'name_ar' => 'مهارة '.$slug,
            'category' => $category,
            'evidence_status' => $verified ? 'verified' : 'unverified',
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);
    }

    public function test_bilingual_expertise_filters_verified_skills_with_canonical_category_keys(): void
    {
        $owner = $this->profile('expertise-owner@example.test', true);
        $categories = [
            'database' => 'Database & Reliability',
            'integration' => 'Software & Integration',
            'security' => 'Infrastructure & Security',
            'delivery' => 'Project Delivery',
        ];
        foreach ($categories as $key => $category) {
            $this->addSkill($owner, $key.'-skill', $category);
        }
        $this->addSkill($owner, 'unverified-database', 'Database & Reliability', false);
        $other = $this->profile('expertise-reader@example.test', false);
        $this->addSkill($other, 'other-owner-skill', 'Database & Reliability');

        foreach (['en', 'ar'] as $locale) {
            $unfiltered = $this->get(route('public.expertise', ['lang' => $locale]))
                ->assertOk()->assertSee('role="search"', false);
            foreach (array_keys($categories) as $key) {
                $unfiltered->assertSee('Claim '.$key.'-skill');
            }
            foreach ($categories as $key => $category) {
                $response = $this->get(route('public.expertise', [
                    'lang' => $locale, 'category' => $key,
                ]))->assertOk()
                    ->assertSee('id="expertise-category"', false)
                    ->assertSee('name="category"', false)
                    ->assertSee('value="'.$key.'" selected', false)
                    ->assertSee('Claim '.$key.'-skill')
                    ->assertSee($locale === 'ar' ? 'إزالة التصفية' : 'Clear filter')
                    ->assertDontSee('Claim unverified-database')
                    ->assertDontSee('Claim other-owner-skill');
                foreach (array_keys($categories) as $otherKey) {
                    if ($otherKey !== $key) {
                        $response->assertDontSee('Claim '.$otherKey.'-skill');
                    }
                }
            }
        }
    }

    public function test_unknown_array_or_oversized_category_does_not_filter_or_disclose_private_skills(): void
    {
        $owner = $this->profile('expertise-safe@example.test', true);
        $this->addSkill($owner, 'public-database', 'Database & Reliability');
        $this->addSkill($owner, 'public-security', 'Infrastructure & Security');
        $this->addSkill($owner, 'private-database', 'Database & Reliability', false);

        foreach (['category[]=database', 'category=unknown', 'category='.str_repeat('x', 6000)] as $input) {
            $this->get('/expertise?lang=en&'.$input)->assertOk()
                ->assertSee('Claim public-database')
                ->assertSee('Claim public-security')
                ->assertDontSee('Claim private-database')
                ->assertDontSee('Clear filter');
        }
    }

    public function test_unpublished_or_inactive_owner_has_no_expertise_filter_data(): void
    {
        $profile = $this->profile('expertise-private@example.test', true, 'review');
        $this->addSkill($profile, 'private-owner', 'Software & Integration');
        $this->get('/expertise?category=integration&lang=en')->assertOk()
            ->assertDontSee('Claim private-owner')
            ->assertSee('No skills have been approved with verified evidence yet.');

        $profile->update(['publish_status' => 'published']);
        $profile->user->update(['status' => 'pending_activation']);
        $this->get('/expertise?category=integration&lang=ar')->assertOk()
            ->assertDontSee('مهارة private-owner')
            ->assertSee('لم تُعتمد مهارات للنشر بعد.');
    }
}

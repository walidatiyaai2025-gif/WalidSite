<?php

namespace Tests\Feature\Api;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicExpertiseTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $email, string $role = 'owner', string $status = 'active', string $publication = 'published'): Profile
    {
        $user = User::create(['email' => $email, 'status' => $status]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Fixture account (not a published claim)',
            'bio_en' => 'PRIVATE PROFILE BIO',
            'publish_status' => $publication,
        ]);
    }

    private function skill(Profile $profile, string $slug, string $evidence = 'verified', string $category = 'Database & Reliability'): Skill
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Engineering '.$slug,
            'name_ar' => 'هندسة '.$slug,
            'category' => $category,
            'evidence_status' => $evidence,
        ]);
        DB::table('profile_skill')->insert([
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);

        return $skill;
    }

    public function test_unconfigured_public_expertise_has_honest_empty_data(): void
    {
        $this->getJson('/api/v1/public/expertise')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_expertise_requires_active_published_owner_and_verified_linked_skills(): void
    {
        $nonOwner = $this->profile('non-owner@example.test', 'reader');
        $this->skill($nonOwner, 'not-owner');
        $inactiveOwner = $this->profile('inactive-owner@example.test', 'owner', 'disabled');
        $this->skill($inactiveOwner, 'inactive-owner');
        $draftOwner = $this->profile('draft-owner@example.test', 'owner', 'active', 'draft');
        $this->skill($draftOwner, 'draft-owner');

        $this->getJson('/api/v1/public/expertise')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertDontSee('not-owner')->assertDontSee('inactive-owner')
            ->assertDontSee('draft-owner');

        $owner = $this->profile('active-owner@example.test');
        $approved = $this->skill($owner, 'database-operations');
        $unverified = $this->skill($owner, 'unapproved-private-skill', 'unverified');

        $this->getJson('/api/v1/public/expertise')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $approved->slug)
            ->assertJsonPath('data.0.name', 'Engineering database-operations')
            ->assertJsonPath('data.0.category', 'Database & Reliability')
            ->assertJsonPath('meta.total', 1)
            ->assertDontSee($unverified->slug)
            ->assertDontSee('PRIVATE PROFILE BIO')
            ->assertDontSee('not-owner');

        // Evidence withdrawal affects the next API read immediately.
        $approved->update(['evidence_status' => 'unverified']);
        $this->getJson('/api/v1/public/expertise')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $approved->update(['evidence_status' => 'verified']);
        $owner->update(['publish_status' => 'draft']);
        $this->getJson('/api/v1/public/expertise')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_expertise_localized_category_filter_and_accurate_bounded_pagination(): void
    {
        $owner = $this->profile('paged-owner@example.test');
        for ($n = 1; $n <= 26; $n++) {
            $this->skill($owner, sprintf('approved-%02d', $n));
        }
        $this->skill($owner, 'software-approved', 'verified', 'Software & Integration');

        $this->getJson('/api/v1/public/expertise?lang=en&category=Database%20%26%20Reliability&page=1')
            ->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.name', 'Engineering approved-01')
            ->assertDontSee('software-approved');
        $this->getJson('/api/v1/public/expertise?lang=ar&category=Database%20%26%20Reliability&page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.slug', 'approved-26')
            ->assertJsonPath('data.0.name', 'هندسة approved-26');
        $this->getJson('/api/v1/public/expertise?category=Database%20%26%20Reliability&page=3')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/public/expertise?category=Unknown')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_locale_page_and_category_are_rejected(): void
    {
        foreach ([
            '/api/v1/public/expertise?lang=fr',
            '/api/v1/public/expertise?page=0',
            '/api/v1/public/expertise?page=10001',
            '/api/v1/public/expertise?page[]=1',
            '/api/v1/public/expertise?category[]=private',
            '/api/v1/public/expertise?category='.str_repeat('x', 81),
        ] as $path) {
            $this->getJson($path)->assertUnprocessable();
        }
    }
}

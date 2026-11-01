<?php

namespace Tests\Feature\Api;

use App\Models\CareerPosition;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicRecruiterTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $email, string $role = 'owner', string $userStatus = 'active', string $published = 'published'): Profile
    {
        $user = User::create(['email' => $email, 'status' => $userStatus]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Test portfolio owner',
            'bio_en' => 'PRIVATE_OWNER_BIO_NEVER_PUBLIC',
            'publish_status' => $published,
        ]);
    }

    private function skill(Profile $owner, string $slug, string $category, string $evidenceStatus = 'verified'): Skill
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Skill '.$slug,
            'name_ar' => 'مهارة '.$slug,
            'category' => $category,
            'evidence_status' => $evidenceStatus,
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $owner->id, 'skill_id' => $skill->id]);

        return $skill;
    }

    public function test_unconfigured_non_owner_disabled_and_draft_profiles_are_absent(): void
    {
        $this->getJson('/api/v1/public/recruiter')->assertOk()->assertJsonPath('data', null);
        $reader = $this->profile('reader@example.test', 'reader');
        $this->skill($reader, 'reader-private', 'Database & Reliability');
        $disabled = $this->profile('disabled@example.test', 'owner', 'disabled');
        $this->skill($disabled, 'disabled-private', 'Project Delivery');
        $draft = $this->profile('draft@example.test', 'owner', 'active', 'draft');
        $this->skill($draft, 'draft-private', 'Software & Integration');

        $this->getJson('/api/v1/public/recruiter?lang=ar&track=all')
            ->assertOk()->assertJsonPath('data', null)
            ->assertDontSee('reader-private')
            ->assertDontSee('disabled-private')
            ->assertDontSee('draft-private')
            ->assertDontSee('PRIVATE_OWNER_BIO_NEVER_PUBLIC');
    }

    public function test_bilingual_recruiter_tracks_only_offer_verified_owner_skills_with_live_evidence(): void
    {
        $owner = $this->profile('owner@example.test');
        $database = $this->skill($owner, 'database-reviewed', 'Database & Reliability');
        $this->skill($owner, 'software-reviewed', 'Software & Integration');
        $this->skill($owner, 'security-reviewed', 'Infrastructure & Security');
        $this->skill($owner, 'delivery-reviewed', 'Project Delivery');
        $this->skill($owner, 'private-software', 'Software & Integration', 'unverified');

        CareerPosition::create([
            'profile_id' => $owner->id,
            'org_name' => 'Verified example employer',
            'role_en' => 'Documented engineering role',
            'role_ar' => 'دور هندسي موثق',
            'started_on' => '2023-04-01',
            'verified' => true,
            'publishable' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $owner->id,
            'claim_en' => 'Public approved capability',
            'claim_ar' => 'خبرة مثبتة ومعتمدة',
            'evidence_url' => 'https://docs.example.org/review',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $owner->id,
            'claim_en' => 'PRIVATE INTERNAL CLAIM',
            'claim_ar' => 'معلومة غير عامة',
            'evidence_url' => 'https://service.internal/secret',
            'status' => 'approved',
            'is_public' => true,
        ]);

        $en = $this->getJson('/api/v1/public/recruiter?lang=en&track=database')
            ->assertOk()->assertJsonPath('data.name', 'Test portfolio owner')
            ->assertJsonPath('data.track', 'database')
            ->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.slug', 'database-reviewed')
            ->assertJsonPath('data.skills.0.name', 'Skill database-reviewed')
            ->assertJsonCount(1, 'data.positions')
            ->assertJsonPath('data.positions.0.role', 'Documented engineering role')
            ->assertJsonCount(1, 'data.claims')
            ->assertJsonPath('data.claims.0.text', 'Public approved capability')
            ->assertJsonPath('data.claims.0.evidence_url', 'https://docs.example.org/review')
            ->assertDontSee('PRIVATE')
            ->assertDontSee('service.internal')
            ->assertDontSee('private-software');
        $en->assertJsonPath('data.recruiter_url', route('public.hire', ['lang' => 'en', 'track' => 'database']))
            ->assertJsonPath('data.resume_url', route('public.resume', ['lang' => 'en', 'track' => 'database']))
            ->assertJsonPath('data.contact_url', route('public.contact', ['lang' => 'en']));

        $ar = $this->getJson('/api/v1/public/recruiter?lang=ar&track=software')
            ->assertOk()->assertJsonCount(2, 'data.skills')
            ->assertJsonCount(1, 'data.positions')
            ->assertJsonPath('data.positions.0.role', 'دور هندسي موثق')
            ->assertJsonPath('data.claims.0.text', 'خبرة مثبتة ومعتمدة')
            ->assertDontSee('private-software');
        $this->assertEqualsCanonicalizing(
            ['software-reviewed', 'security-reviewed'],
            array_column($ar->json('data.skills'), 'slug')
        );
        $this->getJson('/api/v1/public/recruiter?lang=ar&track=delivery')
            ->assertOk()->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.name', 'مهارة delivery-reviewed');

        // Revoking the proof removes the skill from the next public read.
        $database->update(['evidence_status' => 'unverified']);
        $this->getJson('/api/v1/public/recruiter?track=database')
            ->assertOk()->assertJsonCount(0, 'data.skills')
            ->assertJsonCount(1, 'data.positions');

        // Publication withdrawal makes even formerly approved CV fields private.
        $owner->update(['publish_status' => 'draft']);
        $this->getJson('/api/v1/public/recruiter')->assertOk()->assertJsonPath('data', null);
    }

    public function test_filter_validation_fails_closed_without_an_owner(): void
    {
        foreach ([
            '/api/v1/public/recruiter?lang=fr',
            '/api/v1/public/recruiter?lang[]=en',
            '/api/v1/public/recruiter?track=secret',
            '/api/v1/public/recruiter?track[]=database',
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
    }
}

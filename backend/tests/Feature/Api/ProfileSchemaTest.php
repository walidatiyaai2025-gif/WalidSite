<?php

namespace Tests\Feature\Api;

use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_profiles_and_conservative_publication_defaults(): void
    {
        $user = User::create(['email' => 'sample@example.test', 'status' => 'active']);
        $profile = Profile::create(['user_id' => $user->id, 'public_name' => 'Test Profile']);
        $this->assertTrue(Str::isUuid($profile->id));
        $this->assertSame('draft', $profile->publish_status);
        $this->assertSame($user->id, $profile->user->id);

        $position = CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Fixture Organization',
            'role_en' => 'Test Role',
            'role_ar' => 'دور تجريبي',
            'started_on' => '2024-01-01',
        ]);
        $certification = Certification::create([
            'profile_id' => $profile->id,
            'title' => 'Test Credential',
            'issuer' => 'Test Issuer',
        ]);
        $skill = Skill::create([
            'slug' => 'fixture-skill',
            'name_en' => 'Test Skill',
            'name_ar' => 'مهارة تجريبية',
            'category' => 'testing',
        ]);

        $this->assertTrue(Str::isUuid($position->id));
        $this->assertTrue(Str::isUuid($certification->id));
        $this->assertTrue(Str::isUuid($skill->id));
        $this->assertFalse($position->verified);
        $this->assertFalse($position->publishable);
        $this->assertFalse($certification->verified);
        $this->assertSame('unverified', $skill->evidence_status);
        $this->assertCount(0, CareerPosition::publiclyVerified()->get());
        $this->assertCount(0, Certification::publiclyVerified()->get());
        $this->assertCount(0, Skill::withVerifiedEvidence()->get());
        $this->assertSame(1, $profile->positions()->count());
        $this->assertSame(1, $profile->certifications()->count());
    }

    public function test_publishing_profile_alone_does_not_expose_unverified_claims(): void
    {
        $user = User::create(['email' => 'other@example.test', 'status' => 'active']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Test Profile',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Fixture Organization',
            'role_en' => 'Role',
            'role_ar' => 'دور',
            'started_on' => '2024-01-01',
            'publishable' => true,
        ]);
        Certification::create([
            'profile_id' => $profile->id,
            'title' => 'Test Credential',
            'issuer' => 'Test Issuer',
            'verified' => true,
        ]);
        $this->assertSame(0, CareerPosition::publiclyVerified()->count());
        $this->assertSame(0, Certification::publiclyVerified()->count());
    }

    public function test_removing_user_cascades_profile_and_claim_tables(): void
    {
        $user = User::create(['email' => 'delete@example.test', 'status' => 'active']);
        $profile = Profile::create(['user_id' => $user->id, 'public_name' => 'Test Profile']);
        $id = $profile->id;
        $user->delete();
        $this->assertDatabaseMissing('profiles', ['id' => $id]);
    }
}

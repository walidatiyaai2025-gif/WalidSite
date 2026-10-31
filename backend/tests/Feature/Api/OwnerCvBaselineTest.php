<?php

namespace Tests\Feature\Api;

use App\Models\Certification;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\OwnerCvBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnerCvBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_provide_uuid_profile_schema_and_reversible_relationships(): void
    {
        foreach (['profiles', 'career_positions', 'skills', 'profile_skill', 'certifications', 'professional_claims'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumn('career_positions', 'source_refs_json'));
        $this->assertTrue(Schema::hasColumn('certifications', 'verified'));
        $this->assertTrue(Schema::hasColumn('profiles', 'publish_status'));
    }

    public function test_owner_cv_seeds_full_safe_baseline_without_credentials_or_unverified_public_claims(): void
    {
        $this->seed(OwnerCvBaselineSeeder::class);

        $this->assertDatabaseCount('profiles', 1);
        $this->assertDatabaseCount('career_positions', 4);
        $this->assertDatabaseCount('certifications', 5);
        $this->assertDatabaseCount('professional_claims', 1);
        $this->assertGreaterThan(20, DB::table('skills')->count());
        $this->assertEquals(DB::table('skills')->count(), DB::table('profile_skill')->count());

        $user = User::firstOrFail();
        $this->assertSame('pending_activation', $user->status);
        $this->assertNull($user->password_hash);
        $this->assertDatabaseCount('role_user', 0);
        $this->assertFalse($user->canAccessAdmin());
        $profile = Profile::firstOrFail();
        $this->assertSame('Waleed Atiya Ata Atiya', $profile->public_name);
        $this->assertSame('review', $profile->publish_status);
        $this->assertEquals(0, DB::table('career_positions')->where('verified', true)->count());
        $this->assertEquals(0, DB::table('career_positions')->where('publishable', true)->count());
        $this->assertEquals(0, DB::table('professional_claims')->where('is_public', true)->count());
        $this->assertTrue(Certification::query()->where('verified', true)->doesntExist());
        $this->assertEquals(4, DB::table('career_positions')->where('date_precision', 'year')->count());
    }

    public function test_reseed_is_idempotent_and_does_not_overwrite_manually_edited_profile(): void
    {
        $this->seed(OwnerCvBaselineSeeder::class);
        $before = [
            DB::table('career_positions')->count(),
            DB::table('skills')->count(),
            DB::table('profile_skill')->count(),
            DB::table('certifications')->count(),
            DB::table('professional_claims')->count(),
        ];

        $profile = Profile::firstOrFail();
        $profile->update(['bio_en' => 'Owner reviewed text']);
        $this->seed(OwnerCvBaselineSeeder::class);

        $this->assertSame('Owner reviewed text', $profile->fresh()->bio_en);
        $this->assertEquals($before, [
            DB::table('career_positions')->count(),
            DB::table('skills')->count(),
            DB::table('profile_skill')->count(),
            DB::table('certifications')->count(),
            DB::table('professional_claims')->count(),
        ]);
        $this->assertDatabaseCount('users', 1);
    }
}

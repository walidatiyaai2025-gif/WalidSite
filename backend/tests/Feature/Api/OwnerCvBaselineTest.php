<?php

namespace Tests\Feature\Api;

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

    public function test_schema_extends_canonical_t003_tables_without_duplicates(): void
    {
        $this->assertTrue(Schema::hasTable('profiles'));
        $this->assertTrue(Schema::hasTable('profile_skill'));
        $this->assertTrue(Schema::hasTable('professional_claims'));
        $this->assertTrue(Schema::hasColumn('career_positions', 'date_precision'));
        $this->assertTrue(Schema::hasColumn('certifications', 'source_refs_json'));
    }

    public function test_source_cv_populates_all_safe_basics_with_no_owner_credentials(): void
    {
        $this->seed(OwnerCvBaselineSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('profiles', 1);
        $this->assertDatabaseCount('career_positions', 4);
        $this->assertDatabaseCount('certifications', 5);
        $this->assertDatabaseCount('skills', 25);
        $this->assertDatabaseCount('profile_skill', 25);
        $this->assertDatabaseCount('professional_claims', 1);

        $user = User::firstOrFail();
        $this->assertSame('pending_activation', $user->status);
        $this->assertNull($user->password_hash);
        $this->assertFalse($user->canAccessAdmin());
        $this->assertDatabaseCount('role_user', 0);
        $profile = Profile::firstOrFail();
        $this->assertSame('Waleed Atiya Ata Atiya', $profile->public_name);
        $this->assertSame('review', $profile->publish_status);
        $this->assertSame(0, DB::table('career_positions')->where('publishable', true)->count());
        $this->assertSame(0, DB::table('career_positions')->where('verified', true)->count());
        $this->assertSame(0, DB::table('certifications')->where('verified', true)->count());
        $this->assertSame(0, DB::table('professional_claims')->where('is_public', true)->count());
        $this->assertSame(4, DB::table('career_positions')->where('date_precision', 'year')->count());
        $this->assertNotNull(DB::table('certifications')->first()->source_refs_json);
    }

    public function test_second_seed_preserves_edits_and_does_not_duplicate_claims_or_skills(): void
    {
        $this->seed(OwnerCvBaselineSeeder::class);
        $profile = Profile::firstOrFail();
        $profile->update(['bio_en' => 'Revised by owner']);
        $counts = ['career_positions', 'skills', 'profile_skill', 'certifications', 'professional_claims'];
        $before = array_map(fn ($table) => DB::table($table)->count(), $counts);
        $this->seed(OwnerCvBaselineSeeder::class);
        $this->assertSame('Revised by owner', $profile->fresh()->bio_en);
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(), $counts));
        $this->assertDatabaseCount('users', 1);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Domain\Profile\OwnerCvPublisher;
use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use App\Support\Deployment\SiteInstaller;
use App\Support\Deployment\SiteUpdater;
use Database\Seeders\OwnerCvBaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class OwnerCvPublicationTest extends TestCase
{
    use RefreshDatabase;

    private function seedOwner(): User
    {
        $this->seed(OwnerCvBaselineSeeder::class);

        return User::query()->firstOrFail();
    }

    private function activate(User $owner): User
    {
        $owner->forceFill(['status' => 'active'])->save();
        $owner->roles()->syncWithoutDetaching([
            Role::firstOrCreate(['name' => 'owner'])->id,
        ]);

        return $owner->fresh();
    }

    public function test_seed_remains_private_and_publication_rejects_unactivated_owner(): void
    {
        $owner = $this->seedOwner();

        $this->assertSame('pending_activation', $owner->status);
        $this->assertSame('review', Profile::query()->firstOrFail()->publish_status);
        $this->assertSame(0, CareerPosition::query()->where('publishable', true)->count());
        $this->assertSame(0, CareerPosition::query()->where('verified', true)->count());
        $this->assertSame(0, Skill::query()->where('evidence_status', 'verified')->count());
        $this->assertSame(0, DB::table('certifications')->where('verified', true)->count());
        $this->assertSame(0, DB::table('professional_claims')->where('is_public', true)->count());

        $this->expectException(RuntimeException::class);
        app(OwnerCvPublisher::class)->publishAfterVerifiedActivation($owner);
    }

    public function test_canonical_activated_owner_publishes_only_source_backed_career_and_skills(): void
    {
        $owner = $this->activate($this->seedOwner());

        $result = app(OwnerCvPublisher::class)->publishAfterVerifiedActivation($owner);

        $this->assertSame([
            'career_published' => 4,
            'skills_published' => 25,
            'career_skipped' => 0,
            'skills_skipped' => 0,
        ], $result);
        $this->assertSame('published', Profile::query()->firstOrFail()->publish_status);
        $this->assertSame(4, CareerPosition::query()->publiclyVerified()->count());
        $this->assertSame(25, Skill::query()->withVerifiedEvidence()->count());

        // Owner-supplied certifications and the summary claim still need their
        // independent evidence/publication gates.
        $this->assertSame(0, DB::table('certifications')->where('verified', true)->count());
        $this->assertSame(0, DB::table('professional_claims')->where('is_public', true)->count());

        $this->getJson(route('api.public.profile', ['lang' => 'en']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Waleed Atiya Ata Atiya')
            ->assertJsonCount(0, 'data.claims');

        $this->getJson(route('api.public.expertise', ['lang' => 'en']))
            ->assertOk()
            ->assertJsonPath('meta.total', 25);

        $this->getJson(route('api.public.resume', ['lang' => 'en', 'track' => 'all']))
            ->assertOk()
            ->assertJsonCount(4, 'data.positions')
            ->assertJsonCount(25, 'data.skills')
            ->assertJsonCount(0, 'data.certifications')
            ->assertJsonCount(0, 'data.claims');
    }

    public function test_edited_or_unrelated_rows_fail_closed_without_blocking_exact_source_rows(): void
    {
        $owner = $this->seedOwner();
        $profile = Profile::query()->firstOrFail();

        $edited = CareerPosition::query()
            ->where('org_name', 'Amiri Diwan')->firstOrFail();
        $edited->forceFill(['role_en' => 'Owner edited role'])->save();

        $unrelatedCareer = CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Unrelated Organisation',
            'role_en' => 'Unrelated',
            'role_ar' => 'غير مرتبط',
            'started_on' => '2020-01-01',
            'ended_on' => '2020-12-31',
            'publishable' => false,
            'verified' => false,
        ]);
        $unrelatedSkill = Skill::create([
            'slug' => 'unrelated-private-skill-'.Str::lower(Str::random(8)),
            'name_en' => 'Unrelated private skill',
            'name_ar' => 'مهارة خاصة غير مرتبطة',
            'category' => 'Private',
            'evidence_status' => 'owner_cv',
        ]);
        DB::table('profile_skill')->insert([
            'profile_id' => $profile->id,
            'skill_id' => $unrelatedSkill->id,
        ]);

        $result = app(OwnerCvPublisher::class)
            ->publishAfterVerifiedActivation($this->activate($owner));

        $this->assertSame(3, $result['career_published']);
        $this->assertSame(1, $result['career_skipped']);
        $this->assertFalse((bool) $edited->fresh()->verified);
        $this->assertFalse((bool) $edited->fresh()->publishable);
        $this->assertFalse((bool) $unrelatedCareer->fresh()->verified);
        $this->assertFalse((bool) $unrelatedCareer->fresh()->publishable);
        $this->assertSame('owner_cv', $unrelatedSkill->fresh()->evidence_status);
        $this->assertSame(3, CareerPosition::query()->publiclyVerified()->count());
        $this->assertSame(25, Skill::query()->withVerifiedEvidence()->count());
    }

    public function test_publication_is_idempotent_and_does_not_create_records(): void
    {
        $owner = $this->activate($this->seedOwner());
        $before = [
            'users' => DB::table('users')->count(),
            'profiles' => DB::table('profiles')->count(),
            'career_positions' => DB::table('career_positions')->count(),
            'skills' => DB::table('skills')->count(),
            'profile_skill' => DB::table('profile_skill')->count(),
            'certifications' => DB::table('certifications')->count(),
            'professional_claims' => DB::table('professional_claims')->count(),
        ];

        $publisher = app(OwnerCvPublisher::class);
        $first = $publisher->publishAfterVerifiedActivation($owner);
        $second = $publisher->publishAfterVerifiedActivation($owner->fresh());

        $this->assertSame($first, $second);
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_real_site_installer_activates_and_publishes_canonical_public_cv_only(): void
    {
        $lock = storage_path('app/install-565-'.bin2hex(random_bytes(6)).'.lock');
        $previous = [
            'enabled' => config('site.setup_enabled'),
            'token' => config('site.setup_token'),
            'database_ready' => config('site.setup_database_ready'),
            'lock' => config('site.install_lock_path'),
        ];
        config([
            'site.setup_enabled' => true,
            'site.setup_token' => str_repeat('i', 40),
            'site.setup_database_ready' => false,
            'site.install_lock_path' => $lock,
        ]);

        try {
            $this->assertTrue(
                app(SiteInstaller::class)->complete(
                    'A-strong-owner-password-565!',
                    app(SiteUpdater::class)
                )
            );

            $owner = User::query()->firstOrFail();
            $this->assertSame('active', $owner->status);
            $this->assertTrue($owner->hasRole('owner'));
            $this->assertNotNull($owner->password_hash);
            $this->assertSame('published', Profile::query()->firstOrFail()->publish_status);
            $this->assertSame(4, CareerPosition::query()->publiclyVerified()->count());
            $this->assertSame(25, Skill::query()->withVerifiedEvidence()->count());
            $this->assertSame(0, DB::table('certifications')->where('verified', true)->count());
            $this->assertSame(0, DB::table('professional_claims')->where('is_public', true)->count());
            $this->assertFileExists($lock);
        } finally {
            config([
                'site.setup_enabled' => $previous['enabled'],
                'site.setup_token' => $previous['token'],
                'site.setup_database_ready' => $previous['database_ready'],
                'site.install_lock_path' => $previous['lock'],
            ]);
            @unlink($lock);
        }
    }
}

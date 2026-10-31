<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicProfessionalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function publishedOwner(): Profile
    {
        $user = User::create([
            'email' => Str::random(12).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $role = Role::firstOrCreate(['name' => 'owner']);
        $user->roles()->attach($role->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Approved Public Profile',
            'bio_en' => 'Reviewed professional biography',
            'bio_ar' => 'سيرة مهنية معتمدة',
            'publish_status' => 'published',
        ]);
    }

    public function test_routes_have_real_accessible_bilingual_destinations(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="'.route('public.about', ['lang' => 'en']).'"', false)
            ->assertSee('href="'.route('public.expertise', ['lang' => 'en']).'"', false)
            ->assertSee('href="'.route('public.hire', ['lang' => 'en']).'"', false);

        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('نبذة عني')
            ->assertSee('href="'.route('public.about', ['lang' => 'en']).'"', false);

        $this->get('/expertise')->assertOk()->assertSee('role="search"', false);
        $this->get('/hire')->assertOk()->assertSee('Role track')
            ->assertSee('name="track"', false);
    }

    public function test_source_seed_is_never_automatically_exposed_publicly(): void
    {
        $this->artisan('db:seed', ['--class' => 'OwnerCvBaselineSeeder'])->assertSuccessful();
        $this->get('/about')->assertOk()
            ->assertSee('Professional information is under review')
            ->assertDontSee('Amiri Diwan')
            ->assertDontSee('MCSA')
            ->assertDontSee('lido825@hotmail.com');
        $this->get('/expertise')->assertOk()->assertDontSee('SQL Server AlwaysOn Clustering');
        $this->get('/hire')->assertOk()->assertDontSee('SQL Server Administration');
    }

    public function test_only_owner_with_published_profile_and_verified_role_items_is_visible(): void
    {
        $profile = $this->publishedOwner();
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Approved Example Organization',
            'role_en' => 'Reviewed DBA',
            'role_ar' => 'مسؤول قواعد بيانات معتمد',
            'started_on' => '2024-01-01',
            'date_precision' => 'year',
            'verified' => true,
            'publishable' => true,
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Restricted Internal Organization',
            'role_en' => 'Unreviewed',
            'role_ar' => 'غير موثق',
            'started_on' => '2023-01-01',
            'verified' => false,
            'publishable' => false,
        ]);
        Certification::create([
            'profile_id' => $profile->id,
            'title' => 'Credential With Verified Evidence',
            'verified' => true,
            'evidence_asset_id' => (string) Str::uuid(),
        ]);
        Certification::create([
            'profile_id' => $profile->id,
            'title' => 'Unverified Certification',
            'verified' => false,
        ]);

        $this->get('/about')->assertOk()
            ->assertSee('Approved Public Profile')
            ->assertSee('Reviewed professional biography')
            ->assertSee('Approved Example Organization')
            ->assertSee('Credential With Verified Evidence')
            ->assertDontSee('Restricted Internal Organization')
            ->assertDontSee('Unverified Certification')
            ->assertDontSee('2024-01-01');

        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('سيرة مهنية معتمدة')
            ->assertSee('مسؤول قواعد بيانات معتمد');
    }

    public function test_skills_require_both_published_owner_and_verified_evidence(): void
    {
        $profile = $this->publishedOwner();
        $verified = Skill::create([
            'slug' => 'verified-database-fixture',
            'name_en' => 'Verified database skill',
            'name_ar' => 'مهارة قواعد بيانات مثبتة',
            'category' => 'Database & Reliability',
            'evidence_status' => 'verified',
        ]);
        $unverified = Skill::create([
            'slug' => 'unverified-fixture',
            'name_en' => 'Hidden skill',
            'name_ar' => 'مهارة مخفية',
            'category' => 'Software & Integration',
            'evidence_status' => 'owner_cv',
        ]);
        DB::table('profile_skill')->insert([
            ['profile_id' => $profile->id, 'skill_id' => $verified->id],
            ['profile_id' => $profile->id, 'skill_id' => $unverified->id],
        ]);

        $this->get('/expertise')->assertOk()
            ->assertSee('Verified database skill')->assertDontSee('Hidden skill');
        $this->get('/expertise?category=Software%20%26%20Integration')->assertOk()
            ->assertSee('No verified skills match')->assertDontSee('Verified database skill');
        $this->get('/expertise?lang=ar')->assertOk()
            ->assertSee('مهارة قواعد بيانات مثبتة');

        $this->get('/hire?track=database')->assertOk()
            ->assertSee('Verified database skill')->assertDontSee('Hidden skill');
        $this->get('/hire?track=software')->assertOk()
            ->assertSee('Approved professional evidence is not yet available');
        $this->get('/hire?track=bogus')->assertOk()
            ->assertSee('Database &amp; Reliability', false)
            ->assertDontSee('Hidden skill');
    }

    public function test_non_owner_published_profile_never_leaks_publicly(): void
    {
        $user = User::create(['email' => 'reader@example.test', 'status' => 'active']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Non owner profile',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Confidential Example',
            'role_en' => 'Role',
            'role_ar' => 'دور',
            'started_on' => '2024-01-01',
            'publishable' => true,
            'verified' => true,
        ]);
        $this->get('/about')->assertOk()
            ->assertSee('Professional information is under review')
            ->assertDontSee('Confidential Example')
            ->assertDontSee('Non owner profile');
    }
}

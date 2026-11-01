<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicResumeTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfile(
        string $email,
        string $role = 'owner',
        string $status = 'active',
        string $publication = 'published'
    ): Profile {
        $user = User::create(['email' => $email, 'status' => $status]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Verified Test Owner',
            'bio_en' => 'PRIVATE_OWNER_BIO_NOT_PUBLISHED',
            'publish_status' => $publication,
        ]);
    }

    private function addSkill(Profile $profile, string $slug, string $category, string $status = 'verified'): Skill
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Verified skill '.$slug,
            'name_ar' => 'مهارة موثقة '.$slug,
            'category' => $category,
            'evidence_status' => $status,
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);

        return $skill;
    }

    private function addPosition(Profile $profile, bool $verified = true, bool $publishable = true): CareerPosition
    {
        return CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Approved Employer',
            'role_en' => 'Verified Engineer',
            'role_ar' => 'مهندس معتمد',
            'started_on' => $verified ? '2022-01-01' : '2021-01-01',
            'ended_on' => null,
            'verified' => $verified,
            'publishable' => $publishable,
        ]);
    }

    private function addCertificate(Profile $profile, string $title, bool $usable): array
    {
        $asset = Asset::create([
            'asset_key' => 'proof-'.strtolower(str_replace(' ', '-', $title)),
            'mime_type' => 'image/png',
            'storage_key' => 'private/proof-material.png',
            'sha256' => str_repeat('a', 64),
            'origin' => 'owner-supplied',
            'rights_status' => $usable ? 'cleared' : 'revoked',
            'publishable' => $usable,
        ]);
        $certificate = Certification::create([
            'profile_id' => $profile->id,
            'title' => $title,
            'issuer' => 'Reviewed issuer',
            'issued_on' => '2023-05-10',
            'verified' => true,
            'evidence_asset_id' => $asset->id,
        ]);

        return [$certificate, $asset];
    }

    public function test_missing_or_unpublished_owner_returns_no_private_resume(): void
    {
        $this->getJson('/api/v1/public/resume')->assertOk()->assertJsonPath('data', null);
        $other = $this->makeProfile('not-owner@example.test', 'reader');
        $this->addPosition($other);
        $this->addSkill($other, 'other-private', 'Database & Reliability');

        $inactive = $this->makeProfile('inactive-owner@example.test', 'owner', 'disabled');
        $this->addSkill($inactive, 'inactive-private', 'Database & Reliability');
        $draft = $this->makeProfile('draft-owner@example.test', 'owner', 'active', 'draft');
        $this->addSkill($draft, 'draft-private', 'Database & Reliability');

        $this->getJson('/api/v1/public/resume?lang=ar')->assertOk()
            ->assertJsonPath('data', null)
            ->assertDontSee('Approved Employer')
            ->assertDontSee('other-private')
            ->assertDontSee('inactive-private')
            ->assertDontSee('draft-private')
            ->assertDontSee('PRIVATE_OWNER_BIO_NOT_PUBLISHED');
    }

    public function test_public_resume_hides_unreviewed_claims_skills_positions_and_revoked_certificates(): void
    {
        $owner = $this->makeProfile('published-owner@example.test');
        $this->addPosition($owner);
        $this->addPosition($owner, false);
        $this->addSkill($owner, 'approved-database', 'Database & Reliability');
        $this->addSkill($owner, 'unapproved-internal', 'Infrastructure & Security', 'unverified');
        [$certificate, $asset] = $this->addCertificate($owner, 'Approved proof-backed credential', true);
        $this->addCertificate($owner, 'Revoked private credential', false);

        ProfessionalClaim::create([
            'profile_id' => $owner->id,
            'claim_en' => 'Public approved engineering claim',
            'claim_ar' => 'خبرة هندسية موثقة',
            'evidence_url' => 'https://docs.example.test/verified',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $owner->id,
            'claim_en' => 'PRIVATE unapproved claim',
            'claim_ar' => 'ادعاء غير معتمد',
            'evidence_url' => 'https://docs.example.test/pending',
            'status' => 'draft',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $owner->id,
            'claim_en' => 'PRIVATE internal proof claim',
            'claim_ar' => 'دليل خاص',
            'evidence_url' => 'https://node.internal/private',
            'status' => 'approved',
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/v1/public/resume?lang=en&track=all')
            ->assertOk()
            ->assertJsonPath('data.name', 'Verified Test Owner')
            ->assertJsonPath('data.track', 'all')
            ->assertJsonCount(1, 'data.positions')
            ->assertJsonPath('data.positions.0.organization', 'Approved Employer')
            ->assertJsonPath('data.positions.0.role', 'Verified Engineer')
            ->assertJsonPath('data.positions.0.started_on', '2022-01-01')
            ->assertJsonPath('data.positions.0.ended_on', null)
            ->assertJsonCount(1, 'data.certifications')
            ->assertJsonPath('data.certifications.0.title', 'Approved proof-backed credential')
            ->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.slug', 'approved-database')
            ->assertJsonCount(1, 'data.claims')
            ->assertJsonPath('data.claims.0.text', 'Public approved engineering claim')
            ->assertDontSee('PRIVATE')
            ->assertDontSee('unapproved-internal')
            ->assertDontSee('Revoked private credential')
            ->assertDontSee('node.internal')
            ->assertDontSee('private/proof-material.png');

        $this->assertStringContainsString('/resume?', $response->json('data.resume_url'));
        $this->assertStringContainsString('/contact?', $response->json('data.contact_url'));

        // A previously verified credential cannot remain in the public CV
        // after its proof asset is revoked, even before any manual republish.
        $asset->update(['rights_status' => 'revoked', 'publishable' => false]);
        $this->getJson('/api/v1/public/resume')->assertOk()
            ->assertJsonCount(0, 'data.certifications')
            ->assertDontSee($certificate->title);

        $owner->update(['publish_status' => 'draft']);
        $this->getJson('/api/v1/public/resume')->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_bilingual_resume_tracks_filter_skills_but_preserve_verified_career_history(): void
    {
        $owner = $this->makeProfile('localized-owner@example.test');
        $this->addPosition($owner);
        $this->addSkill($owner, 'database', 'Database & Reliability');
        $this->addSkill($owner, 'software', 'Software & Integration');
        $this->addSkill($owner, 'security', 'Infrastructure & Security');
        $this->addSkill($owner, 'delivery', 'Project Delivery');

        $arabic = $this->getJson('/api/v1/public/resume?lang=ar&track=software')
            ->assertOk()
            ->assertJsonPath('data.track', 'software')
            ->assertJsonCount(2, 'data.skills')
            ->assertJsonCount(1, 'data.positions')
            ->assertJsonPath('data.positions.0.role', 'مهندس معتمد')
            ->assertDontSee('مهارة موثقة database')
            ->assertDontSee('مهارة موثقة delivery');

        $this->assertEqualsCanonicalizing(
            ['security', 'software'],
            array_column($arabic->json('data.skills'), 'slug')
        );
        $this->getJson('/api/v1/public/resume?lang=en&track=database')
            ->assertOk()->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.slug', 'database')
            ->assertJsonPath('data.skills.0.name', 'Verified skill database')
            ->assertJsonCount(1, 'data.positions');
        $this->getJson('/api/v1/public/resume?lang=ar&track=delivery')
            ->assertOk()->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.name', 'مهارة موثقة delivery');
    }

    public function test_invalid_public_resume_track_and_locale_return_validation_errors(): void
    {
        foreach ([
            '/api/v1/public/resume?lang=fr',
            '/api/v1/public/resume?lang[]=ar',
            '/api/v1/public/resume?track=unknown',
            '/api/v1/public/resume?track[]=database',
        ] as $path) {
            $this->getJson($path)->assertUnprocessable();
        }
    }
    public function test_empty_public_response_must_not_be_cached(): void
    {
        $response = $this->getJson('/api/v1/public/resume')->assertOk()->assertJsonPath('data', null);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}

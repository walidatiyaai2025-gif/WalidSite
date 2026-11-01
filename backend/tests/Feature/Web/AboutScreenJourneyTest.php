<?php

namespace Tests\Feature\Web;

use App\Models\Asset;
use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AboutScreenJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $status = 'active', string $publish = 'published'): Profile
    {
        $user = User::create([
            'email' => Str::random(12).'@example.test',
            'status' => $status,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Approved Owner',
            'bio_en' => 'PRIVATE-INTERNAL-EMPLOYER-DETAILS',
            'bio_ar' => 'سيرة ذاتية سرية وغير معتمدة',
            'publish_status' => $publish,
        ]);
    }

    private function position(Profile $profile, string $role, bool $verified = true,
        bool $publishable = true, string $date = '2024-01-01'): void
    {
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Reviewed '.$role.' organization',
            'role_en' => $role,
            'role_ar' => 'وظيفة '.$role,
            'started_on' => $date,
            'verified' => $verified,
            'publishable' => $publishable,
        ]);
    }

    private function evidenceAsset(): Asset
    {
        return Asset::create([
            'asset_key' => 'credential-evidence-'.Str::random(10),
            'mime_type' => 'image/png',
            'storage_key' => 'verified/credential.png',
            'sha256' => str_repeat('a', 64),
            'origin' => 'owner-supplied',
            'rights_status' => 'cleared',
            'publishable' => true,
        ]);
    }

    public function test_bilingual_empty_about_is_a_functional_accessible_screen_with_real_destinations(): void
    {
        Http::fake();
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/about?lang='.$locale)->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('id="about-screen-title"', false)
                ->assertSee('id="about-career"', false)
                ->assertSee('id="about-certifications"', false)
                ->assertSee('id="about-evidence"', false)
                ->assertSee('href="#about-career"', false)
                ->assertSee('href="#about-certifications"', false)
                ->assertSee('href="#about-evidence"', false)
                ->assertSee('src="'.route('public.owner-portrait').'"', false)
                ->assertSee('href="'.route('public.resume', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.projects', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.contact', ['lang' => $locale]).'"', false)
                ->assertDontSee('PRIVATE-INTERNAL-EMPLOYER-DETAILS')
                ->assertDontSee('20+ Years')
                ->assertDontSee('PMP®');
            if ($locale === 'ar') {
                $page->assertSee('لا توجد فترات مهنية تم التحقق منها')
                    ->assertSee('لا توجد شهادات اجتازت التحقق')
                    ->assertSee('لا توجد عبارات مهنية إضافية');
            } else {
                $page->assertSee('No career periods have completed verification')
                    ->assertSee('No credentials have cleared evidence')
                    ->assertSee('No additional source-backed professional statements');
            }

            $this->get('/resume?lang='.$locale)->assertOk()
                ->assertSee($locale === 'ar' ? 'طباعة أو حفظ PDF' : 'Print or save PDF');
        }
        $this->get(route('public.owner-portrait'))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        Http::assertNothingSent();
    }

    public function test_owner_only_verified_timeline_certifications_and_claims_with_real_proof_links(): void
    {
        Http::fake();
        $profile = $this->owner();
        $this->position($profile, 'Older Published Role', date: '2021-01-01');
        $this->position($profile, 'New Published Role', date: '2025-01-01');
        $this->position($profile, 'PRIVATE-UNVERIFIED-ROLE', verified: false);
        $this->position($profile, 'PRIVATE-UNPUBLISHED-ROLE', publishable: false);
        $evidence = $this->evidenceAsset();
        Certification::create([
            'profile_id' => $profile->id,
            'title' => 'Reviewed issued certificate',
            'issuer' => 'Verified public issuer',
            'issued_on' => '2023-07-20',
            'verified' => true,
            'evidence_asset_id' => $evidence->id,
        ]);
        Certification::create([
            'profile_id' => $profile->id,
            'title' => 'PRIVATE-NO-EVIDENCE-CREDENTIAL',
            'verified' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Evidence-backed engineering claim',
            'claim_ar' => 'ادعاء هندسي تدعمه الأدلة',
            'evidence_url' => 'https://docs.example.org/reviewed-career',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'PRIVATE-UNSAFE-CLAIM',
            'claim_ar' => 'ادعاء غير آمن',
            'evidence_url' => 'https://127.0.0.1/private',
            'status' => 'approved',
            'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'PRIVATE-PENDING-CLAIM',
            'claim_ar' => 'ادعاء قيد الاعتماد',
            'evidence_url' => 'https://docs.example.org/unreviewed',
            'status' => 'pending',
            'is_public' => true,
        ]);

        foreach (['en', 'ar'] as $locale) {
            $html = $this->get('/about?lang='.$locale)->assertOk()
                ->assertSee('Reviewed New Published Role organization')
                ->assertSee('Reviewed Older Published Role organization')
                ->assertSee('Reviewed issued certificate')
                ->assertSee('Verified public issuer')
                ->assertSee('datetime="2023-07-20"', false)
                ->assertSee('href="https://docs.example.org/reviewed-career"', false)
                ->assertSee('target="_blank" rel="noopener noreferrer"', false)
                ->assertDontSee('PRIVATE-UNVERIFIED-ROLE')
                ->assertDontSee('PRIVATE-UNPUBLISHED-ROLE')
                ->assertDontSee('PRIVATE-NO-EVIDENCE-CREDENTIAL')
                ->assertDontSee('PRIVATE-UNSAFE-CLAIM')
                ->assertDontSee('PRIVATE-PENDING-CLAIM')
                ->assertDontSee('PRIVATE-INTERNAL-EMPLOYER-DETAILS')
                ->assertDontSee('https://127.0.0.1')->getContent();

            $this->assertLessThan(
                strpos($html, 'Reviewed Older Published Role organization'),
                strpos($html, 'Reviewed New Published Role organization'),
                'Verified timeline must show newest role first.'
            );
            $this->assertStringContainsString(
                $locale === 'ar' ? 'ادعاء هندسي تدعمه الأدلة' : 'Evidence-backed engineering claim',
                $html
            );
            $this->assertStringContainsString(
                $locale === 'ar' ? 'وظيفة New Published Role' : 'New Published Role',
                $html
            );
        }
        Http::assertNothingSent();
    }

    public function test_disabling_owner_or_revoking_credential_rights_removes_public_material_immediately(): void
    {
        Http::fake();
        $profile = $this->owner();
        $this->position($profile, 'Time-limited role');
        $evidence = $this->evidenceAsset();
        Certification::create([
            'profile_id' => $profile->id,
            'title' => 'Revocable credential',
            'verified' => true,
            'evidence_asset_id' => $evidence->id,
        ]);
        $this->get('/about')->assertOk()
            ->assertSee('Revocable credential')
            ->assertSee('Time-limited role');

        $evidence->update(['rights_status' => 'revoked', 'publishable' => false]);
        $this->get('/about')->assertOk()
            ->assertDontSee('Revocable credential')
            ->assertSee('Time-limited role');
        $profile->user->update(['status' => 'inactive']);
        $this->get('/about')->assertOk()
            ->assertDontSee('Revocable credential')
            ->assertDontSee('Time-limited role');

        $profile->user->update(['status' => 'active']);
        $profile->update(['publish_status' => 'draft']);
        $this->get('/about?lang=ar')->assertOk()
            ->assertDontSee('Time-limited role')
            ->assertDontSee('Revocable credential');
        Http::assertNothingSent();
    }

    public function test_non_owner_profile_never_supplies_the_public_timeline(): void
    {
        Http::fake();
        $reader = User::create(['email' => 'nonowner-about@example.test', 'status' => 'active']);
        $profile = Profile::create([
            'user_id' => $reader->id,
            'public_name' => 'PRIVATE-NONOWNER-NAME',
            'publish_status' => 'published',
        ]);
        $this->position($profile, 'PRIVATE-NONOWNER-POSITION');
        $this->get('/about')->assertOk()
            ->assertDontSee('PRIVATE-NONOWNER-NAME')
            ->assertDontSee('PRIVATE-NONOWNER-POSITION');
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResumePdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => $status,
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function profile(User $user, string $publishStatus = 'published'): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Verified Profile',
            'publish_status' => $publishStatus,
        ]);
    }

    public function test_public_resume_has_a_real_bilingual_download_only_for_published_owner(): void
    {
        Http::fake();
        $this->get('/resume?lang=en')->assertOk()->assertDontSee('Download verified PDF');
        $this->get('/resume/pdf?lang=en')->assertNotFound();

        $this->profile($this->user('reader'));
        $this->get('/resume/pdf?lang=en')->assertNotFound();

        $owner = $this->user('owner');
        $profile = $this->profile($owner);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Real approved organisation',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'started_on' => '2021-01-01',
            'verified' => true,
            'publishable' => true,
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'PRIVATE ORGANISATION',
            'role_en' => 'Private',
            'role_ar' => 'خاص',
            'started_on' => '2020-01-01',
            'verified' => false,
            'publishable' => false,
        ]);

        $this->get('/resume?lang=ar&track=database')->assertOk()
            ->assertSee('تنزيل السيرة PDF')
            ->assertSee('href="'.route('public.resume.pdf', [
                'lang' => 'ar', 'track' => 'database',
            ]).'"', false);

        foreach (['ar', 'en'] as $locale) {
            $response = $this->get('/resume/pdf?lang='.$locale.'&track=database')
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString(
                'attachment; filename="waleed-verified-cv-database-'.$locale.'.pdf"',
                $response->headers->get('Content-Disposition')
            );
            $binary = $response->getContent();
            $this->assertTrue(str_starts_with($binary, '%PDF-'));
            $this->assertTrue(str_contains(substr($binary, -100), '%%EOF'));
            $this->assertGreaterThan(2000, strlen($binary));
        }
        Http::assertNothingSent();
    }

    public function test_unpublished_or_inactive_owner_never_has_a_public_pdf(): void
    {
        Http::fake();
        $owner = $this->user();
        $profile = $this->profile($owner, 'draft');
        $this->get('/resume/pdf')->assertNotFound();
        $profile->update(['publish_status' => 'published']);
        $owner->update(['status' => 'suspended']);
        $this->get('/resume/pdf?lang=ar')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_download_validates_locale_track_and_never_fetches_external_assets(): void
    {
        Http::fake();
        $this->profile($this->user());
        $this->get('/resume/pdf?lang=en&track=all')->assertOk();
        $this->get('/resume/pdf?lang=ar&track=delivery')->assertOk();
        foreach ([
            '/resume/pdf?lang[]=ar',
            '/resume/pdf?lang=de',
            '/resume/pdf?track[]=database',
            '/resume/pdf?track=../../private',
            '/resume/pdf?track=unsupported',
        ] as $url) {
            $this->get($url)->assertRedirect()->assertSessionHasErrors();
        }
        Http::assertNothingSent();
    }
}

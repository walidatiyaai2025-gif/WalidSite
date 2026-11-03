<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * T009 end-to-end engineering acceptance across WEB-04/05/11/14.
 * Owner-frozen WEB-04 visual source is intentionally untouched.
 * This is NOT a decoded-RGBA screenshot parity certificate (#481).
 */
final class T009EndToEndAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, string $visibility = 'public'): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Evidence reviewed delivery',
            'summary_en' => 'Public implementation evidence',
            'summary_ar' => 'أدلة تنفيذ موثقة للنشر',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
    }

    private function evidence(Project $project, string $url = 'https://docs.example.org/public-case'): ProjectEvidence
    {
        return ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => now()->subHour(),
        ]);
    }

    private function publicOwner(): Profile
    {
        $user = User::create([
            'email' => 't009-owner@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Approved Public Owner',
            'publish_status' => 'published',
        ]);
    }

    public function test_verified_but_unrouteable_project_slugs_never_occupy_directory_page_slots(): void
    {
        $publishedAt = now()->subDays(2)->startOfMinute();
        foreach (['invalid_slug', 'invalid slug', 'invalid.slug'] as $badSlug) {
            $bad = $this->project($badSlug);
            $bad->forceFill(['published_at' => now()->subMinutes(2)])->save();
            $this->evidence($bad);
        }
        for ($i = 1; $i <= 10; $i++) {
            $good = $this->project('reachable-project-'.$i);
            $good->forceFill(['published_at' => $publishedAt->copy()->subMinutes($i)])->save();
            $this->evidence($good);
        }

        foreach (['en', 'ar'] as $locale) {
            $first = $this->get('/projects?lang='.$locale)->assertOk()
                ->assertSee('Page 1 / 2')
                ->assertSee('reachable-project-1')
                ->assertSee('reachable-project-9')
                ->assertDontSee('reachable-project-10')
                ->assertDontSee('invalid_slug')
                ->assertDontSee('invalid slug')
                ->assertDontSee('invalid.slug');

            $this->assertSame(9, substr_count($first->getContent(), '/projects/reachable-project-'));
            $this->get('/projects?lang='.$locale.'&page=2')->assertOk()
                ->assertSee('reachable-project-10')
                ->assertDontSee('reachable-project-1"')
                ->assertDontSee('invalid_slug');
            $this->get(route('public.project', [
                'slug' => 'reachable-project-10','lang' => $locale,
            ]))->assertOk()->assertSee('https://docs.example.org/public-case');
        }
        // Deep links for malformed slugs have always been rejected by the
        // canonical route. The directory must now agree with that policy.
        $this->get('/projects/invalid_slug')->assertNotFound();
    }

    public function test_reviewer_to_cv_to_private_consent_contact_journey_works_in_both_locales(): void
    {
        Http::fake();
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.contact_recipient' => 'recipient@example.test',
        ]);
        $profile = $this->publicOwner();
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Public referenced employer',
            'role_en' => 'Verified Engineer',
            'role_ar' => 'مهندس موثق',
            'started_on' => '2021-01-01',
            'verified' => true,
            'publishable' => true,
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'PRIVATE EMPLOYER NEVER DISCLOSE',
            'role_en' => 'Private Role',
            'role_ar' => 'دور خاص',
            'started_on' => '2020-01-01',
            'verified' => false,
            'publishable' => false,
        ]);
        $project = $this->project('reviewed-end-to-end');
        $proof = $this->evidence($project);
        $project->evidence()->create([
            'evidence_type' => 'document',
            'url' => 'https://private.example.org/internal',
            'visibility' => 'private',
            'verified_at' => now()->subHour(),
        ]);

        foreach (['en', 'ar'] as $lang) {
            $caseUrl = route('public.project', ['slug'=>$project->slug,'lang'=>$lang]);
            $contactUrl = route('public.contact', ['lang'=>$lang,'project'=>$project->slug]);
            $this->get('/projects?lang='.$lang)->assertOk()
                ->assertSee('href="'.e($caseUrl).'"', false)
                ->assertDontSee('private.example.org');

            $this->get($caseUrl)->assertOk()
                ->assertSee('https://docs.example.org/public-case')
                ->assertDontSee('private.example.org')
                ->assertSee('href="'.e($contactUrl).'"', false)
                ->assertSee('href="'.e(route('public.resume', ['lang'=>$lang])).'"', false);

            $this->get('/resume?lang='.$lang.'&track=delivery')->assertOk()
                ->assertSee($lang === 'ar' ? 'مهندس موثق' : 'Verified Engineer')
                ->assertDontSee('PRIVATE EMPLOYER NEVER DISCLOSE');
            $pdf = $this->get('/resume/pdf?lang='.$lang.'&track=delivery')
                ->assertOk()
                ->assertHeader('Content-Type','application/pdf')
                ->assertHeader('Cache-Control','no-store, private');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());

            $this->get($contactUrl)->assertOk()
                ->assertSee('name="privacy_consent"', false)
                ->assertSee('href="'.e($caseUrl).'"', false)
                ->assertDontSee('private.example.org');
            $this->from($contactUrl)->post('/contact?lang='.$lang, [
                'name' => 'Verified case inquirer',
                'email' => 'inquirer@example.test',
                'subject' => $lang === 'ar'
                    ? 'استفسار بشأن المشروع الموثق'
                    : 'Verified case study inquiry',
                'message' => 'Please discuss the public engineering case study.',
                'privacy_consent' => '1',
            ])->assertRedirect(route('public.contact', ['lang'=>$lang]))
                ->assertSessionHas('contact_delivered', true);
        }
        Mail::assertSentCount(2);
        Http::assertNothingSent();

        // Evidence can be revoked without republishing; the case, directory
        // and contact prefill must all fail closed on the next request.
        $proof->update(['visibility' => 'private']);
        foreach (['en', 'ar'] as $lang) {
            $this->get('/projects?lang='.$lang)->assertOk()
                ->assertDontSee($project->slug);
            $this->get(route('public.project', [
                'slug'=>$project->slug,'lang'=>$lang,
            ]))->assertNotFound();
            $this->get(route('public.contact', [
                'lang'=>$lang,'project'=>$project->slug,
            ]))->assertOk()
                ->assertDontSee('class="contact-project-context"', false)
                ->assertDontSee('Evidence reviewed delivery');
        }
        Mail::assertSentCount(2);
    }

    public function test_html_resume_has_same_finite_verified_experience_limit_as_pdf(): void
    {
        $profile = $this->publicOwner();
        $baseDate = now()->subDay();
        for ($i = 0; $i < 251; $i++) {
            CareerPosition::create([
                'profile_id' => $profile->id,
                'org_name' => 'Verified organisation '.$i,
                'role_en' => 'Public role '.$i,
                'role_ar' => 'دور منشور '.$i,
                'started_on' => $baseDate->copy()->subDays($i)->toDateString(),
                'verified' => true,
                'publishable' => true,
            ]);
        }
        $page = $this->get('/resume?lang=en')->assertOk()
            ->assertSee('Public role 0')
            ->assertSee('Public role 249')
            ->assertDontSee('Public role 250');
        $this->assertSame(250, substr_count($page->getContent(), 'Verified organisation '));
    }

    public function test_unverified_private_and_unavailable_contact_never_fabricate_delivery(): void
    {
        Mail::fake();
        $private = $this->project('not-public-yet', 'private');
        $this->evidence($private);
        $this->get('/projects?lang=ar')->assertOk()
            ->assertDontSee('not-public-yet');
        $this->get('/projects/not-public-yet?lang=ar')->assertNotFound();

        config(['mail.default'=>'log','mail.contact_recipient'=>null]);
        $this->get('/contact?lang=ar')->assertOk()
            ->assertSee('استقبال الرسائل غير مفعل')
            ->assertDontSee('name="subject"', false);
        $this->post('/contact?lang=ar',[
            'name'=>'Visitor',
            'email'=>'visitor@example.test',
            'subject'=>'Unconfigured delivery',
            'message'=>'This should not be reported as sent.',
            'privacy_consent'=>'1',
        ])->assertStatus(503);
        Mail::assertNothingSent();
    }
}

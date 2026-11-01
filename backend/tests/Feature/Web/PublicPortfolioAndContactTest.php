<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicPortfolioAndContactTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, string $visibility = 'public'): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Documented Project',
            'summary_en' => 'Confirmed public case study',
            'summary_ar' => 'دراسة حالة معتمدة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
    }

    private function evidence(Project $project, string $visibility = 'public', bool $verified = true, string $url = 'https://example.org/evidence'): void
    {
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => $visibility,
            'verified_at' => $verified ? now()->subDay() : null,
        ]);
    }

    public function test_projects_have_localized_functional_empty_and_search_states(): void
    {
        $this->get('/projects')->assertOk()
            ->assertSee('Verified projects and case studies')
            ->assertSee('No evidence-verified public case studies')
            ->assertSee('role="search"', false);
        $this->get('/projects?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مشاريع ودراسات حالة موثقة');

        $project = $this->project('documented-example');
        $this->evidence($project);
        $this->get('/projects?q=Documented')->assertOk()
            ->assertSee('Documented Project')
            ->assertSee('href="'.route('public.project', ['slug' => $project->slug, 'lang' => 'en']).'"', false);
        $this->get('/projects?q=Missing')->assertOk()->assertDontSee('Documented Project');
    }

    public function test_case_studies_fail_closed_for_private_draft_and_unverified_evidence(): void
    {
        $private = $this->project('internal-project', 'private');
        $this->evidence($private);
        $unverified = $this->project('unverified-project');
        $this->evidence($unverified, 'public', false);
        $invalid = $this->project('invalid-evidence');
        $this->evidence($invalid, 'public', true, 'https://github.com/private/repo');

        $this->get('/projects')->assertOk()
            ->assertDontSee('internal-project')
            ->assertDontSee('unverified-project')
            ->assertDontSee('invalid-evidence');
        foreach ([$private, $unverified, $invalid] as $project) {
            $this->get('/projects/'.$project->slug)->assertNotFound();
        }

        $published = $this->project('safe-public-case');
        $this->evidence($published);
        $this->evidence($published, 'private', true, 'https://private.example.org/secret');
        $this->get('/projects/'.$published->slug)->assertOk()
            ->assertSee('https://example.org/evidence')
            ->assertDontSee('https://private.example.org/secret')
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_public_resume_never_exposes_pending_private_cv(): void
    {
        $user = User::create(['email' => 'resume@example.test', 'status' => 'pending_activation']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Unpublished Person',
            'bio_en' => 'Confidential CV details',
            'bio_ar' => 'معلومات خاصة',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Private Employer',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'started_on' => '2024-01-01',
            'verified' => true,
            'publishable' => true,
        ]);
        $this->get('/resume')->assertOk()->assertDontSee('Unpublished Person')
            ->assertDontSee('Private Employer')->assertDontSee('Confidential CV details');
        $user->update(['status' => 'active']);
        $this->get('/resume')->assertOk()
            ->assertSee('Unpublished Person')->assertSee('Private Employer')
            ->assertDontSee('Confidential CV details');
    }

    public function test_resume_lists_only_verified_skills_of_published_active_owner_in_both_languages(): void
    {
        $owner = User::create(['email' => 'cv-skills-owner@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Public CV Owner',
            'publish_status' => 'published',
        ]);

        $verified = Skill::create([
            'slug' => 'verified-db-skill', 'name_en' => 'Verified SQL Server Skill',
            'name_ar' => 'مهارة SQL Server موثقة',
            'category' => 'Database & Reliability', 'evidence_status' => 'verified',
        ]);
        $unverified = Skill::create([
            'slug' => 'unverified-cv-skill', 'name_en' => 'Unreviewed CV Skill',
            'name_ar' => 'مهارة غير معتمدة',
            'category' => 'Database & Reliability', 'evidence_status' => 'owner_cv',
        ]);
        $unrelated = Skill::create([
            'slug' => 'other-person-skill', 'name_en' => 'Other Person Secret Skill',
            'name_ar' => 'مهارة شخص آخر',
            'category' => 'Database & Reliability', 'evidence_status' => 'verified',
        ]);
        DB::table('profile_skill')->insert([
            ['profile_id' => $profile->id, 'skill_id' => $verified->id],
            ['profile_id' => $profile->id, 'skill_id' => $unverified->id],
        ]);
        $other = User::create(['email' => 'other-person@example.test', 'status' => 'active']);
        $otherProfile = Profile::create([
            'user_id' => $other->id,
            'public_name' => 'Other Person', 'publish_status' => 'published',
        ]);
        DB::table('profile_skill')->insert([
            ['profile_id' => $otherProfile->id, 'skill_id' => $unrelated->id],
        ]);

        $this->get('/resume')->assertOk()
            ->assertSee('Verified skills')->assertSee('Verified SQL Server Skill')
            ->assertDontSee('Unreviewed CV Skill')
            ->assertDontSee('Other Person Secret Skill');
        $this->get('/resume?lang=ar')->assertOk()
            ->assertSee('المهارات الموثقة')
            ->assertSee('مهارة SQL Server موثقة')
            ->assertDontSee('مهارة غير معتمدة')
            ->assertDontSee('مهارة شخص آخر');

        $profile->update(['publish_status' => 'review']);
        $this->get('/resume')->assertOk()
            ->assertDontSee('Verified SQL Server Skill')
            ->assertSee('Public CV review is pending');
    }

    public function test_contact_is_explicitly_unavailable_without_verified_delivery_configuration(): void
    {
        config(['mail.contact_recipient' => null, 'mail.default' => 'log']);
        $this->get('/contact?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('استقبال الرسائل غير مفعل');
        $this->post('/contact', [
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Technical inquiry',
            'message' => 'A real technical inquiry, for review.',
        ])->assertStatus(503);
    }

    public function test_contact_form_validates_and_honeypot_never_sends(): void
    {
        config(['mail.contact_recipient' => 'contact@example.test', 'mail.default' => 'smtp']);
        Mail::fake();
        $this->get('/contact')->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="message"', false);
        $this->post('/contact', [
            'name' => 'V',
            'email' => 'not-email',
            'subject' => 'a',
            'message' => 'short',
        ])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
        $this->post('/contact', [
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Technical inquiry',
            'message' => 'This is a technical inquiry for review.',
            'website' => 'https://bot.test/',
        ])->assertRedirect();
        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidencePortfolioPagesTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, string $visibility = 'public', string $evidence = 'https://docs.example.test/proof'): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => $slug === 'verified-project' ? 'Verified Cloud Project' : 'Confidential Project',
            'summary_en' => 'Evidence-reviewed technical work',
            'summary_ar' => 'عمل تقني تم التحقق من أدلته',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'public_document',
            'url' => $evidence,
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);

        return $project;
    }

    public function test_project_directory_search_and_case_study_only_show_public_proof(): void
    {
        $this->project('verified-project');
        $this->project('internal-project', 'private');
        $this->project('unsafe-evidence', 'public', 'http://localhost/private');

        $this->get('/projects')->assertOk()
            ->assertSee('Verified Cloud Project')
            ->assertDontSee('Confidential Project')
            ->assertDontSee('unsafe-evidence')
            ->assertSee('href="'.route('public.project', ['slug' => 'verified-project', 'lang' => 'en']).'"', false);

        $this->get('/projects?q=Cloud')->assertOk()->assertSee('Verified Cloud Project');
        $this->get('/projects?q=no-matches')->assertOk()->assertSee('No evidence-verified public case studies');

        $this->get('/projects/verified-project?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('عمل تقني تم التحقق من أدلته')
            ->assertSee('https://docs.example.test/proof');

        $this->get('/projects/internal-project')->assertNotFound();
        $this->get('/projects/unsafe-evidence')->assertNotFound();
    }

    public function test_resume_shows_nothing_unpublished_or_from_non_owner_accounts(): void
    {
        $this->get('/resume')->assertOk()->assertSee('Public CV review is pending');

        $other = User::create(['email' => 'not-owner@example.test', 'status' => 'active']);
        Profile::create([
            'user_id' => $other->id,
            'public_name' => 'Never Published Non Owner',
            'publish_status' => 'published',
        ]);
        $this->get('/resume')->assertOk()->assertDontSee('Never Published Non Owner');

        $owner = User::create(['email' => 'approved-owner@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Public Verified Owner',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Public Evidence Organization',
            'role_en' => 'Approved Engineer',
            'role_ar' => 'مهندس معتمد',
            'started_on' => '2024-01-01',
            'verified' => true,
            'publishable' => true,
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Confidential Employer',
            'role_en' => 'Private Role',
            'role_ar' => 'دور سري',
            'started_on' => '2023-01-01',
            'verified' => false,
            'publishable' => false,
        ]);

        $this->get('/resume')->assertOk()
            ->assertSee('Approved Engineer')
            ->assertSee('Public Verified Owner')
            ->assertDontSee('Private Role')
            ->assertDontSee('Confidential Employer');
        $this->get('/resume?lang=ar')->assertOk()->assertSee('dir="rtl"', false)
            ->assertSee('مهندس معتمد');
    }

    public function test_contact_delivery_is_disabled_without_configured_mail_transport(): void
    {
        config()->set('mail.contact_recipient', null);
        config()->set('mail.default', 'log');

        $this->get('/contact')->assertOk()
            ->assertSee('Contact delivery is unavailable')
            ->assertDontSee('name="message"', false);
        $this->post('/contact', [
            'name' => 'Anonymous Example',
            'email' => 'sender@example.test',
            'subject' => 'Project inquiry',
            'message' => 'Interested in a published project.',
        ])->assertStatus(503);
    }

    public function test_contact_sends_only_when_configured_and_honeypot_does_not_send(): void
    {
        config()->set('mail.contact_recipient', 'reviewed-inbox@example.test');
        config()->set('mail.default', 'smtp');
        Mail::fake();

        $this->get('/contact')->assertOk()->assertSee('name="message"', false);
        $input = [
            'name' => 'Visitor Test',
            'email' => 'visitor@example.test',
            'subject' => 'A verified project inquiry',
            'message' => 'I would like to discuss the published work.',
        ];

        $this->post('/contact?lang=en', array_merge($input, ['website' => 'spam.example']))
            ->assertRedirect(route('public.contact', ['lang' => 'en']));
        Mail::assertNothingSent();

        $this->post('/contact?lang=en', $input)
            ->assertRedirect(route('public.contact', ['lang' => 'en']))
            ->assertSessionHas('contact_delivered', true);
        Mail::assertSentCount(1);
    }
}

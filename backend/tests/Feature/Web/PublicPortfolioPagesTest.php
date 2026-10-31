<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicPortfolioPagesTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, string $visibility = 'public', bool $published = true): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Verified '. $slug,
            'summary_en' => 'Evidence-backed engineering work',
            'summary_ar' => 'عمل هندسي موثق',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => $published ? now()->subDay() : null,
        ]);
    }

    private function proof(Project $project, string $url = 'https://docs.example.test/evidence', bool $verified = true): void
    {
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'public_document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => $verified ? now()->subDay() : null,
        ]);
    }

    public function test_empty_public_pages_are_reachable_without_inventing_content(): void
    {
        $this->get('/projects')->assertOk()->assertSee('No evidence-verified public case studies');
        $this->get('/resume')->assertOk()->assertSee('Public CV review is pending');
        $this->get('/projects/unknown')->assertNotFound();
    }

    public function test_public_projects_require_publication_and_usable_verified_evidence(): void
    {
        $good = $this->project('reviewed');
        $this->proof($good);

        $private = $this->project('confidential', 'private');
        $this->proof($private);

        $draft = $this->project('draft', 'public', false);
        $this->proof($draft);

        $unverified = $this->project('unverified');
        $this->proof($unverified, 'https://docs.example.test/no-validation', false);

        $unsafe = $this->project('unsafe');
        $this->proof($unsafe, 'https://github.com/owner/possibly-private');

        $this->get('/projects')->assertOk()
            ->assertSee('Verified reviewed')
            ->assertDontSee('Verified confidential')
            ->assertDontSee('Verified draft')
            ->assertDontSee('Verified unverified')
            ->assertDontSee('Verified unsafe');

        $this->get('/projects/reviewed')->assertOk()
            ->assertSee('https://docs.example.test/evidence')
            ->assertSee('Evidence-backed engineering work');

        foreach (['confidential', 'draft', 'unverified', 'unsafe'] as $slug) {
            $this->get('/projects/'.$slug)->assertNotFound();
        }
    }

    public function test_case_study_supports_arabic_and_search_filters_without_private_data(): void
    {
        $project = $this->project('arabic-case');
        $this->proof($project);
        $this->get('/projects?lang=ar&q=arabic-case')->assertOk()
            ->assertSee('مشاريع ودراسات حالة موثقة')
            ->assertSee('Verified arabic-case');
        $this->get('/projects/arabic-case?lang=ar')->assertOk()
            ->assertSee('عمل هندسي موثق')
            ->assertSee('مهندس');
        $this->get('/projects?q=missing')->assertOk()
            ->assertDontSee('Verified arabic-case');
    }

    public function test_resume_never_shows_a_published_profile_from_an_inactive_non_owner(): void
    {
        $user = User::create(['email' => 'unapproved@example.test', 'status' => 'pending_activation']);
        Profile::create([
            'user_id' => $user->id,
            'public_name' => 'PRIVATE NON OWNER',
            'publish_status' => 'published',
        ]);

        $this->get('/resume?lang=ar')->assertOk()
            ->assertDontSee('PRIVATE NON OWNER')
            ->assertSee('السيرة العامة قيد المراجعة');
    }

    public function test_contact_is_explicitly_unavailable_without_configured_delivery(): void
    {
        config()->set('mail.contact_recipient', null);
        config()->set('mail.default', 'log');

        $this->get('/contact')->assertOk()
            ->assertSee('Contact delivery is unavailable')
            ->assertDontSee('type="submit"', false);

        $this->post('/contact', [
            'name' => 'Visitor', 'email' => 'visitor@example.test',
            'subject' => 'Project question', 'message' => 'A meaningful question for the owner.',
        ])->assertStatus(503);
    }

    public function test_honeypot_discards_spam_without_dispatching_mail(): void
    {
        config()->set('mail.contact_recipient', 'owner@example.test');
        config()->set('mail.default', 'smtp');
        Mail::fake();

        $this->post('/contact?lang=ar', [
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Project question',
            'message' => 'A meaningful question about verified work.',
            'website' => 'https://spam.example.test',
        ])->assertRedirect(route('public.contact', ['lang' => 'ar']));

        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProfessionalVerifiedProjectNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, string $title, string $visibility = 'public'): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => $title,
            'summary_en' => 'Public evidence-reviewed project description.',
            'summary_ar' => 'دراسة حالة منشورة بأدلة تمت مراجعتها.',
            'role_en' => 'Engineering',
            'role_ar' => 'هندسة',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
    }

    private function evidence(Project $project, string $url, bool $verified = true): void
    {
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => $verified ? now()->subDay() : null,
        ]);
    }

    public function test_professional_pages_link_to_the_real_evidence_gated_gallery_in_both_languages(): void
    {
        $approved = $this->project('approved-engineering-case', 'Approved Engineering Case');
        $this->evidence($approved, 'https://docs.example.org/approved');

        $private = $this->project('private-engineering-case', 'Private Engineering Case', 'private');
        $this->evidence($private, 'https://docs.example.org/private');

        $unverified = $this->project('unverified-engineering-case', 'Unverified Engineering Case');
        $this->evidence($unverified, 'https://docs.example.org/pending', false);

        $unsafe = $this->project('unsafe-engineering-case', 'Unsafe Engineering Case');
        $this->evidence($unsafe, 'https://docs.example.org/internal?token=secret');

        foreach ([
            'en' => 'Verified case studies',
            'ar' => 'دراسات حالة موثقة',
        ] as $locale => $linkLabel) {
            $galleryUrl = route('public.projects', ['lang' => $locale]);

            foreach (['about', 'expertise', 'hire'] as $page) {
                $this->get(route('public.'.$page, ['lang' => $locale]))->assertOk()
                    ->assertSee('href="'.e($galleryUrl).'"', false)
                    ->assertSee($linkLabel);
            }

            // The destination itself enforces evidence, visibility, valid
            // publication, and safe URLs. No unverified skill-case assertion.
            $this->get($galleryUrl)->assertOk()
                ->assertSee('Approved Engineering Case')
                ->assertSee('approved-engineering-case')
                ->assertDontSee('Private Engineering Case')
                ->assertDontSee('Unverified Engineering Case')
                ->assertDontSee('Unsafe Engineering Case')
                ->assertDontSee('token=secret');
            $this->get('/projects/private-engineering-case?lang='.$locale)->assertNotFound();
            $this->get('/projects/unverified-engineering-case?lang='.$locale)->assertNotFound();
            $this->get('/projects/unsafe-engineering-case?lang='.$locale)->assertNotFound();
        }
    }

    public function test_case_study_discovery_link_remains_available_without_public_skills_or_projects(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->get(route('public.expertise', ['lang' => $locale]))->assertOk()
                ->assertSee('href="'.e(route('public.projects', ['lang' => $locale])).'"', false);
            $this->get(route('public.projects', ['lang' => $locale]))->assertOk()
                ->assertDontSee('Approved Engineering Case');
        }
    }
}

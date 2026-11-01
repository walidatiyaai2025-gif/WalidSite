<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomeCaseStudyDestinationTest extends TestCase
{
    use RefreshDatabase;

    private function addProject(string $slug, int $daysAgo, string $proofUrl): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Reviewed '.$slug,
            'summary_en' => 'Public engineering facts backed by a reviewed link.',
            'summary_ar' => 'حقائق هندسية منشورة بعد التحقق من مصدرها.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDays($daysAgo),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $proofUrl,
            'visibility' => 'public',
            'verified_at' => now()->subDays($daysAgo)->subMinute(),
        ]);

        return $project;
    }

    public function test_home_fills_three_featured_slots_with_reachable_verified_projects(): void
    {
        // These projects pass the SQL/public evidence checks but do not match
        // the actual WEB-05 case-study slug route. None may displace a real
        // working destination on the home screen.
        for ($i = 1; $i <= 5; $i++) {
            $this->addProject('unreachable_home_'.$i, 1, 'https://docs.example.org/approved');
        }
        $this->addProject('unsafe-home', 1, 'https://docs.example.org/secret?token=private');
        $approved = [
            'approved-home-one',
            'approved-home-two',
            'approved-home-three',
        ];
        foreach ($approved as $slug) {
            $this->addProject($slug, 2, 'https://docs.example.org/approved');
        }

        foreach (['en', 'ar'] as $locale) {
            $home = $this->get(route('home', ['lang' => $locale]))->assertOk()
                ->assertDontSee('unreachable_home_')
                ->assertDontSee('unsafe-home')
                ->assertDontSee('token=private');
            foreach ($approved as $slug) {
                $detailUrl = route('public.project', ['slug' => $slug, 'lang' => $locale]);
                $home->assertSee('href="'.e($detailUrl).'"', false)
                    ->assertSee('Reviewed '.$slug);

                $this->get($detailUrl)->assertOk()
                    ->assertSee('Reviewed '.$slug)
                    ->assertSee('https://docs.example.org/approved');
            }
            foreach (range(1, 5) as $i) {
                $this->get('/projects/unreachable_home_'.$i.'?lang='.$locale)->assertNotFound();
            }
        }
    }

    public function test_home_does_not_display_broken_or_unsafe_featured_links_as_verified(): void
    {
        $this->addProject('invalid_home_only', 1, 'https://docs.example.org/approved');
        $this->addProject('unsafe-only', 1, 'https://docs.example.org/approved?access_token=secret');

        foreach (['en', 'ar'] as $locale) {
            $this->get(route('home', ['lang' => $locale]))->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'لا توجد دراسات حالة عامة مستوفية لشروط النشر'
                    : 'No evidence-verified public case studies are available yet.')
                ->assertDontSee('invalid_home_only')
                ->assertDontSee('unsafe-only')
                ->assertDontSee('access_token');
        }
    }
}

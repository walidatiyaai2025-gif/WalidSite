<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicProjectDiscoveryLinksTest extends TestCase
{
    use RefreshDatabase;

    private function reviewedProject(int $number, bool $verified = true): Project
    {
        $project = Project::create([
            'slug' => 'reviewed-case-'.sprintf('%02d', $number),
            'title' => 'Reviewed project '.sprintf('%02d', $number),
            'summary_en' => 'Reviewed technical evidence for a public project.',
            'summary_ar' => 'أدلة تقنية تمت مراجعتها لمشروع عام.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subMinutes($number + 1),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/real-evidence-'.sprintf('%02d', $number),
            'visibility' => 'public',
            'verified_at' => $verified ? now()->subMinute() : null,
        ]);

        return $project;
    }

    public function test_bilingual_project_pagination_keeps_only_reviewed_filters_without_echoing_untrusted_query(): void
    {
        foreach (range(1, 11) as $number) {
            $this->reviewedProject($number);
        }

        foreach (['en', 'ar'] as $locale) {
            $base = ['lang' => $locale, 'q' => 'Reviewed', 'status' => 'completed'];
            $next = route('public.projects', array_merge($base, ['page' => 2]));
            $previous = route('public.projects', array_merge($base, ['page' => 1]));
            $clean = route('public.projects', ['lang' => $locale]);

            $this->get('/projects?lang='.$locale.'&q=Reviewed&status=completed&tracking=PRIVATE_TRACKING_TOKEN&secret%5Bkey%5D=LEAK')
                ->assertOk()
                ->assertSee('href="'.e($next).'"', false)
                ->assertSee('href="'.$clean.'"', false)
                ->assertSee($locale === 'ar' ? 'مسح المرشحات' : 'Clear filters')
                ->assertDontSee('PRIVATE_TRACKING_TOKEN')
                ->assertDontSee('LEAK');

            $this->get('/projects?lang='.$locale.'&q=Reviewed&status=completed&page=2&campaign=PRIVATE_CAMPAIGN')
                ->assertOk()
                ->assertSee('href="'.e($previous).'"', false)
                ->assertSee('Reviewed project 11')
                ->assertDontSee('PRIVATE_CAMPAIGN');
        }
    }

    public function test_array_valued_or_unknown_filters_never_enter_public_pagination_urls(): void
    {
        foreach (range(1, 11) as $number) {
            $this->reviewedProject($number);
        }

        $pageTwo = route('public.projects', ['lang' => 'en', 'page' => 2]);
        $this->get('/projects?lang=en&q%5Btoken%5D=HIDDEN&status%5B0%5D=completed&debug=PRIVATE_DEBUG')
            ->assertOk()
            ->assertSee('href="'.$pageTwo.'"', false)
            ->assertDontSee('HIDDEN')
            ->assertDontSee('PRIVATE_DEBUG')
            ->assertDontSee('Clear filters');

        $this->get('/projects?lang=ar&q%5Btoken%5D=HIDDEN&status=completed&extra=PRIVATE_DEBUG')
            ->assertOk()
            ->assertSee('href="'.e(route('public.projects', [
                'lang' => 'ar', 'status' => 'completed', 'page' => 2,
            ])).'"', false)
            ->assertDontSee('HIDDEN')
            ->assertDontSee('PRIVATE_DEBUG');
    }

    public function test_reset_and_pagination_never_discover_unverified_or_private_projects(): void
    {
        foreach (range(1, 11) as $number) {
            $this->reviewedProject($number, $number <= 3);
        }
        $private = $this->reviewedProject(12);
        $private->update(['visibility' => 'private']);

        $this->get('/projects?lang=ar&q=Reviewed&status=completed')
            ->assertOk()
            ->assertSee('Reviewed project 01')
            ->assertDontSee('Reviewed project 10')
            ->assertDontSee('Reviewed project 12')
            ->assertDontSee('href="'.e(route('public.projects', [
                'lang' => 'ar', 'q' => 'Reviewed', 'status' => 'completed', 'page' => 2,
            ])).'"', false);

        $this->get('/projects?lang=ar&q=NoMatch&status=completed')
            ->assertOk()
            ->assertSee('لا توجد دراسات حالة عامة موثقة')
            ->assertSee('href="'.route('public.projects', ['lang' => 'ar']).'"', false);
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicCaseStudyBrowseJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function documentedProject(
        string $slug,
        string $status = 'completed',
        string $visibility = 'public',
        bool $verified = true,
        int $publishedMinutesAgo = 0
    ): Project {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Atlas Case '.str_replace('atlas-case-', '', $slug),
            'summary_en' => 'Evidence-backed public detail',
            'summary_ar' => 'تفاصيل عامة ذات أدلة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => $status,
            'visibility' => $visibility,
            'published_at' => now()->subDay()->subMinutes($publishedMinutesAgo),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://example.org/evidence/'.$slug,
            'visibility' => 'public',
            'verified_at' => $verified ? now()->subDay() : null,
        ]);

        return $project;
    }

    public function test_filtered_verified_project_list_is_accessible_and_case_study_returns_to_same_page(): void
    {
        // Projects sort by published_at DESC, id ASC. UUID ids are not insert-ordered;
        // give fixtures stable, distinct publish times so case 10 is on page 2.
        for ($i = 1; $i <= 10; $i++) {
            $this->documentedProject('atlas-case-'.$i, publishedMinutesAgo: $i);
        }
        $this->documentedProject('private-atlas', 'completed', 'private');
        $this->documentedProject('unverified-atlas', 'completed', 'public', false);
        $this->documentedProject('atlas-research', 'research');

        foreach (['en', 'ar'] as $locale) {
            $arabic = $locale === 'ar';
            $list = $this->get('/projects?lang='.$locale.'&q=Atlas&status=completed')
                ->assertOk()
                ->assertSee('role="status" aria-live="polite"', false)
                ->assertSee($arabic
                    ? 'عدد دراسات الحالة العامة الموثقة المطابقة: 10'
                    : 'Matching verified public case studies: 10')
                ->assertSee($arabic ? 'مسح البحث والمرشحات' : 'Clear search and filters')
                ->assertSee('aria-current="page"', false)
                ->assertDontSee('private-atlas')
                ->assertDontSee('unverified-atlas')
                ->assertDontSee('atlas-research');

            $this->assertStringContainsString(
                e(route('public.projects', [
                    'lang' => $locale, 'q' => 'Atlas',
                    'status' => 'completed', 'page' => 2,
                ])),
                $list->getContent()
            );

            $pageTwo = $this->get('/projects?lang='.$locale.'&q=Atlas&status=completed&page=2')
                ->assertOk()
                ->assertSee('Atlas Case 10')
                ->assertDontSee('Atlas Case 1<')
                ->assertSee($arabic ? 'الصفحة 2 / 2' : 'Page 2 / 2');

            $detailUrl = route('public.project', [
                'slug' => 'atlas-case-10',
                'lang' => $locale,
                'q' => 'Atlas',
                'status' => 'completed',
                'page' => 2,
            ]);
            $pageTwo->assertSee('href="'.e($detailUrl).'"', false);

            $detail = $this->get($detailUrl)
                ->assertOk()
                ->assertSee($arabic ? 'العودة إلى نتائج المشاريع' : 'Back to project results')
                ->assertSee('referrerpolicy="no-referrer"', false);
            $detail->assertSee('href="'.e(route('public.projects', [
                'lang' => $locale,
                'q' => 'Atlas',
                'status' => 'completed',
                'page' => 2,
            ])).'"', false);
        }
    }

    public function test_empty_and_unfiltered_states_offer_real_locale_preserving_destinations(): void
    {
        $this->documentedProject('atlas-case-1');
        foreach (['en', 'ar'] as $locale) {
            $this->get('/projects?lang='.$locale.'&q=no-such-case&status=completed')
                ->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'عدد دراسات الحالة العامة الموثقة المطابقة: 0'
                    : 'Matching verified public case studies: 0')
                ->assertSee('href="'.e(route('public.projects', ['lang' => $locale])).'"', false)
                ->assertSee('href="'.e(route('public.github', ['lang' => $locale])).'"', false);

            $this->get('/projects?lang='.$locale)
                ->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'عدد دراسات الحالة العامة الموثقة المطابقة: 1'
                    : 'Matching verified public case studies: 1')
                ->assertDontSee($locale === 'ar'
                    ? 'مسح البحث والمرشحات'
                    : 'Clear search and filters');
        }
    }

    public function test_case_study_return_destination_rejects_untrusted_and_malformed_query_values(): void
    {
        $this->documentedProject('atlas-case-1');

        $this->get('/projects/atlas-case-1?lang=ar&q[]=unsafe&status=invalid&page[]=2&return=https://evil.test')
            ->assertOk()
            ->assertSee('href="'.e(route('public.projects', ['lang' => 'ar'])).'"', false)
            ->assertDontSee('evil.test')
            ->assertDontSee('status=invalid')
            ->assertDontSee('q%5B');

        $this->get('/projects/atlas-case-1?lang=en&q=Atlas&status=completed&page=999999&next=https://evil.test')
            ->assertOk()
            ->assertSee('href="'.e(route('public.projects', [
                'lang' => 'en', 'q' => 'Atlas', 'status' => 'completed',
            ])).'"', false)
            ->assertDontSee('evil.test');

        $this->get('/projects?lang=ar&q[]=unsafe&status[]=completed')
            ->assertOk()
            ->assertSee('عدد دراسات الحالة العامة الموثقة المطابقة: 1')
            ->assertDontSee('q%5B');
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T009 integrated WEB-04 -> WEB-05 -> WEB-04 bilingual journey.
 * All links are generated from real publication-gated database records.
 */
final class ProjectBilingualBrowseReturnTest extends TestCase
{
    use RefreshDatabase;

    private function publishedProjects(): void
    {
        foreach (range(1, 11) as $number) {
            $slug = 'atlas-verified-'.sprintf('%02d', $number);
            $project = Project::create([
                'slug' => $slug,
                'title' => 'Atlas verified project '.sprintf('%02d', $number),
                'summary_en' => 'Reviewed evidence from a real publication gate.',
                'summary_ar' => 'أدلة جرت مراجعتها عبر بوابة النشر.',
                'role_en' => 'Engineer',
                'role_ar' => 'مهندس',
                'status' => 'completed',
                'visibility' => 'public',
                'published_at' => now()->subDay()->subMinutes($number),
            ]);
            ProjectEvidence::create([
                'project_id' => $project->id,
                'evidence_type' => 'document',
                'url' => 'https://docs.example.org/atlas-verified-'.sprintf('%02d', $number),
                'visibility' => 'public',
                'verified_at' => now()->subDay(),
            ]);
        }

        $private = Project::create([
            'slug' => 'atlas-private-do-not-publish',
            'title' => 'Atlas PRIVATE ACCOUNT DATA',
            'summary_en' => 'Confidential project.',
            'summary_ar' => 'مشروع خاص.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'private',
            'published_at' => null,
        ]);
        ProjectEvidence::create([
            'project_id' => $private->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/secret',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
    }

    public function test_second_page_language_switch_keeps_safe_search_status_and_return_context(): void
    {
        Http::fake();
        $this->publishedProjects();

        foreach (['en', 'ar'] as $locale) {
            $other = $locale === 'ar' ? 'en' : 'ar';
            $filters = ['q' => 'Atlas', 'status' => 'completed', 'page' => 2];
            $list = route('public.projects', array_merge(['lang' => $locale], $filters));
            $otherList = route('public.projects', array_merge(['lang' => $other], $filters));
            $detail = route('public.project', array_merge([
                'slug' => 'atlas-verified-11', 'lang' => $locale,
            ], $filters));
            $otherDetail = route('public.project', array_merge([
                'slug' => 'atlas-verified-11', 'lang' => $other,
            ], $filters));

            $this->get($list)->assertOk()
                ->assertSee('Atlas verified project 11')
                ->assertDontSee('Atlas verified project 01')
                ->assertDontSee('PRIVATE ACCOUNT DATA')
                ->assertSee('href="'.e($otherList).'"', false)
                ->assertSee('href="'.e($detail).'"', false);

            $this->get($detail)->assertOk()
                ->assertSee('Atlas verified project 11')
                ->assertSee('href="'.e($otherDetail).'"', false)
                ->assertSee('href="'.e($list).'"', false)
                ->assertDontSee('PRIVATE ACCOUNT DATA');

            $this->get($otherDetail)->assertOk()
                ->assertSee('href="'.e($otherList).'"', false)
                ->assertSee('href="'.e($detail).'"', false);
        }

        Http::assertNothingSent();
    }

    public function test_malformed_query_values_cannot_leak_to_language_switch_or_create_redirects(): void
    {
        Http::fake();
        $this->publishedProjects();

        $safeList = route('public.projects', ['lang' => 'ar']);
        $this->get('/projects?lang=en&q%5Bhidden%5D=PRIVATE_TOKEN&status=invalid&page%5B0%5D=2&next=https://evil.example')
            ->assertOk()
            ->assertSee('href="'.e($safeList).'"', false)
            ->assertDontSee('PRIVATE_TOKEN')
            ->assertDontSee('evil.example');

        $safeDetail = route('public.project', [
            'slug' => 'atlas-verified-11', 'lang' => 'ar',
        ]);
        $this->get('/projects/atlas-verified-11?lang=en&q%5B0%5D=PRIVATE_TOKEN&status[]=completed&page[]=2&return=https://evil.example')
            ->assertOk()
            ->assertSee('href="'.e($safeDetail).'"', false)
            ->assertDontSee('PRIVATE_TOKEN')
            ->assertDontSee('evil.example');

        foreach (['0', '-1', '10001', 'not-a-number'] as $page) {
            $this->get('/projects?lang=en&page='.$page)
                ->assertOk()->assertSee('href="'.e($safeList).'"', false);
            $this->get('/projects/atlas-verified-11?lang=en&page='.$page)
                ->assertOk()->assertSee('href="'.e($safeDetail).'"', false);
        }

        // Page 9999 is within numeric input bounds but exceeds the verified
        // listing's last page; it must not survive a list locale switch.
        $this->get('/projects?lang=en&page=9999')
            ->assertOk()
            ->assertSee('href="'.e($safeList).'"', false);

        Http::assertNothingSent();
    }

    public function test_valid_status_and_bounded_search_survive_without_arbitrary_tracking_tokens(): void
    {
        Http::fake();
        $this->publishedProjects();

        $search = str_repeat('A', 110);
        $expected = route('public.projects', [
            'lang' => 'en',
            'q' => str_repeat('A', 80),
            'status' => 'completed',
        ]);
        $this->get('/projects?lang=ar&status=completed&q='.$search.'&utm_source=PRIVATE_TRACKING_TOKEN')
            ->assertOk()
            ->assertSee('href="'.e($expected).'"', false)
            ->assertDontSee('PRIVATE_TRACKING_TOKEN')
            ->assertDontSee($search);

        Http::assertNothingSent();
    }
}

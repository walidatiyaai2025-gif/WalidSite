<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\PublicPortfolioController;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PublicReachableProjectDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, int $minutesAfterTwoDays, string $url): Project
    {
        $record = Project::create([
            'slug' => $slug,
            'title' => 'Reviewed '.$slug,
            'summary_en' => 'Evidence-verified public facts.',
            'summary_ar' => 'بيانات عامة موثقة بالمصادر.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDays(2)->addMinutes($minutesAfterTwoDays),
        ]);
        ProjectEvidence::create([
            'project_id' => $record->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => now()->subDays(3),
        ]);
        return $record;
    }

    public function test_home_web_listing_and_public_api_share_reachable_case_studies_and_true_page_totals(): void
    {
        // Leading rows pass published+evidence checks but cannot match the
        // WEB-05 /projects/{slug} route. No broken card may consume slots.
        for ($i = 1; $i <= 15; $i++) {
            $this->project('invalid_slug_'.$i, 120 + $i, 'https://docs.example.org/approved');
        }
        // A well-formed slug without safe public evidence also cannot be
        // counted as a visible project or used to inflate pagination.
        $this->project('unsafe-proof-case', 118, 'https://docs.example.org/proof?token=private');

        for ($i = 1; $i <= 14; $i++) {
            $this->project('reachable-case-'.$i, 60 - $i, 'https://docs.example.org/reviewed');
        }

        foreach (['en', 'ar'] as $locale) {
            $base = '/api/v1/public/projects?lang='.$locale;
            $first = $this->getJson($base.'&page=1')->assertOk()
                ->assertJsonCount(12, 'data')
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.total', 14)
                ->assertJsonPath('meta.last_page', 2)
                ->assertDontSee('invalid_slug_')
                ->assertDontSee('unsafe-proof-case')
                ->assertDontSee('token=private');
            $second = $this->getJson($base.'&page=2')->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 14)
                ->assertDontSee('invalid_slug_');
            $slugs = array_merge(
                array_column($first->json('data'), 'slug'),
                array_column($second->json('data'), 'slug')
            );
            $this->assertEqualsCanonicalizing(
                array_map(static fn ($i) => 'reachable-case-'.$i, range(1, 14)),
                $slugs
            );
            $this->assertCount(14, array_unique($slugs));

            $home = $this->get(route('home', ['lang' => $locale]))->assertOk()
                ->assertSee('reachable-case-1')
                ->assertSee('reachable-case-2')
                ->assertSee('reachable-case-3')
                ->assertDontSee('reachable-case-4')
                ->assertDontSee('invalid_slug_')
                ->assertDontSee('unsafe-proof-case');

            $web1 = $this->get('/projects?lang='.$locale.'&page=1')->assertOk()
                ->assertSee('reachable-case-1')
                ->assertSee('reachable-case-9')
                ->assertDontSee('reachable-case-10')
                ->assertDontSee('invalid_slug_')
                ->assertDontSee('unsafe-proof-case');
            $web2 = $this->get('/projects?lang='.$locale.'&page=2')->assertOk()
                ->assertSee('reachable-case-10')
                ->assertSee('reachable-case-14')
                ->assertDontSee('invalid_slug_')
                ->assertDontSee('token=private');

            foreach (range(1, 14) as $i) {
                $slug = 'reachable-case-'.$i;
                $caseUrl = route('public.project', ['slug' => $slug, 'lang' => $locale]);
                if ($i <= 9) {
                    $web1->assertSee('href="'.e($caseUrl).'"', false);
                } else {
                    $web2->assertSee('href="'.e($caseUrl).'"', false);
                }
                $this->get($caseUrl)->assertOk()
                    ->assertSee('Reviewed '.$slug)
                    ->assertSee('https://docs.example.org/reviewed');
                $this->getJson('/api/v1/public/projects/'.$slug.'?lang='.$locale)
                    ->assertOk()
                    ->assertJsonPath('data.slug', $slug);
            }

            foreach (range(1, 15) as $i) {
                $this->get('/projects/invalid_slug_'.$i)->assertNotFound();
                $this->getJson('/api/v1/public/projects/invalid_slug_'.$i)
                    ->assertNotFound();
            }
        }
    }

    public function test_invalid_route_slug_with_valid_proof_is_not_public_on_any_surface(): void
    {
        $record = $this->project(
            'unrouteable_project', 0, 'https://docs.example.org/approved'
        );
        $dto = (new PublicPortfolioController())->publicProjectData(
            Project::with('evidence')->findOrFail($record->id),
            Request::create('/api/v1/public/projects/'.$record->slug)
        );
        $this->assertSame([], $dto['evidence_urls']);

        $this->getJson('/api/v1/public/projects')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1);
        $this->get('/projects')->assertOk()
            ->assertSee('No evidence-verified public case studies')
            ->assertDontSee('unrouteable_project');
        $this->get('/')->assertOk()
            ->assertSee('No evidence-verified public case studies are available yet.')
            ->assertDontSee('unrouteable_project');
        $this->getJson('/api/v1/public/projects/'.$record->slug)->assertNotFound();
        $this->get('/projects/'.$record->slug)->assertNotFound();
    }
}

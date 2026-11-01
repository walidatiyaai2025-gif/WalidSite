<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\PublicPortfolioController;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PublicProjectPreloadedEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Reviewed Public Case Study',
            'summary_en' => 'Public, reviewed facts only',
            'summary_ar' => 'الوقائع العامة الموثقة فقط',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function proof(
        Project $project,
        string $url,
        string $visibility = 'public',
        ?\DateTimeInterface $verifiedAt = null
    ): ProjectEvidence {
        return ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => $visibility,
            'verified_at' => $verifiedAt,
        ]);
    }

    public function test_unscoped_preloaded_project_evidence_cannot_leak_private_unverified_or_future_sources(): void
    {
        $project = $this->project('reviewed-proof-boundary');
        $valid = 'https://docs.example.org/approved';
        $this->proof($project, $valid, 'public', now()->subDay());
        $this->proof($project, 'https://docs.example.org/private-staff', 'private', now()->subDay());
        $this->proof($project, 'https://docs.example.org/unverified', 'public', null);
        $this->proof($project, 'https://docs.example.org/future-proof', 'public', now()->addDay());
        $this->proof($project, 'https://docs.example.org/unsafe?token=private', 'public', now()->subDay());

        // Deliberately bypass any scoped eager-load filter. A public DTO must
        // never trust the contents of a caller-provided relation.
        $loaded = Project::query()->with('evidence')->findOrFail($project->id);
        $this->assertCount(5, $loaded->evidence);
        $controller = new PublicPortfolioController();

        foreach (['en', 'ar'] as $locale) {
            $dto = $controller->publicProjectData(
                $loaded,
                Request::create('/api/v1/public/projects/'.$project->slug.'?lang='.$locale)
            );
            $this->assertSame([$valid], $dto['evidence_urls']);
            $this->assertSame($locale === 'ar' ? 'مهندس' : 'Engineer', $dto['role']);
            $this->getJson('/api/v1/public/projects/'.$project->slug.'?lang='.$locale)
                ->assertOk()
                ->assertJsonPath('data.evidence_urls', [$valid])
                ->assertDontSee('private-staff')
                ->assertDontSee('unverified')
                ->assertDontSee('future-proof')
                ->assertDontSee('token=private');
        }
    }

    public function test_unscoped_relation_still_enforces_the_first_fifty_eligible_proofs(): void
    {
        $project = $this->project('reviewed-fifty-proof-cap');
        $expected = [];
        for ($index = 1; $index <= 51; $index++) {
            $url = 'https://docs.example.org/proof-'.$index;
            $proof = $this->proof($project, $url, 'public', now()->subDay());
            // Stable UUID sort, same first-fifty ordering as the scoped query.
            $proof->id = sprintf('00000000-0000-4000-8000-%012d', $index);
            $proof->save();
            if ($index <= 50) {
                $expected[] = $url;
            }
        }
        // This extra private record must not alter the count or public order.
        $this->proof($project, 'https://docs.example.org/private-proof', 'private', now()->subDay());

        $loaded = Project::query()->with('evidence')->findOrFail($project->id);
        $dto = (new PublicPortfolioController())->publicProjectData(
            $loaded, Request::create('/api/v1/public/projects/'.$project->slug)
        );
        $this->assertSame($expected, $dto['evidence_urls']);

        $this->getJson('/api/v1/public/projects/'.$project->slug)
            ->assertOk()
            ->assertJsonCount(50, 'data.evidence_urls')
            ->assertJsonPath('data.evidence_urls.0', $expected[0])
            ->assertJsonPath('data.evidence_urls.49', $expected[49])
            ->assertDontSee('private-proof')
            ->assertDontSee('proof-51');
    }

    public function test_loaded_private_only_sources_never_become_public_proof(): void
    {
        $project = $this->project('no-public-proof');
        $this->proof($project, 'https://docs.example.org/private-only', 'private', now()->subDay());
        $this->proof($project, 'https://docs.example.org/unreviewed-only', 'public', null);

        $loaded = Project::query()->with('evidence')->findOrFail($project->id);
        $this->assertSame([], (new PublicPortfolioController())->publicProjectData(
            $loaded, Request::create('/api/v1/public/projects/'.$project->slug)
        )['evidence_urls']);
        $this->getJson('/api/v1/public/projects/'.$project->slug)->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicProjectQueryValidationTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedProject(int $number): void
    {
        $project = Project::create([
            'slug' => 'verified-page-case-'.$number,
            'title' => 'Reviewed project '.$number,
            'summary_en' => 'Documented engineering work.',
            'summary_ar' => 'عمل هندسي موثق.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/approved-proof-'.$number,
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
    }

    public function test_public_project_list_rejects_malformed_or_out_of_range_page_and_locale(): void
    {
        $this->verifiedProject(1);

        foreach ([
            'page=0',
            'page=-1',
            'page[]=2',
            'page=not-a-number',
            'page=1.5',
            'page=10001',
            'page=999999999999999999999999999',
            'lang=invalid',
            'lang[]=ar',
            'lang=AR',
        ] as $query) {
            $this->getJson('/api/v1/public/projects?'.$query)
                ->assertUnprocessable()
                ->assertDontSee('verified-page-case-1');
        }

        $this->getJson('/api/v1/public/projects?page=1&lang=en')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.summary', 'Documented engineering work.');
        $this->getJson('/api/v1/public/projects?page=1&lang=ar')
            ->assertOk()
            ->assertJsonPath('data.0.summary', 'عمل هندسي موثق.');
    }

    public function test_bounded_page_numbers_preserve_safe_counts_and_empty_beyond_last_page(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->verifiedProject($i);
        }

        $first = $this->getJson('/api/v1/public/projects?page=1')->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);
        $second = $this->getJson('/api/v1/public/projects?page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);

        $slugs = array_merge(array_column($first->json('data'), 'slug'),
            array_column($second->json('data'), 'slug'));
        $this->assertCount(13, array_unique($slugs));

        $this->getJson('/api/v1/public/projects?page=10000')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 10000)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);
    }
}

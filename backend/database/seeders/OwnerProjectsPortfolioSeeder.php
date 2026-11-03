<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OwnerProjectsPortfolioSeeder extends Seeder
{
    /**
     * Seed owner-identified public repository projects and the CV's private,
     * reviewable government/enterprise portfolio. Never overwrite admin edits.
     * Only README paths fetched from existing PUBLIC owner repos are pre-approved
     * under the owner's explicit site publication instruction (2026-11-03).
     */
    public function run(): void
    {
        $path = __DIR__.'/owner-projects-portfolio.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 'walidsite.owner-projects.v1' || count($data['projects'] ?? []) !== 40) {
            throw new RuntimeException('Unexpected owner project catalog or missing project source data.');
        }

        $publicRepositories = [
            'FOOD', 'AIWM', 'AIMWWeb', 'WalidSite',
            'contractflow', '3fareet', 'MAM',
        ];
        DB::transaction(function () use ($data, $publicRepositories): void {
            foreach ($data['projects'] as $row) {
                $repo = $row['repo'];
                $hasPublicReadme = is_string($repo) && in_array($repo, $publicRepositories, true);
                $project = Project::query()->firstOrCreate(['slug' => $row['slug']], [
                    'title' => $row['title'],
                    'summary_en' => $row['summary_en'],
                    'summary_ar' => $row['summary_ar'],
                    'role_en' => $row['role_en'],
                    'role_ar' => $row['role_ar'],
                    'status' => $row['status'],
                    'portfolio_category' => $row['category'],
                    'portfolio_source' => $row['source'],
                    'portfolio_featured' => $row['featured'],
                    'visibility' => $hasPublicReadme ? 'public' : 'private',
                    'published_at' => $hasPublicReadme ? now('UTC') : null,
                ]);

                // Seed only a source that is actually public and allowed by
                // PublicPortfolioController's HTTPS safety gate. Never treat
                // a GitHub repository URL as verified project evidence.
                // CV-only and government projects remain PRIVATE until owner
                // evidence approval and explicit project publication.
                if ($hasPublicReadme && $project->wasRecentlyCreated) {
                    ProjectEvidence::query()->create([
                        'project_id' => $project->id,
                        'evidence_type' => 'public_document',
                        'url' => 'https://raw.githubusercontent.com/walidatiyaai2025-gif/'.$repo.'/main/README.md',
                        'visibility' => 'public',
                        'verified_at' => now('UTC'),
                    ]);
                }
            }
        }, 3);
    }
}

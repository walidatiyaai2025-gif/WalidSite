<?php

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class T012VisualCaptureSeeder extends Seeder
{
    private const REPOSITORY = 'walidatiyaai2025-gif/WalidSite';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('T012 visual fixture is testing-only.');
        }

        $sha = (string) getenv('GITHUB_SHA');
        if (! preg_match('/\A[0-9a-f]{40}\z/D', $sha)) {
            throw new RuntimeException('T012 visual fixture requires the checked-out GitHub SHA.');
        }

        $commit = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'WalidSite-T012-visual-fixture'])
            ->connectTimeout(5)->timeout(10)
            ->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.$sha);
        if (! $commit->successful() || $commit->json('sha') !== $sha) {
            throw new RuntimeException('T012 visual fixture requires the exact public renderer commit.');
        }

        // Deterministic, non-personal technical fixtures only. These records
        // exist solely in the throwaway testing database used by screenshot QA.
        $this->article(
            'visual-blog-sql-alwayson',
            'SQL AlwaysOn',
            'SQL AlwaysOn',
            "High availability troubleshooting\n\nVerified test-only technical fixture.",
            1
        );
        $this->article(
            'visual-blog-flutter-android',
            'Flutter & Android',
            'Flutter & Android',
            "Build and integration guides\n\nVerified test-only technical fixture.",
            2
        );
        $this->article(
            'visual-blog-ai-agents',
            'AI Agents',
            'AI Agents',
            "Safe software delivery workflows\n\nVerified test-only technical fixture.",
            3
        );

        $this->article(
            'visual-article-sql-failover',
            'Incident: SQL AlwaysOn failover troubleshooting',
            'Incident: SQL AlwaysOn failover troubleshooting',
            "## Environment\n\n## Troubleshooting\n\n## Resolution\n\n## Validation\n\n## Updated / Sources\n\n"
            ."~~~sql\nSELECT replica_server_name, synchronization_state_desc\nFROM sys.dm_hadr_database_replica_states;\n~~~\n",
            10
        );

        $this->article(
            'visual-search-sql-ha',
            'SQL Server / HA',
            'SQL Server / HA',
            "Summary, evidence, references\n\nvisualsearch",
            20
        );
        $this->article(
            'visual-search-flutter',
            'Flutter build issues',
            'Flutter build issues',
            "Summary, evidence, references\n\nvisualsearch",
            21
        );
        $this->article(
            'visual-search-ai',
            'AI agent workflows',
            'AI agent workflows',
            "Summary, evidence, references\n\nvisualsearch",
            22
        );

        $this->runbook('01-sql-diagnostics', 'SQL Diagnostics', '# Structured troubleshooting');
        $this->runbook('02-prompt-library', 'Prompt Library', '# Validated technical recipes');
        $this->runbook('03-runbook-builder', 'Runbook Builder', '# Guided incidents and checks');
    }

    private function article(string $slug, string $titleEn, string $titleAr, string $body, int $ageMinutes): void
    {
        $article = Article::query()->create([
            'slug' => $slug,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => $body,
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://example.org/t012-visual-fixture',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();
        $article->forceFill(['published_at' => now()->subMinutes($ageMinutes)])->save();
    }

    private function runbook(string $slug, string $title, string $body): void
    {
        $id = (string) Str::uuid();
        $created = now()->subMinutes(5);
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'version' => 1,
            'validated_at' => now()->subMinutes(2),
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => $created,
        ]);
    }
}

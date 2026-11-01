<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    private function reviewedArticle(string $slug, string $body): Article
    {
        $article = Article::create([
            'slug' => 'literal-article-'.$slug,
            'title_en' => 'Reviewed technical '.$slug.' procedure',
            'title_ar' => 'دليل تقني موثق '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => $body,
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/technical-evidence',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();

        return $article;
    }

    private function verifiedProject(string $slug, string $summary, bool $valid = true): Project
    {
        $project = Project::create([
            'slug' => 'literal-project-'.$slug,
            'title' => 'Verified '.$slug.' project',
            'summary_en' => $summary,
            'summary_ar' => 'معلومات عامة موثقة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $valid
                ? 'https://docs.example.org/technical-evidence'
                : 'https://docs.example.org/internal?token=private',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        return $project;
    }

    public function test_blog_and_site_search_match_literal_symbols_only_in_pinned_public_bodies(): void
    {
        $samples = [
            'percent' => ['symbol' => '%', 'body' => 'Verified recovery at 50% completeness.'],
            'underscore' => ['symbol' => '_', 'body' => 'Run backup_checkpoint on the approved host.'],
            'bang' => ['symbol' => '!', 'body' => 'Check operator alert! after maintenance.'],
            'backslash' => ['symbol' => '\\', 'body' => 'Restore from C:\\backups on Windows.'],
        ];
        foreach ($samples as $key => $item) {
            $this->reviewedArticle($key, $item['body']);
        }
        $this->reviewedArticle('clean', 'No punctuation marker in this reviewed document.');
        $private = Article::create([
            'slug' => 'private-literal-draft',
            'title_en' => 'Private 80% development milestone',
            'title_ar' => 'مسودة خاصة',
            'canonical_lang' => 'en',
        ]);
        $this->assertSame('draft', $private->status);

        foreach ($samples as $key => $item) {
            $query = urlencode($item['symbol']);
            foreach (['/blog?lang=en&q=', '/blog?lang=ar&q=', '/search?lang=en&q=', '/search?lang=ar&q='] as $path) {
                $response = $this->get($path.$query)->assertOk()
                    ->assertSee('literal-article-'.$key)
                    ->assertDontSee('literal-article-clean')
                    ->assertDontSee('private-literal-draft');
                foreach (array_keys($samples) as $other) {
                    if ($other !== $key) {
                        $response->assertDontSee('literal-article-'.$other);
                    }
                }
            }
        }

        // A previously approved body's publication flags are not enough
        // once its editorial evidence is revoked.
        $revoked = $this->reviewedArticle('revoked-percentage', 'Source approved 100% today.');
        $revoked->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false, 'technical_passed' => true,
            ],
        ]);
        $this->get('/blog?q=%25')->assertOk()
            ->assertSee('literal-article-percent')
            ->assertDontSee('literal-article-revoked-percentage');
        $this->get('/search?q=%25')->assertOk()
            ->assertDontSee('literal-article-revoked-percentage');
    }

    public function test_project_and_site_search_match_literal_symbols_in_safe_public_summaries(): void
    {
        $samples = [
            'percent' => ['symbol' => '%', 'summary' => 'Documented migration completed 90%.'],
            'underscore' => ['symbol' => '_', 'summary' => 'Verified db_restore checkpoints.'],
            'bang' => ['symbol' => '!', 'summary' => 'Service recovered! All checks complete.'],
            'backslash' => ['symbol' => '\\', 'summary' => 'Verified restore from D:\\snapshots.'],
        ];
        foreach ($samples as $key => $item) {
            $this->verifiedProject($key, $item['summary']);
        }
        $this->verifiedProject('clean', 'Project with routine verified documentation.');
        $this->verifiedProject('revoked', 'Unusable 75% confidential records.', false);

        foreach ($samples as $key => $item) {
            $query = urlencode($item['symbol']);
            foreach (['/projects?lang=en&q=', '/projects?lang=ar&q=', '/search?lang=en&q=', '/search?lang=ar&q='] as $path) {
                $response = $this->get($path.$query)->assertOk()
                    ->assertSee('literal-project-'.$key)
                    ->assertDontSee('literal-project-clean')
                    ->assertDontSee('literal-project-revoked')
                    ->assertDontSee('token=private');
                foreach (array_keys($samples) as $other) {
                    if ($other !== $key) {
                        $response->assertDontSee('literal-project-'.$other);
                    }
                }
            }
        }
    }
}

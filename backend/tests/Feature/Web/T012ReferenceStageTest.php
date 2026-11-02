<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class T012ReferenceStageTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, string $title, string $body, bool $publish = true): Article
    {
        $article = Article::query()->create([
            'slug' => $slug,
            'title_en' => $title,
            'title_ar' => $title,
            'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => $body,
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://example.org/reference-stage-test',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        if ($publish) {
            $article->publishVerified();
        }

        return $article->fresh();
    }

    private function runbook(string $slug, string $title, bool $approved = true): string
    {
        $id = (string) Str::uuid();
        $body = '# '.$title;
        $createdAt = now()->subMinutes(5);
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'version' => 1,
            'validated_at' => $approved ? now()->subMinutes(2) : null,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => $createdAt,
        ]);

        return $id;
    }

    public function test_blog_reference_stage_uses_only_live_verified_articles(): void
    {
        $live = $this->article(
            'safe-reference-card',
            'Safe real article card',
            'Actual reviewed summary'
        );
        $draft = $this->article(
            'private-reference-card',
            'Private draft must not render',
            'PRIVATE BODY',
            false
        );

        $page = $this->get('/blog?lang=en')->assertOk()
            ->assertSee('class="t012-reference-stage"', false)
            ->assertSee($live->title_en)
            ->assertSee('Actual reviewed summary')
            ->assertDontSee($draft->title_en)
            ->assertDontSee('SQL AlwaysOn');

        $live->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->get('/blog?lang=en')->assertOk()
            ->assertDontSee($live->title_en)
            ->assertDontSee($draft->title_en);
    }

    public function test_article_reference_stage_derives_heading_and_code_from_published_revision(): void
    {
        $article = $this->article(
            'derived-article-stage',
            'Derived article title',
            "## Custom environment\n\n## Custom validation\n\n"
            ."~~~sql\nSELECT 'derived-only';\n~~~\n"
        );

        $this->get('/blog/'.$article->slug.'?lang=en')->assertOk()
            ->assertSee('Derived article title')
            ->assertSee('Custom environment')
            ->assertSee('Custom validation')
            ->assertSee("SELECT 'derived-only';")
            ->assertDontSee('SELECT replica_server_name')
            ->assertSee('href="#sources-title"', false);
    }

    public function test_lab_reference_cards_are_version_reviewed_runbooks_not_static_mock_data(): void
    {
        $approved = $this->runbook('approved-reference-runbook', 'Approved runtime runbook');
        $draft = $this->runbook('draft-reference-runbook', 'Draft runbook must stay hidden', false);

        $page = $this->get('/lab?lang=en')->assertOk()
            ->assertSee('Approved runtime runbook')
            ->assertDontSee('Draft runbook must stay hidden')
            ->assertDontSee('SQL Diagnostics');

        DB::table('runbook_versions')->where('runbook_id', $approved)
            ->update(['checksum' => str_repeat('0', 64)]);
        $this->get('/lab?lang=en')->assertOk()
            ->assertDontSee('Approved runtime runbook')
            ->assertDontSee('Draft runbook must stay hidden');

        $this->get('/lab/runbooks/'.$draft)->assertNotFound();
    }

    public function test_search_reference_cards_follow_real_query_results_only(): void
    {
        $matching = $this->article(
            'matching-reference-search',
            'Actual search result',
            'needle-reference-stage'
        );
        $other = $this->article(
            'other-reference-search',
            'Unrelated approved article',
            'different phrase'
        );

        $this->get('/search?lang=en&q=needle-reference-stage&type=articles')->assertOk()
            ->assertSee('Actual search result')
            ->assertDontSee('Unrelated approved article')
            ->assertDontSee('SQL Server / HA');

        $matching->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->get('/search?lang=en&q=needle-reference-stage&type=articles')->assertOk()
            ->assertDontSee('Actual search result')
            ->assertDontSee('Unrelated approved article');
    }
}

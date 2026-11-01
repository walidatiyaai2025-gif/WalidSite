<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileHomeFeedTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Reviewed article '.$slug,
            'title_ar' => 'مقال موثق '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Reviewed technical instructions',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/verified',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true, 'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();

        return $article;
    }

    private function project(string $slug): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Reviewed project '.$slug,
            'summary_en' => 'Documented engineering result',
            'summary_ar' => 'نتيجة هندسية موثقة',
            'role_en' => 'Engineering',
            'role_ar' => 'هندسة',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $project->evidence()->create([
            'evidence_type' => 'public_document',
            'url' => 'https://docs.example.org/project-evidence',
            'visibility' => 'public',
            'verified_at' => now()->subHours(2),
        ]);

        return $project;
    }

    private function runbook(string $slug): string
    {
        $id = (string) Str::uuid();
        $body = '# Reviewed recovery steps '.$slug;
        $title = 'Reviewed runbook '.$slug;
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'version' => 1,
            'offline_ok' => true,
            'validated_at' => now()->subMinutes(5),
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => now()->subMinutes(6),
        ]);

        return $id;
    }

    public function test_guest_mobile_home_is_empty_and_honest_before_publication(): void
    {
        Http::fake();

        foreach (['en', 'ar'] as $lang) {
            $response = $this->getJson('/api/v1/mobile/home?lang='.$lang)->assertOk()
                ->assertJsonPath('data.profile', null)
                ->assertJsonCount(0, 'data.featured_projects')
                ->assertJsonCount(0, 'data.reading_feed')
                ->assertJsonCount(0, 'data.offline_runbooks')
                ->assertJsonCount(0, 'data.tools')
                ->assertJsonPath('data.saved_requires_auth', true)
                ->assertJsonPath('meta.lang', $lang)
                ->assertJsonMissingPath('data.saved')
                ->assertJsonMissingPath('data.saved_count');
            $this->assertStringContainsString('no-store',
                (string) $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
    }

    public function test_guest_mobile_home_reuses_identical_verified_public_catalogs_in_both_locales(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->project(sprintf('project-%02d', $i));
        }
        for ($i = 1; $i <= 16; $i++) {
            $article = $this->article(sprintf('article-%02d', $i));
            // The API sorts by publication timestamp before UUID. Distinct
            // timestamps make this test's expected article-01 ordering
            // deterministic across SQLite, MariaDB and CI runs.
            $article->forceFill(['published_at' => now()->subMinutes($i)])->save();
        }
        for ($i = 1; $i <= 17; $i++) {
            $this->runbook(sprintf('runbook-%02d', $i));
        }

        Http::fake();
        foreach (['en', 'ar'] as $lang) {
            $mobile = $this->getJson('/api/v1/mobile/home?lang='.$lang)->assertOk()
                ->assertJsonCount(3, 'data.featured_projects')
                ->assertJsonCount(15, 'data.reading_feed')
                ->assertJsonCount(15, 'data.offline_runbooks')
                ->assertJsonCount(0, 'data.tools')
                ->assertJsonPath('data.saved_requires_auth', true)
                ->assertJsonPath('meta.lang', $lang);

            // The app must not acquire a parallel weaker publication policy.
            $home = $this->getJson('/api/v1/public/home?lang='.$lang)->assertOk();
            $lab = $this->getJson('/api/v1/public/tools?lang='.$lang)->assertOk();
            $this->assertSame($home->json('data.profile'), $mobile->json('data.profile'));
            $this->assertSame($home->json('data.featured_projects'), $mobile->json('data.featured_projects'));
            $this->assertSame($lab->json('data.articles'), $mobile->json('data.reading_feed'));
            $this->assertSame($lab->json('data.runbooks'), $mobile->json('data.offline_runbooks'));
            $mobile->assertJsonPath('data.reading_feed.0.title',
                ($lang === 'ar' ? 'مقال موثق ' : 'Reviewed article ').'article-01')
                ->assertJsonPath('data.offline_runbooks.0.title', 'Reviewed runbook runbook-01')
                ->assertJsonMissingPath('data.offline_runbooks.0.checksum');
        }
        Http::assertNothingSent();
    }

    public function test_draft_and_revoked_publications_disappear_on_next_mobile_home_read(): void
    {
        $safeArticle = $this->article('safe-article');
        $safeProject = $this->project('safe-project');
        $safeGuide = $this->runbook('safe-runbook');
        $draft = Article::create([
            'slug' => 'private-draft',
            'title_en' => 'PRIVATE DRAFT',
            'title_ar' => 'مسودة خاصة',
            'canonical_lang' => 'en',
        ]);

        $this->getJson('/api/v1/mobile/home')->assertOk()
            ->assertJsonCount(1, 'data.featured_projects')
            ->assertJsonCount(1, 'data.reading_feed')
            ->assertJsonCount(1, 'data.offline_runbooks')
            ->assertDontSee($draft->slug);

        $safeArticle->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false, 'technical_passed' => true,
            ],
        ]);
        $safeProject->evidence()->update([
            'url' => 'https://docs.example.org/private?token=synthetic-secret',
        ]);
        DB::table('runbooks')->where('id', $safeGuide)
            ->update(['title' => 'Unreviewed private guide heading']);

        Http::fake();
        $response = $this->getJson('/api/v1/mobile/home')->assertOk()
            ->assertJsonCount(0, 'data.featured_projects')
            ->assertJsonCount(0, 'data.reading_feed')
            ->assertJsonCount(0, 'data.offline_runbooks')
            ->assertDontSee('synthetic-secret')
            ->assertDontSee('Unreviewed private guide heading')
            ->assertDontSee('PRIVATE DRAFT')
            ->assertDontSee($safeGuide);
        Http::assertNothingSent();
    }

    public function test_invalid_guest_home_queries_are_rejected_without_network_or_private_data(): void
    {
        Http::fake();
        foreach ([
            '/api/v1/mobile/home?lang=fr',
            '/api/v1/mobile/home?lang[]=ar',
            '/api/v1/mobile/home?page=2',
            '/api/v1/mobile/home?page[]=1',
        ] as $uri) {
            $this->getJson($uri)->assertUnprocessable();
        }
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicLabCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function publishArticle(string $slug): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Reviewed guide '.$slug,
            'title_ar' => 'دليل موثق '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Verified, cited procedure',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/recovery',
                'verified' => true,
            ]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();

        return $article;
    }

    private function publishRunbook(string $slug, bool $approved = true): string
    {
        $id = (string) Str::uuid();
        $body = '# Reviewed offline step '.$slug;
        $title = 'Verified runbook '.$slug;
        DB::table('runbooks')->insert([
            'id' => $id, 'slug' => $slug, 'title' => $title,
            'body_md' => $body, 'version' => 1,
            'validated_at' => $approved ? now()->subMinutes(5) : null,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(), 'runbook_id' => $id,
            'version' => 1, 'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => now()->subMinutes(6),
        ]);

        return $id;
    }

    public function test_empty_lab_returns_honest_resources_and_never_calls_network(): void
    {
        Http::fake();
        $response = $this->getJson('/api/v1/public/tools')->assertOk()
            ->assertJsonCount(0, 'data.tools')
            ->assertJsonCount(0, 'data.articles')
            ->assertJsonCount(0, 'data.runbooks')
            ->assertJsonPath('meta.lang', 'en');
        // Symfony appends the private directive to no-store during response
        // preparation. Both directives are required, regardless of ordering.
        $this->assertStringContainsString('no-store',
            (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private',
            (string) $response->headers->get('Cache-Control'));
        $this->assertSame(
            ['tools', 'articles', 'runbooks'],
            array_keys($response->json('data'))
        );
        Http::assertNothingSent();
    }

    public function test_lab_bilingual_articles_filter_revoked_pins_before_fifteen_result_cap(): void
    {
        for ($i = 1; $i <= 17; $i++) {
            $article = $this->publishArticle(sprintf('a-revoked-%02d', $i));
            DB::table('article_revisions')->where('article_id', $article->id)->update([
                'validation_json' => json_encode([
                    'citations_passed' => false, 'technical_passed' => true,
                ], JSON_THROW_ON_ERROR),
            ]);
            $article->forceFill(['published_at' => now()->addMinutes(-1)])->save();
        }

        $expected = [];
        for ($i = 1; $i <= 16; $i++) {
            $article = $this->publishArticle(sprintf('z-reviewed-%02d', $i));
            $article->forceFill(['published_at' => now()->subDay()])->save();
            $expected[] = $article->slug;
        }

        Http::fake();
        $en = $this->getJson('/api/v1/public/tools?lang=en')->assertOk()
            ->assertJsonCount(15, 'data.articles')
            ->assertJsonPath('data.articles.0.title', 'Reviewed guide z-reviewed-01')
            ->assertJsonPath('meta.lang', 'en')
            ->assertDontSee('a-revoked-');
        $ar = $this->getJson('/api/v1/public/tools?lang=ar')->assertOk()
            ->assertJsonCount(15, 'data.articles')
            ->assertJsonPath('data.articles.0.title', 'دليل موثق z-reviewed-01')
            ->assertJsonPath('meta.lang', 'ar')
            ->assertDontSee('a-revoked-');

        $this->assertSame(array_slice($expected, 0, 15),
            array_column($en->json('data.articles'), 'slug'));
        $this->assertSame(array_slice($expected, 0, 15),
            array_column($ar->json('data.articles'), 'slug'));
        $en->assertJsonPath('data.articles.0.url',
            route('public.article', ['slug' => $expected[0], 'lang' => 'en']));
        $ar->assertJsonPath('data.articles.0.url',
            route('public.article', ['slug' => $expected[0], 'lang' => 'ar']));
        $en->assertJsonMissingPath('data.articles.0.body_markdown');
        $en->assertJsonMissingPath('data.articles.0.citations');
        Http::assertNothingSent();
    }

    public function test_citation_revocation_removes_an_article_immediately(): void
    {
        $article = $this->publishArticle('revocable-lab-resource');
        $this->getJson('/api/v1/public/tools')->assertOk()
            ->assertJsonCount(1, 'data.articles');

        DB::table('article_revisions')->where('article_id', $article->id)
            ->update(['citations_json' => json_encode([[
                'source_url' => 'https://docs.example.org/recovery?token=private',
                'verified' => true,
            ]], JSON_THROW_ON_ERROR)]);
        $this->getJson('/api/v1/public/tools')->assertOk()
            ->assertJsonCount(0, 'data.articles')->assertDontSee('token=private');
    }

    public function test_lab_only_exposes_version_approved_runbooks_and_caps_fifteen(): void
    {
        $draft = $this->publishRunbook('unapproved', approved: false);
        $tampered = $this->publishRunbook('tampered');
        DB::table('runbook_versions')->where('runbook_id', $tampered)
            ->update(['checksum' => str_repeat('0', 64)]);
        $expected = [];
        for ($i = 1; $i <= 17; $i++) {
            $expected[] = $this->publishRunbook(sprintf('verified-%02d', $i));
        }

        Http::fake();
        $res = $this->getJson('/api/v1/public/tools')->assertOk()
            ->assertJsonCount(15, 'data.runbooks')
            ->assertJsonCount(0, 'data.tools')
            ->assertJsonCount(0, 'data.articles')
            ->assertDontSee($draft)->assertDontSee($tampered);

        $this->assertSame(array_slice($expected, 0, 15),
            array_column($res->json('data.runbooks'), 'id'));
        $res->assertJsonPath('data.runbooks.0.version', 1)
            ->assertJsonMissingPath('data.runbooks.0.checksum')
            ->assertJsonMissingPath('data.runbooks.0.slug');
        Http::assertNothingSent();
    }

    public function test_invalid_locale_and_page_are_rejected_without_exposing_private_state(): void
    {
        foreach ([
            '/api/v1/public/tools?lang=fr',
            '/api/v1/public/tools?lang[]=ar',
            '/api/v1/public/tools?page=2',
            '/api/v1/public/tools?page[]=1',
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
    }
}

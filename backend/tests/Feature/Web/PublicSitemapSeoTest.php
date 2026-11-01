<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSitemapSeoTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, bool $publish = true): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Verified bilingual guidance',
            'title_ar' => 'إرشادات تقنية موثقة',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Source-backed operational guidance.',
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
        if ($publish) {
            $article->publishVerified();
        }

        return $article;
    }

    private function project(string $slug, string $visibility = 'public', string $url = 'https://docs.example.org/proof'): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Verified public case',
            'summary_en' => 'Evidence-backed public information',
            'summary_ar' => 'معلومات عامة موثقة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        return $project;
    }

    public function test_xml_sitemap_only_exposes_verified_public_articles_and_projects(): void
    {
        $visibleArticle = $this->article('public-evidence-guide');
        $draftArticle = $this->article('private-draft-guide', false);
        $revokedArticle = $this->article('revoked-citation-guide');
        $revokedArticle->revisions()->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);

        $visibleProject = $this->project('verified-case-study');
        $privateProject = $this->project('confidential-case-study', 'private');
        $unsafeProject = $this->project('unsafe-evidence-case', 'public', 'https://localhost/private');

        $response = $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('xmlns:xhtml="http://www.w3.org/1999/xhtml"', false);

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'Sitemap must remain valid UTF-8 XML.');
        $this->assertSame('urlset', $xml->getName());

        foreach (['en', 'ar'] as $language) {
            $response->assertSee(e(route('public.article', [
                'slug' => $visibleArticle->slug, 'lang' => $language,
            ])), false);
            $response->assertSee(e(route('public.project', [
                'slug' => $visibleProject->slug, 'lang' => $language,
            ])), false);
        }

        foreach ([
            $draftArticle->slug, $revokedArticle->slug,
            $privateProject->slug, $unsafeProject->slug,
        ] as $hiddenSlug) {
            $response->assertDontSee($hiddenSlug);
        }
        $response->assertDontSee('/admin/')
            ->assertDontSee('/search?');
    }

    public function test_revoking_approved_evidence_removes_urls_from_subsequent_sitemaps(): void
    {
        $article = $this->article('lose-citations');
        $project = $this->project('lose-evidence');

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('lose-citations')
            ->assertSee('lose-evidence');

        $article->revisions()->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);
        $project->evidence()->firstOrFail()->update(['verified_at' => null]);

        $this->get('/sitemap.xml')->assertOk()
            ->assertDontSee('lose-citations')
            ->assertDontSee('lose-evidence');
    }

    public function test_canonical_and_alternates_never_copy_untrusted_query_parameters(): void
    {
        $article = $this->article('bilingual-safe-article');

        $this->get('/blog/'.$article->slug.'?lang=ar&foo=secrets-canary')->assertOk()
            ->assertSee('href="'.e(route('public.article', [
                'slug' => $article->slug, 'lang' => 'ar',
            ])).'"', false)
            ->assertSee('hreflang="en"', false)
            ->assertDontSee('secrets-canary');

        $this->get('/blog?lang=ar&q=secrets-canary')->assertOk()
            ->assertSee('href="'.e(route('public.blog', ['lang' => 'ar'])).'"', false)
            ->assertDontSee('href="'.e(route('public.blog', [
                'lang' => 'en', 'q' => 'secrets-canary',
            ])).'"', false);

        $this->get('/search?lang=ar&q=secrets-canary')->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertDontSee('<link rel="canonical"', false);

        $this->get('/projects/'.$article->slug)->assertNotFound();
    }

    public function test_verified_case_study_has_locale_specific_canonical_and_sitemap_discovery(): void
    {
        $project = $this->project('public-case-for-seo');
        $this->get('/projects/'.$project->slug.'?lang=ar&ref=secrets-canary')->assertOk()
            ->assertSee('hreflang="ar"', false)
            ->assertSee('href="'.e(route('public.project', [
                'slug' => $project->slug, 'lang' => 'ar',
            ])).'"', false)
            ->assertDontSee('secrets-canary')
            ->assertSee('rel="sitemap"', false);
    }
}

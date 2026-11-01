<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifiedArticleStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_article_jsonld_uses_only_the_pinned_verified_revision_in_both_locales(): void
    {
        // A reviewed title must never break out of the JSON script context.
        $maliciousTitle = 'Reviewed guide </script><script>alert("x")</script>';
        $article = Article::create([
            'slug' => 'structured-verified-guide',
            'title_en' => $maliciousTitle,
            'title_ar' => 'دليل تقني منشور وموثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Published safe guide',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/verified-guidance',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        $article->publishVerified();

        // Subsequent drafts cannot alter publication metadata or citations.
        $article->revisions()->create([
            'revision_no' => 2,
            'body_md' => 'PRIVATE-DRAFT-UNREVIEWED-CONTENT',
            'status' => 'draft',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/private-draft',
                'verified' => false,
            ]],
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => false,
            ],
        ]);

        foreach (['en' => $maliciousTitle, 'ar' => 'دليل تقني منشور وموثق'] as $locale => $headline) {
            $page = $this->get(route('public.article', [
                'slug' => $article->slug,
                'lang' => $locale,
            ]))->assertOk()
                ->assertDontSee('PRIVATE-DRAFT-UNREVIEWED-CONTENT')
                ->assertDontSee('https://docs.example.org/private-draft');

            $markup = $page->getContent();
            $this->assertSame(1, preg_match(
                '~<script type="application/ld\+json">([^<]*)</script>~s',
                $markup,
                $matches
            ));
            $data = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('https://schema.org', $data['@context']);
            $this->assertSame('TechArticle', $data['@type']);
            $this->assertSame($headline, $data['headline']);
            $this->assertSame($locale, $data['inLanguage']);
            $this->assertSame(route('public.article', [
                'slug' => $article->slug,
                'lang' => $locale,
            ]), $data['mainEntityOfPage']);
            $this->assertSame($article->published_at->toIso8601String(), $data['datePublished']);
            $this->assertSame(['https://docs.example.org/verified-guidance'], $data['citation']);
            $this->assertArrayNotHasKey('author', $data);
            $this->assertArrayNotHasKey('articleBody', $data);
            $this->assertStringNotContainsString('</script><script>alert', $markup);
            $this->assertStringNotContainsString('PRIVATE-DRAFT-UNREVIEWED-CONTENT', $markup);
        }

        // Withdrawing the citation gate removes the entire public page,
        // including its structured data, even if the SQL row remains published.
        $article->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->get('/blog/'.$article->slug.'?lang=en')->assertNotFound();
        $this->get('/blog/'.$article->slug.'?lang=ar')->assertNotFound();
    }
}

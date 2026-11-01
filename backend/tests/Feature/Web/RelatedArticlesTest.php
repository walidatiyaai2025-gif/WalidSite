<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatedArticlesTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $slug, bool $published = true): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Article '.$slug,
            'title_ar' => 'مقال '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Evidence-backed guide',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://example.org/documentation',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);

        if ($published) {
            $article->publishVerified();
        }

        return $article;
    }

    private function category(string $slug): Category
    {
        return Category::create([
            'slug' => $slug,
            'name_en' => ucfirst($slug),
            'name_ar' => 'تصنيف '.$slug,
        ]);
    }

    public function test_no_related_articles_section_without_verified_matches(): void
    {
        $source = $this->article('primary-guide');
        $this->get('/blog/'.$source->slug)->assertOk()
            ->assertDontSee('id="related-articles-title"', false);

        $source->categories()->attach($this->category('databases')->id);
        $this->get('/blog/'.$source->slug.'?lang=ar')->assertOk()
            ->assertDontSee('id="related-articles-title"', false);
    }

    public function test_related_articles_are_bilingual_and_exclude_drafts_revoked_and_other_categories(): void
    {
        $database = $this->category('databases');
        $otherCategory = $this->category('networking');
        $source = $this->article('source-guide');
        $source->categories()->attach($database->id);

        $good = $this->article('verified-related');
        $good->categories()->attach($database->id);

        $draft = $this->article('private-draft', false);
        $draft->categories()->attach($database->id);

        $revoked = $this->article('revoked-article');
        $revoked->categories()->attach($database->id);
        $revoked->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);

        $unrelated = $this->article('unrelated-public');
        $unrelated->categories()->attach($otherCategory->id);

        $en = $this->get('/blog/source-guide?lang=en')->assertOk()
            ->assertSee('Related articles')
            ->assertSee('Article verified-related')
            ->assertSee('href="'.e(route('public.article', [
                'slug' => $good->slug, 'lang' => 'en',
            ])).'"', false)
            ->assertDontSee('Article private-draft')
            ->assertDontSee('Article revoked-article')
            ->assertDontSee('Article unrelated-public');
        $this->assertSame(1, substr_count($en->getContent(), 'class="article-related-link"'));

        $ar = $this->get('/blog/source-guide?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مقالات ذات صلة')
            ->assertSee('مقال verified-related')
            ->assertSee('href="'.e(route('public.article', [
                'slug' => $good->slug, 'lang' => 'ar',
            ])).'"', false)
            ->assertDontSee('مقال private-draft')
            ->assertDontSee('مقال revoked-article');
        $this->assertSame(1, substr_count($ar->getContent(), 'class="article-related-link"'));
    }

    public function test_related_article_links_are_capped_at_three_and_sorted_by_publication_time(): void
    {
        $category = $this->category('technical-guides');
        $source = $this->article('source-guide');
        $source->categories()->attach($category->id);

        for ($number = 1; $number <= 4; $number++) {
            $related = $this->article('related-guide-'.$number);
            $related->categories()->attach($category->id);
            $related->forceFill(['published_at' => now()->subMinutes(10 - $number)])->save();
        }

        $response = $this->get('/blog/source-guide?lang=en')->assertOk()
            ->assertSee('Article related-guide-4')
            ->assertSee('Article related-guide-3')
            ->assertSee('Article related-guide-2')
            ->assertDontSee('Article related-guide-1');

        $this->assertSame(3, substr_count($response->getContent(), 'class="article-related-link"'));
    }

    public function test_related_articles_scan_past_more_than_twenty_four_revoked_neighbours(): void
    {
        $category = $this->category('verified-relations');
        $source = $this->article('source-late-neighbours');
        $source->categories()->attach($category->id);

        $safe = $this->article('related-after-revocations');
        $safe->categories()->attach($category->id);
        $safe->forceFill(['published_at' => now()->subDays(3)])->save();

        for ($i = 1; $i <= 27; $i++) {
            $bad = $this->article('related-revoked-'.$i);
            $bad->categories()->attach($category->id);
            $bad->revisions()->firstOrFail()->update([
                'validation_json' => [
                    'citations_passed' => false,
                    'technical_passed' => true,
                ],
            ]);
            $bad->forceFill(['published_at' => now()->subMinute()])->save();
        }

        $english = $this->get('/blog/source-late-neighbours?lang=en')->assertOk()
            ->assertSee('Article related-after-revocations')
            ->assertSee(e(route('public.article', [
                'slug' => $safe->slug, 'lang' => 'en',
            ])), false)
            ->assertDontSee('Article related-revoked-');
        $this->assertSame(1, substr_count($english->getContent(), 'class="article-related-link"'));

        $arabic = $this->get('/blog/source-late-neighbours?lang=ar')->assertOk()
            ->assertSee('مقال related-after-revocations')
            ->assertDontSee('مقال related-revoked-');
        $this->assertSame(1, substr_count($arabic->getContent(), 'class="article-related-link"'));
    }

}

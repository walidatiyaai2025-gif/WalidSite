<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicArticleSlugDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function published(string $slug): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Routeproof '.$slug,
            'title_ar' => 'دليل روابط '.$slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Published and source-reviewed routeproof guidance',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/routeproof',
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

    public function test_all_web_discovery_surfaces_exclude_verified_but_unroutable_slugs_before_limits(): void
    {
        $safe = $this->published('routable-guide');
        $safe->forceFill(['published_at' => now()->subDays(3)])->save();

        // Even more than 15 invalid public rows must not consume homepage,
        // blog, lab or search result slots ahead of the working destination.
        for ($i = 1; $i <= 18; $i++) {
            $bad = $this->published('unroutable_guide_'.$i);
            $bad->forceFill(['published_at' => now()->subMinute()])->save();
        }

        foreach (['en', 'ar'] as $lang) {
            foreach ([
                '/?lang='.$lang,
                '/blog?lang='.$lang,
                '/lab?lang='.$lang,
                '/search?q=Routeproof&lang='.$lang,
            ] as $uri) {
                $this->get($uri)->assertOk()
                    ->assertSee($lang === 'ar' ? 'دليل روابط routable-guide' : 'Routeproof routable-guide')
                    ->assertDontSee('unroutable_guide_')
                    ->assertSee(e(route('public.article', [
                        'slug' => $safe->slug, 'lang' => $lang,
                    ])), false);
            }
        }

        $this->get('/blog/unroutable_guide_1')->assertNotFound();
        $this->get('/blog/routable-guide?lang=ar')->assertOk()
            ->assertSee('دليل روابط routable-guide');
    }

    public function test_blog_categories_and_related_links_exclude_invalid_slugs_without_hiding_valid_neighbours(): void
    {
        $validCategory = Category::create([
            'slug' => 'routing', 'name_en' => 'Routable Topic',
            'name_ar' => 'تصنيف الروابط الصحيحة',
        ]);
        $invalidCategory = Category::create([
            'slug' => 'unroutable', 'name_en' => 'Unroutable Topic',
            'name_ar' => 'تصنيف الروابط المعطلة',
        ]);
        $source = $this->published('source-guide');
        $source->categories()->attach($validCategory->id);
        $related = $this->published('valid-related');
        $related->categories()->attach($validCategory->id);
        $unroutable = $this->published('bad_related');
        $unroutable->categories()->attach([$validCategory->id, $invalidCategory->id]);
        $unroutable->forceFill(['published_at' => now()->addMinute()])->save();

        $this->get('/blog?lang=en')->assertOk()
            ->assertSee('Routable Topic')->assertDontSee('Unroutable Topic')
            ->assertDontSee('Routeproof bad_related');
        $this->get('/blog?category=unroutable&lang=en')->assertOk()
            ->assertDontSee('Routeproof bad_related');
        foreach (['en', 'ar'] as $lang) {
            $this->get('/blog/source-guide?lang='.$lang)->assertOk()
                ->assertSee($lang === 'ar' ? 'دليل روابط valid-related' : 'Routeproof valid-related')
                ->assertDontSee('bad_related')
                ->assertSee(e(route('public.article', [
                    'slug' => $related->slug, 'lang' => $lang,
                ])), false);
        }
    }
}

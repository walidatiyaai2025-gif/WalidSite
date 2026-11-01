<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Article;
use Tests\TestCase;

class SearchEmptyGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_search_has_prompt_and_no_result_group_headings(): void
    {
        $this->get('/search?lang=en')->assertOk()
            ->assertSee('Enter a query to search published')
            ->assertDontSee('id="articles-h"', false)
            ->assertDontSee('id="projects-h"', false);
    }

    public function test_unmatched_query_has_no_empty_article_or_project_lists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $lang) {
            $this->get('/search?lang='.$lang.'&q=unmatched-unique-query-385')->assertOk()
                ->assertDontSee('id="articles-h"', false)
                ->assertDontSee('id="projects-h"', false)
                ->assertSee($lang === 'ar' ? 'لا توجد نتائج عامة موثقة' : 'No verified public matches');
        }
    }
    public function test_verified_article_result_announces_actual_counts_in_both_locales(): void
    {
        $article = Article::create([
            'slug' => 'verified-search-count',
            'title_en' => 'DistinctiveCountGuide',
            'title_ar' => 'دليل العد الموثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'status' => 'validated',
            'body_md' => 'Verified source-backed guide',
            'citations_json' => [['source_url' => 'https://example.org/source', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();

        $this->get('/search?lang=en&q=DistinctiveCountGuide')->assertOk()
            ->assertSee('Verified results: 1 article, 0 projects.')
            ->assertSee('id="articles-h"', false)
            ->assertDontSee('id="projects-h"', false);
        $this->get('/search?lang=ar&q=DistinctiveCountGuide')->assertOk()
            ->assertSee('نتائج موثقة: 1 مقال، 0 مشاريع.')
            ->assertSee('dir="rtl"', false);
    }

    public function test_two_verified_articles_use_arabic_dual_form(): void
    {
        foreach (['one', 'two'] as $suffix) {
            $article = Article::create([
                'slug' => 'dual-search-'.$suffix,
                'title_en' => 'DualSearchKeyword '.$suffix,
                'title_ar' => 'مقال بحث مثنى '.$suffix,
                'canonical_lang' => 'en',
            ]);
            $article->revisions()->create([
                'revision_no' => 1,
                'status' => 'validated',
                'body_md' => 'Verified source-backed guide',
                'citations_json' => [['source_url' => 'https://example.org/source', 'verified' => true]],
                'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            ]);
            $article->publishVerified();
        }

        $this->get('/search?lang=ar&q=DualSearchKeyword')->assertOk()
            ->assertSee('نتائج موثقة: 2 مقالان، 0 مشاريع.');
        $this->get('/search?lang=en&q=DualSearchKeyword')->assertOk()
            ->assertSee('Verified results: 2 articles, 0 projects.');
    }

    public function test_search_reset_preserves_locale_without_carrying_query(): void
    {
        foreach (['en' => 'Clear search', 'ar' => 'مسح البحث'] as $locale => $label) {
            $this->get('/search?lang='.$locale.'&q=unmatched-unique-query-399')->assertOk()
                ->assertSee($label)
                ->assertSee('href="'.route('public.search', ['lang' => $locale]).'"', false)
                ->assertDontSee('href="'.route('public.search', ['lang' => $locale, 'q' => 'unmatched-unique-query-399']).'"', false);

            $this->get('/search?lang='.$locale)->assertOk()
                ->assertDontSee('class="ed-clear"', false);
        }
    }

}

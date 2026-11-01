<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

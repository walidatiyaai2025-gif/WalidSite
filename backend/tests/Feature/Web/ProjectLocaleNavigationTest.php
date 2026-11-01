<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectLocaleNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_language_switch_keeps_search_and_allowlisted_status_in_both_directions(): void
    {
        $this->get('/projects?lang=ar&q=Cloud&status=research')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('href="'.e(route('public.projects', [
                'lang' => 'en', 'q' => 'Cloud', 'status' => 'research',
            ])).'"', false);

        $this->get('/projects?lang=en&q=Database&status=in_progress')->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('href="'.e(route('public.projects', [
                'lang' => 'ar', 'q' => 'Database', 'status' => 'in_progress',
            ])).'"', false);
    }

    public function test_untrusted_query_arrays_or_unknown_status_do_not_pollute_language_links(): void
    {
        foreach ([
            '/projects?lang=ar&q[]=hidden&status[]=research',
            '/projects?lang=ar&status=not_a_status',
            '/projects?lang=ar&q[private]=secret',
        ] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('href="'.e(route('public.projects', ['lang' => 'en'])).'"', false)
                ->assertDontSee('Array to string conversion');
        }
    }

    public function test_long_search_context_is_bounded_before_language_link_generation(): void
    {
        $search = str_repeat('A', 110);
        $this->get('/projects?lang=en&q='.$search.'&status=completed')->assertOk()
            ->assertSee('href="'.e(route('public.projects', [
                'lang' => 'ar', 'q' => str_repeat('A', 80), 'status' => 'completed',
            ])).'"', false)
            ->assertDontSee($search);
    }

    public function test_default_project_view_uses_clean_language_link(): void
    {
        $this->get('/projects')->assertOk()
            ->assertSee('href="'.e(route('public.projects', ['lang' => 'ar'])).'"', false);
    }
}

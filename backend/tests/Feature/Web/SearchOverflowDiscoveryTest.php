<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchOverflowDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilingual_search_preview_links_to_full_verified_article_and_project_results(): void
    {
        $term = 'Recovery & restore';

        for ($number = 1; $number <= 11; $number++) {
            $article = Article::create([
                'slug' => 'overflow-article-'.$number,
                'title_en' => $term.' article '.$number,
                'title_ar' => 'مقال استعادة موثق '.$number,
                'canonical_lang' => 'en',
            ]);
            $article->revisions()->create([
                'revision_no' => 1,
                'body_md' => 'Evidence-reviewed recovery steps.',
                'status' => 'validated',
                'citations_json' => [[
                    'source_url' => 'https://docs.example.org/recovery',
                    'verified' => true,
                ]],
                'validation_json' => [
                    'citations_passed' => true,
                    'technical_passed' => true,
                ],
            ]);
            $article->publishVerified();

            $project = Project::create([
                'slug' => 'overflow-project-'.$number,
                'title' => $term.' project '.$number,
                'summary_en' => 'Public recovery work with approved evidence.',
                'summary_ar' => 'عمل استعادة عام موثق بالأدلة.',
                'role_en' => 'Engineer',
                'role_ar' => 'مهندس',
                'status' => 'completed',
                'visibility' => 'public',
                'published_at' => now()->subDay(),
            ]);
            ProjectEvidence::create([
                'project_id' => $project->id,
                'evidence_type' => 'document',
                'url' => 'https://docs.example.org/recovery',
                'visibility' => 'public',
                'verified_at' => now()->subDay(),
            ]);
        }

        // Neither the preview nor the full result pages may expose records
        // that never passed publication or source-evidence checks.
        Article::create([
            'slug' => 'overflow-private-article',
            'title_en' => $term.' private unreviewed article',
            'title_ar' => 'مقال غير منشور',
            'canonical_lang' => 'en',
        ]);

        foreach (['en', 'ar'] as $locale) {
            $searchUrl = route('public.search', ['lang' => $locale, 'q' => $term]);
            $response = $this->get($searchUrl)->assertOk()
                ->assertSee('href="'.e(route('public.blog', [
                    'lang' => $locale, 'q' => $term,
                ])).'"', false)
                ->assertSee('href="'.e(route('public.projects', [
                    'lang' => $locale, 'q' => $term,
                ])).'"', false)
                ->assertDontSee('overflow-private-article');

            if ($locale === 'ar') {
                $response->assertSee('استعراض جميع المقالات المطابقة')
                    ->assertSee('استعراض جميع المشاريع المطابقة')
                    ->assertSee('تُعرض أول 10 نتائج موثقة');
            } else {
                $response->assertSee('Browse all matching articles')
                    ->assertSee('Browse all matching projects')
                    ->assertSee('Showing at most 10 verified matches per type.');
            }

            // Quick search intentionally remains bounded to ten valid rows.
            // The full filtered listings must not silently lose the 11th.
            $this->assertSame(10, substr_count($response->getContent(), '/blog/overflow-article-'));
            $this->assertSame(10, substr_count($response->getContent(), '/projects/overflow-project-'));

            $this->get(route('public.blog', [
                'lang' => $locale, 'q' => $term, 'page' => 2,
            ]))->assertOk()
                ->assertSee('overflow-article-11')
                ->assertDontSee('overflow-private-article');

            $this->get(route('public.projects', [
                'lang' => $locale, 'q' => $term, 'page' => 2,
            ]))->assertOk()->assertSee('overflow-project-11');
        }
    }
}

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
            // The blog orders by published_at DESC then UUID. Publishing
            // eleven fixtures within one clock second gives them random UUID
            // tie order, making "article-11 on page 2" intermittent.
            $article->forceFill([
                'published_at' => now()->subMinutes($number),
            ])->save();

            $project = Project::create([
                'slug' => 'overflow-project-'.$number,
                'title' => $term.' project '.$number,
                'summary_en' => 'Public recovery work with approved evidence.',
                'summary_ar' => 'عمل استعادة عام موثق بالأدلة.',
                'role_en' => 'Engineer',
                'role_ar' => 'مهندس',
                'status' => 'completed',
                'visibility' => 'public',
                // Deterministic newest-first ordering also matters for
                // the projects overflow assertion with UUID tie-breakers.
                'published_at' => now()->subDay()->subMinutes($number),
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

        // A public project without an approved evidence record cannot be
        // included in either the default or project-only result set.
        Project::create([
            'slug' => 'overflow-private-project',
            'title' => $term.' project without approved evidence',
            'summary_en' => 'Unverified portfolio work',
            'summary_ar' => 'مشروع غير موثق',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
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

            // WEB-13 content-type selector is server-enforced, not a
            // cosmetic client-side hide. The other model is not queried
            // into the preview, while full scoped result links stay valid.
            $articlesOnly = $this->get(route('public.search', [
                'lang' => $locale, 'q' => $term, 'type' => 'articles',
            ]))->assertOk()
                ->assertSee('value="articles" selected', false)
                ->assertSee('overflow-article-1')
                ->assertDontSee('overflow-project-')
                ->assertDontSee('overflow-private-article')
                ->assertSee('href="'.e(route('public.blog', [
                    'lang' => $locale, 'q' => $term,
                ])).'"', false);
            $this->assertSame(10, substr_count($articlesOnly->getContent(), '/blog/overflow-article-'));
            $articlesOnly->assertDontSee('Browse all matching projects');

            $projectsOnly = $this->get(route('public.search', [
                'lang' => $locale, 'q' => $term, 'type' => 'projects',
            ]))->assertOk()
                ->assertSee('value="projects" selected', false)
                ->assertSee('overflow-project-1')
                ->assertDontSee('overflow-article-')
                ->assertDontSee('overflow-private-project')
                ->assertSee('href="'.e(route('public.projects', [
                    'lang' => $locale, 'q' => $term,
                ])).'"', false);
            $this->assertSame(10, substr_count($projectsOnly->getContent(), '/projects/overflow-project-'));
            $projectsOnly->assertDontSee('Browse all matching articles');

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

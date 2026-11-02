<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleLocalReadingListTest extends TestCase
{
    use RefreshDatabase;

    private function approvedArticle(string $englishTitle = 'Reviewed failover guide'): Article
    {
        $article = Article::create([
            'slug' => 'reviewed-local-reading-guide',
            'title_en' => $englishTitle,
            'title_ar' => 'دليل تقني موثق للحفظ المحلي',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => "# Reviewed failover procedure\n\nA cited technical step.",
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/verified-failover',
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

    public function test_bilingual_approved_article_has_explicit_local_only_save_revisit_remove_and_clear_controls(): void
    {
        $article = $this->approvedArticle();
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get(route('public.article', [
                'slug' => $article->slug, 'lang' => $locale,
            ]))->assertOk()
                ->assertSee('id="reading-list-heading"', false)
                ->assertSee('id="article-save-toggle" type="button" aria-pressed="false" disabled', false)
                ->assertSee('id="article-reading-clear" type="button" disabled', false)
                ->assertSee('id="article-reading-links"', false)
                ->assertSee('id="article-reading-status"', false)
                ->assertSee('role="status" aria-live="polite" aria-atomic="true"', false)
                ->assertSee("const storageKey = 'walidsite:public-reading-list:v1'", false)
                ->assertSee('window.localStorage.setItem(storageKey, JSON.stringify(next))', false)
                ->assertSee("saved.some(item => item.slug === current.slug)", false)
                ->assertSee("saved.filter(item => item.slug !== current.slug)", false)
                ->assertSee('window.confirm(words.confirm)', false)
                ->assertSee('const limit = 30', false)
                ->assertSee('links.replaceChildren()', false)
                ->assertSee('anchor.textContent =', false)
                ->assertSee("articlePath.replace('reading-list-placeholder', encodeURIComponent(item.slug))", false)
                ->assertSee("button:focus-visible", false)
                ->assertSee('article-reading-list{display:none!important}', false);
            if ($locale === 'ar') {
                $page->assertSee('القراءة لاحقًا على هذا الجهاز')
                    ->assertSee('لا يُرسل سجل القراءة إلى الخادم')
                    ->assertSee('حفظ هذا المقال محليًا')
                    ->assertSee('حذف كل الروابط المحفوظة')
                    ->assertSee('dir="rtl"', false);
            } else {
                $page->assertSee('Read later on this device')
                    ->assertSee('not uploaded or synced with an account')
                    ->assertSee('Save this article locally')
                    ->assertSee('Clear saved links')
                    ->assertSee('dir="ltr"', false);
            }
            // The only target is the server-owned published article route.
            $page->assertSee('reading-list-placeholder', false)
                ->assertDontSee('localStorage.setItem("bearer"', false);
            $this->assertStringNotContainsString('Set-Cookie: saved',
                implode(' ', $page->headers->all('Set-Cookie')));
        }
    }

    public function test_unapproved_or_revoked_articles_never_become_public_through_local_saved_links(): void
    {
        $approved = $this->approvedArticle();
        $draft = Article::create([
            'slug' => 'private-draft-reading-link',
            'title_en' => 'PRIVATE-ARTICLE-NOT-FOR-READING-LIST',
            'title_ar' => 'مسودة لا تنشر',
            'canonical_lang' => 'en',
        ]);

        $this->get('/blog/'.$draft->slug)->assertNotFound();
        $this->get('/blog/'.$approved->slug.'?lang=en')->assertOk()
            ->assertSee('Every visit rechecks publication and evidence');

        // Even if a browser retained the slug, the next HTTP visit must
        // refuse its revoked evidence rather than serving cached content.
        $approved->revisions()->where('revision_no', 1)->firstOrFail()
            ->update(['validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ]]);
        $this->get('/blog/'.$approved->slug.'?lang=en')->assertNotFound();
        $this->get('/blog/'.$approved->slug.'?lang=ar')->assertNotFound();
    }

    public function test_untrusted_published_titles_cannot_escape_json_script_or_saved_link_markup(): void
    {
        $article = $this->approvedArticle('Reviewed </script><script>alert("unsafe")</script> source');
        $html = $this->get('/blog/'.$article->slug.'?lang=en')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>alert', $html);
        $this->assertStringContainsString('\\u003C/script\\u003E', $html);
        $this->assertStringContainsString('anchor.textContent =', $html);
        $this->assertStringContainsString("validSlug(value.slug)", $html);
        $this->assertStringContainsString("validTitle(value.en)", $html);
        $this->assertStringContainsString("validTitle(value.ar)", $html);
        $this->assertStringNotContainsString('anchor.innerHTML', $html);
    }
}

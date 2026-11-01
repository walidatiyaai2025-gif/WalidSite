<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifiedArticleReportContextTest extends TestCase
{
    use RefreshDatabase;

    private function publishedGuide(string $slug): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Reviewed technical guide',
            'title_ar' => 'دليل تقني مُراجع',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Reviewed technical material',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/reviewed-guide',
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

    public function test_verified_article_report_action_opens_bilingual_contextual_contact_form(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        $article = $this->publishedGuide('reviewed-report-guide');

        foreach (['en', 'ar'] as $locale) {
            $reportUrl = route('public.contact', [
                'lang' => $locale, 'article' => $article->slug,
            ]);
            $this->get(route('public.article', ['slug' => $article->slug, 'lang' => $locale]))
                ->assertOk()
                ->assertSee('href="'.e($reportUrl).'"', false);

            $response = $this->get($reportUrl)->assertOk()
                ->assertSee('class="contact-article-context"', false)
                ->assertSee($locale === 'ar' ? $article->title_ar : $article->title_en)
                ->assertSee('name="subject" value="', false)
                ->assertSee('name="message"', false)
                ->assertSee('name="privacy_consent"', false);

            $expectedSubject = ($locale === 'ar' ? 'ملاحظة بشأن المقال: ' : 'Article issue: ')
                .$article->slug;
            $this->assertStringContainsString(
                'name="subject" value="'.e($expectedSubject).'"',
                $response->getContent()
            );
            $this->assertStringContainsString(
                'href="'.e(route('public.article', [
                    'slug' => $article->slug, 'lang' => $locale,
                ])).'"',
                $response->getContent()
            );
        }
    }

    public function test_draft_revoked_or_malformed_article_never_leaks_into_contact_context(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        $draft = Article::create([
            'slug' => 'secret-draft',
            'title_en' => 'PRIVATE DRAFT TITLE',
            'title_ar' => 'مسودة غير منشورة',
            'canonical_lang' => 'en',
        ]);
        $published = $this->publishedGuide('withdrawn-report-guide');
        $published->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);

        foreach ([
            '/contact?lang=en&article='.$draft->slug,
            '/contact?lang=en&article='.$published->slug,
            '/contact?lang=en&article[]=secret-draft',
            '/contact?lang=en&article=https%3A%2F%2Fexample.test%2Fevil',
            '/contact?lang=en&article=missing-guide',
        ] as $url) {
            $page = $this->get($url)->assertOk()
                ->assertDontSee('class="contact-article-context"', false)
                ->assertDontSee('PRIVATE DRAFT TITLE')
                ->assertDontSee('Reviewed technical guide')
                ->assertDontSee('Article issue:');
            $this->assertStringContainsString('name="subject" value=""', $page->getContent());
        }
    }

    public function test_verified_article_context_does_not_pretend_mail_is_available(): void
    {
        config(['mail.default' => 'log', 'mail.contact_recipient' => null]);
        $article = $this->publishedGuide('mail-disabled-report-guide');
        $this->get('/contact?lang=en&article='.$article->slug)
            ->assertOk()
            ->assertSee('Reviewed technical guide')
            ->assertSee('Contact delivery is unavailable')
            ->assertDontSee('name="subject"', false)
            ->assertDontSee('name="privacy_consent"', false);
    }
}

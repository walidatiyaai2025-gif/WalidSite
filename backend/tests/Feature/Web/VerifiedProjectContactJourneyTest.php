<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class VerifiedProjectContactJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, bool $published = true, string $visibility = 'public'): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Reviewed case '.$slug,
            'summary_en' => 'Approved public engineering facts.',
            'summary_ar' => 'حقائق هندسية عامة موثقة.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => $published ? now()->subDay() : null,
        ]);
    }

    private function proof(
        Project $project,
        string $url = 'https://docs.example.org/approved',
        string $visibility = 'public',
        ?\DateTimeInterface $verifiedAt = null
    ): void {
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => $visibility,
            'verified_at' => $verifiedAt,
        ]);
    }

    public function test_bilingual_case_study_to_contact_language_switch_and_mail_submission_journey(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        Mail::fake();
        $project = $this->project('approved-case');
        $this->proof($project, verifiedAt: now()->subDay());

        foreach (['en', 'ar'] as $locale) {
            $otherLocale = $locale === 'en' ? 'ar' : 'en';
            $caseUrl = route('public.project', ['slug' => $project->slug, 'lang' => $locale]);
            $contactUrl = route('public.contact', ['lang' => $locale, 'project' => $project->slug]);
            $oppositeContactUrl = route('public.contact', [
                'lang' => $otherLocale, 'project' => $project->slug,
            ]);
            $this->get($caseUrl)->assertOk()
                ->assertSee('href="'.e($contactUrl).'"', false)
                ->assertSee('href="'.e(route('public.resume', ['lang' => $locale])).'"', false)
                ->assertSee($locale === 'ar'
                    ? 'استفسر عن دراسة الحالة الموثقة'
                    : 'Ask about this verified case study');

            $form = $this->get($contactUrl)->assertOk()
                ->assertSee('class="contact-project-context"', false)
                ->assertSee('Reviewed case approved-case')
                ->assertSee('href="'.e($caseUrl).'"', false)
                ->assertSee('href="'.e($oppositeContactUrl).'"', false)
                ->assertSee('name="privacy_consent"', false)
                ->assertDontSee('class="contact-article-context"', false);
            $subject = ($locale === 'ar' ? 'استفسار عن المشروع: ' : 'Project inquiry: ')
                .$project->slug;
            $this->assertStringContainsString(
                'name="subject" value="'.e($subject).'"', $form->getContent()
            );
            $this->get($oppositeContactUrl)->assertOk()
                ->assertSee('href="'.e($contactUrl).'"', false);
        }

        $this->from('/contact?lang=en&project=approved-case')
            ->post('/contact?lang=en', [
                'name' => 'Reviewed visitor',
                'email' => 'visitor@example.test',
                'subject' => 'Project inquiry: approved-case',
                'message' => 'Please clarify the published public case study.',
                'privacy_consent' => '1',
            ])
            ->assertRedirect(route('public.contact', ['lang' => 'en']))
            ->assertSessionHas('contact_delivered', true);

        Mail::assertSentCount(1);
        Mail::assertSent(\Illuminate\Mail\Mailable::class,
            static fn ($mail) => $mail->subject === 'WalidSite contact: Project inquiry: approved-case');
    }

    public function test_private_draft_revoked_future_and_unsafe_projects_never_enter_contact_context(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        Mail::fake();

        $private = $this->project('private-case', true, 'private');
        $this->proof($private, verifiedAt: now()->subDay());
        $draft = $this->project('draft-case', false);
        $this->proof($draft, verifiedAt: now()->subDay());
        $unverified = $this->project('unverified-case');
        $this->proof($unverified);
        $future = $this->project('future-case');
        $this->proof($future, verifiedAt: now()->addDay());
        $unsafe = $this->project('unsafe-case');
        $this->proof($unsafe, 'https://docs.example.org/private?access_token=secret', verifiedAt: now()->subDay());
        $unrouteable = $this->project('invalid_slug_case');
        $this->proof($unrouteable, verifiedAt: now()->subDay());

        foreach ([$private, $draft, $unverified, $future, $unsafe, $unrouteable] as $project) {
            $this->get('/contact?lang=en&project='.$project->slug)
                ->assertOk()
                ->assertDontSee('class="contact-project-context"', false)
                ->assertDontSee('Reviewed case '.$project->slug)
                ->assertDontSee('Project inquiry:')
                ->assertSee('name="subject" value=""', false)
                ->assertSee('href="'.e(route('public.contact', ['lang' => 'ar'])).'"', false);
        }
        $this->get('/contact?project[]=private-case')->assertOk()
            ->assertDontSee('Reviewed case private-case')
            ->assertDontSee('class="contact-project-context"', false);
        $this->get('/contact?project=https%3A%2F%2Fdocs.example.org%2Fprivate')->assertOk()
            ->assertDontSee('class="contact-project-context"', false);
        Mail::assertNothingSent();
    }

    public function test_revocation_is_checked_on_each_contact_read_and_mail_outage_is_honest(): void
    {
        $project = $this->project('withdrawn-context-case');
        $this->proof($project, verifiedAt: now()->subDay());

        config(['mail.default' => 'log', 'mail.contact_recipient' => null]);
        $url = route('public.contact', ['lang' => 'ar', 'project' => $project->slug]);
        $this->get($url)->assertOk()
            ->assertSee('class="contact-project-context"', false)
            ->assertSee('استقبال الرسائل غير مفعل')
            ->assertDontSee('name="subject"', false);

        ProjectEvidence::query()->where('project_id', $project->id)
            ->update(['visibility' => 'private']);
        $this->get($url)->assertOk()
            ->assertDontSee('class="contact-project-context"', false)
            ->assertDontSee('Reviewed case withdrawn-context-case');
        $this->get(route('public.project', ['slug' => $project->slug, 'lang' => 'ar']))
            ->assertNotFound();
    }

    public function test_article_report_priority_and_contextual_language_switch_do_not_leak_projects(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        $article = Article::create([
            'slug' => 'approved-editorial-note',
            'title_en' => 'Reviewed editorial note',
            'title_ar' => 'ملاحظة منشورة',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Published after review',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/public-article',
                'verified' => true,
            ]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();
        $project = $this->project('another-approved-case');
        $this->proof($project, verifiedAt: now()->subDay());

        foreach (['en', 'ar'] as $locale) {
            $targetLocale = $locale === 'en' ? 'ar' : 'en';
            $url = route('public.contact', [
                'lang' => $locale,
                'article' => $article->slug,
                'project' => $project->slug,
            ]);
            $page = $this->get($url)->assertOk()
                ->assertSee('class="contact-article-context"', false)
                ->assertDontSee('class="contact-project-context"', false)
                ->assertDontSee('Reviewed case another-approved-case')
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $targetLocale, 'article' => $article->slug,
                ])).'"', false)
                ->assertDontSee('project=another-approved-case');
            $this->assertStringContainsString(
                'name="subject" value="'.e(($locale === 'ar'
                    ? 'ملاحظة بشأن المقال: ' : 'Article issue: ').$article->slug).'"',
                $page->getContent()
            );
        }
    }
}

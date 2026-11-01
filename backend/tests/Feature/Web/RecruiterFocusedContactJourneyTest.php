<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class RecruiterFocusedContactJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): Profile
    {
        $user = User::create([
            'email' => 'recruiter-journey-owner@example.test',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Approved public profile',
            'publish_status' => 'published',
        ]);
    }

    private function skill(Profile $profile, string $slug, string $category, bool $verified = true): void
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Reviewed '.$slug,
            'name_ar' => 'مهارة '.$slug,
            'category' => $category,
            'evidence_status' => $verified ? 'verified' : 'unverified',
        ]);
        DB::table('profile_skill')->insert([
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_three_recruiter_focuses_reach_matching_verified_resume_and_localized_inquiry(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        $owner = $this->owner();
        $this->skill($owner, 'database', 'Database & Reliability');
        $this->skill($owner, 'software', 'Software & Integration');
        $this->skill($owner, 'delivery', 'Project Delivery');
        $this->skill($owner, 'SECRET-unapproved', 'Database & Reliability', false);

        $topics = [
            'database' => ['Database engineering', 'هندسة قواعد البيانات'],
            'software' => ['Software and infrastructure engineering', 'هندسة البرمجيات والبنية التحتية'],
            'delivery' => ['Project delivery', 'إدارة وتسليم المشاريع'],
        ];

        foreach ($topics as $focus => [$englishTopic, $arabicTopic]) {
            foreach (['en', 'ar'] as $locale) {
                $otherLocale = $locale === 'en' ? 'ar' : 'en';
                $recruiterUrl = route('public.hire', ['lang' => $locale, 'track' => $focus]);
                $resumeUrl = route('public.resume', ['lang' => $locale, 'track' => $focus]);
                $contactUrl = route('public.contact', ['lang' => $locale, 'track' => $focus]);
                $oppositeContactUrl = route('public.contact', [
                    'lang' => $otherLocale, 'track' => $focus,
                ]);

                $this->get($recruiterUrl)->assertOk()
                    ->assertSee('href="'.e($resumeUrl).'"', false)
                    ->assertSee('href="'.e($contactUrl).'"', false)
                    ->assertSee(($locale === 'ar' ? 'مهارة ' : 'Reviewed ').$focus)
                    ->assertDontSee('SECRET-unapproved');

                $this->get($resumeUrl)->assertOk()
                    ->assertSee('href="'.e($contactUrl).'"', false)
                    ->assertSee(($locale === 'ar' ? 'مهارة ' : 'Reviewed ').$focus)
                    ->assertDontSee('SECRET-unapproved');

                $form = $this->get($contactUrl)->assertOk()
                    ->assertSee('class="contact-track-context"', false)
                    ->assertSee('href="'.e($recruiterUrl).'"', false)
                    ->assertSee('href="'.e($oppositeContactUrl).'"', false)
                    ->assertSee('name="subject" value="', false)
                    ->assertSee('name="privacy_consent"', false)
                    ->assertDontSee('class="contact-project-context"', false)
                    ->assertDontSee('class="contact-article-context"', false);
                $topic = $locale === 'ar' ? $arabicTopic : $englishTopic;
                $subject = ($locale === 'ar' ? 'استفسار عن مجال: ' : 'Inquiry about: ').$topic;
                $this->assertStringContainsString(
                    'name="subject" value="'.e($subject).'"',
                    $form->getContent()
                );
                $this->get($oppositeContactUrl)->assertOk()
                    ->assertSee('href="'.e($contactUrl).'"', false);
            }
        }
    }

    public function test_contact_focus_query_validation_cannot_reflect_unsupported_or_nested_values(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        foreach ([
            'track=all',
            'track=unknown',
            'track[]=database',
            'track[focus]=software',
            'track='.str_repeat('x', 800),
        ] as $query) {
            $page = $this->get('/contact?lang=en&'.$query)->assertOk()
                ->assertDontSee('class="contact-track-context"', false)
                ->assertDontSee('Inquiry about:')
                ->assertSee('name="subject" value=""', false)
                ->assertSee('href="'.e(route('public.contact', ['lang' => 'ar'])).'"', false);
            $this->assertStringNotContainsString(
                'href="'.e(route('public.contact', ['lang' => 'ar', 'track' => 'database'])).'"',
                $page->getContent()
            );
        }
        $this->get('/hire?lang=ar&track[]=database')->assertOk()
            ->assertSee('href="'.e(route('public.contact', ['lang' => 'ar'])).'"', false);
        $this->get('/resume?lang=en&track[]=database')->assertOk()
            ->assertSee('href="'.e(route('public.contact', ['lang' => 'en'])).'"', false);
    }

    public function test_public_article_and_case_study_contexts_take_precedence_over_recruiter_track(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        $project = Project::create([
            'slug' => 'source-reviewed-example',
            'title' => 'Public case study',
            'summary_en' => 'Only public verified claims',
            'summary_ar' => 'مصادر موثقة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/approved',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        $article = Article::create([
            'slug' => 'approved-public-guide',
            'title_en' => 'Approved public guide',
            'title_ar' => 'دليل منشور',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Verified guide',
            'status' => 'validated',
            'citations_json' => [['source_url' => 'https://docs.example.org/guide', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();

        foreach (['en', 'ar'] as $locale) {
            $alternate = $locale === 'ar' ? 'en' : 'ar';

            $projectPage = $this->get(route('public.contact', [
                'lang' => $locale, 'project' => $project->slug, 'track' => 'software',
            ]))->assertOk()
                ->assertSee('class="contact-project-context"', false)
                ->assertDontSee('class="contact-track-context"', false)
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $alternate, 'project' => $project->slug,
                ])).'"', false)
                ->assertDontSee('Inquiry about:');
            $this->assertStringContainsString(
                'name="subject" value="'.e(($locale === 'ar'
                    ? 'استفسار عن المشروع: ' : 'Project inquiry: ').$project->slug).'"',
                $projectPage->getContent()
            );

            $articlePage = $this->get(route('public.contact', [
                'lang' => $locale, 'article' => $article->slug,
                'project' => $project->slug, 'track' => 'database',
            ]))->assertOk()
                ->assertSee('class="contact-article-context"', false)
                ->assertDontSee('class="contact-project-context"', false)
                ->assertDontSee('class="contact-track-context"', false)
                ->assertSee('href="'.e(route('public.contact', [
                    'lang' => $alternate, 'article' => $article->slug,
                ])).'"', false);
            $this->assertStringContainsString(
                'name="subject" value="'.e(($locale === 'ar'
                    ? 'ملاحظة بشأن المقال: ' : 'Article issue: ').$article->slug).'"',
                $articlePage->getContent()
            );

            $this->get('/contact?lang='.$locale.'&article[]=invalid&track=database')
                ->assertOk()
                ->assertDontSee('class="contact-track-context"', false)
                ->assertSee('name="subject" value=""', false);
        }
    }

    public function test_inquiry_subject_is_editable_and_normal_consent_and_mail_safety_still_apply(): void
    {
        config(['mail.default' => 'smtp', 'mail.contact_recipient' => 'recipient@example.test']);
        Mail::fake();

        $this->get('/contact?lang=en&track=database')->assertOk()
            ->assertSee('Inquiry about: Database engineering');

        $this->from('/contact?lang=en&track=database')
            ->post('/contact?lang=en', [
                'name' => 'Recruiter',
                'email' => 'recruiter@example.test',
                'subject' => 'Specific database engineering inquiry',
                'message' => 'Please reply about a verified public engineering case.',
            ])
            ->assertSessionHasErrors(['privacy_consent']);
        Mail::assertNothingSent();

        $this->from('/contact?lang=en&track=database')
            ->post('/contact?lang=en', [
                'name' => 'Recruiter',
                'email' => 'recruiter@example.test',
                'subject' => 'Specific database engineering inquiry',
                'message' => 'Please reply about a verified public engineering case.',
                'privacy_consent' => '1',
                'website' => '',
            ])
            ->assertRedirect(route('public.contact', ['lang' => 'en']))
            ->assertSessionHas('contact_delivered', true);
        Mail::assertSentCount(1);
        Mail::assertSent(Mailable::class,
            static fn ($mail) => $mail->subject === 'WalidSite contact: Specific database engineering inquiry');
    }

    public function test_unverified_skills_and_mail_disabled_cannot_imply_recruiter_availability(): void
    {
        $owner = $this->owner();
        $this->skill($owner, 'PRIVATE-unverified', 'Database & Reliability', false);

        $recruiter = $this->get('/hire?lang=ar&track=database')->assertOk()
            ->assertDontSee('PRIVATE-unverified')
            ->assertSee('لا توجد مهارات موثقة')
            ->assertSee('href="'.e(route('public.contact', [
                'lang' => 'ar', 'track' => 'database',
            ])).'"', false);

        config(['mail.default' => 'log', 'mail.contact_recipient' => null]);
        $this->get('/contact?lang=ar&track=database')->assertOk()
            ->assertSee('class="contact-track-context"', false)
            ->assertSee('استقبال الرسائل غير مفعل')
            ->assertDontSee('name="subject"', false)
            ->assertDontSee('PRIVATE-unverified');
        $this->post('/contact?lang=ar', [
            'name' => 'Recruiter',
            'email' => 'recruiter@example.test',
            'subject' => 'Inquiry',
            'message' => 'Please respond about a role when possible.',
            'privacy_consent' => '1',
        ])->assertStatus(503);
    }
}

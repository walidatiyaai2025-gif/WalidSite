<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicPortfolioAndContactTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, string $visibility = 'public'): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Documented Project',
            'summary_en' => 'Confirmed public case study',
            'summary_ar' => 'دراسة حالة معتمدة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
    }

    private function evidence(Project $project, string $visibility = 'public', bool $verified = true, string $url = 'https://example.org/evidence'): void
    {
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => $visibility,
            'verified_at' => $verified ? now()->subDay() : null,
        ]);
    }

    public function test_projects_have_localized_functional_empty_and_search_states(): void
    {
        $this->get('/projects')->assertOk()
            ->assertSee('Verified projects and case studies')
            ->assertSee('No evidence-verified public case studies')
            ->assertSee('role="search"', false);
        $this->get('/projects?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مشاريع ودراسات حالة موثقة');

        $project = $this->project('documented-example');
        $this->evidence($project);
        $this->get('/projects?q=Documented')->assertOk()
            ->assertSee('Documented Project')
            ->assertSee('href="'.route('public.project', ['slug' => $project->slug, 'lang' => 'en']).'"', false);
        $this->get('/projects?q=Missing')->assertOk()->assertDontSee('Documented Project');
    }

    public function test_project_status_filter_is_localized_composable_and_only_shows_verified_evidence(): void
    {
        $completed = $this->project('completed-case');
        $this->evidence($completed);

        $research = $this->project('research-case');
        $research->update(['status' => 'research']);
        $this->evidence($research);

        $unsafe = $this->project('unsafe-research');
        $unsafe->update(['status' => 'research']);
        $this->evidence($unsafe, 'public', true, 'https://localhost/internal');

        $this->get('/projects?status=research&q=Documented&lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('name="status"', false)
            ->assertSee('value="research" selected', false)
            ->assertSee('research-case')
            ->assertSee('حالة المشروع')
            ->assertDontSee('completed-case')
            ->assertDontSee('unsafe-research');

        $this->get('/projects?status=completed')->assertOk()
            ->assertSee('completed-case')
            ->assertDontSee('research-case');

        // Non-scalar and unknown status input fails to the safe "all" filter.
        $this->get('/projects?status[]=research')->assertOk()
            ->assertSee('completed-case')->assertSee('research-case');
        $this->get('/projects?status=unpublished')->assertOk()
            ->assertSee('completed-case')->assertSee('research-case')
            ->assertDontSee('unsafe-research');
    }

    public function test_case_studies_fail_closed_for_private_draft_and_unverified_evidence(): void
    {
        $private = $this->project('internal-project', 'private');
        $this->evidence($private);
        $unverified = $this->project('unverified-project');
        $this->evidence($unverified, 'public', false);
        $invalid = $this->project('invalid-evidence');
        $this->evidence($invalid, 'public', true, 'https://github.com/private/repo');

        $this->get('/projects')->assertOk()
            ->assertDontSee('internal-project')
            ->assertDontSee('unverified-project')
            ->assertDontSee('invalid-evidence');
        foreach ([$private, $unverified, $invalid] as $project) {
            $this->get('/projects/'.$project->slug)->assertNotFound();
        }

        $published = $this->project('safe-public-case');
        $this->evidence($published);
        $this->evidence($published, 'private', true, 'https://private.example.org/secret');
        $this->get('/projects/'.$published->slug)->assertOk()
            ->assertSee('https://example.org/evidence')
            ->assertDontSee('https://private.example.org/secret')
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_web_project_pages_count_only_safe_evidence_and_fill_all_nine_slots(): void
    {
        // The first 11 DB rows pass the verified SQL scope but have private
        // query strings. They must not occupy page slots or inflate page count.
        for ($i = 1; $i <= 11; $i++) {
            $unsafe = $this->project('hidden-web-'.$i);
            $unsafe->update(['published_at' => now()->subMinutes(2)]);
            $this->evidence($unsafe, 'public', true,
                'https://docs.example.org/internal?token=not-for-publication');
        }
        // The route orders published_at DESC, then UUID. Give each safe
        // fixture a stable, distinct minute so the first nine and tenth
        // stay on their expected pages regardless of DB timestamp precision.
        $safePublishedAt = now()->subDays(2)->startOfMinute();
        for ($i = 1; $i <= 10; $i++) {
            $safe = $this->project('safe-web-'.$i);
            $safe->update(['published_at' => $safePublishedAt->copy()->subMinutes($i)]);
            $this->evidence($safe, 'public', true, 'https://docs.example.org/approved');
        }

        $first = $this->get('/projects?lang=en')->assertOk()
            ->assertSee('Page 1 / 2')
            ->assertSee('safe-web-1')
            ->assertSee('safe-web-9')
            ->assertDontSee('safe-web-10')
            ->assertDontSee('hidden-web-1')
            ->assertDontSee('token=not-for-publication')
            ->assertSee('page=2');
        $second = $this->get('/projects?page=2&lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('safe-web-10')
            ->assertDontSee('safe-web-1"')
            ->assertDontSee('hidden-web-11')
            ->assertDontSee('page=3');
        $this->get('/projects?page=3')->assertOk()
            ->assertSee('No evidence-verified public case studies')
            ->assertDontSee('hidden-web-11');
    }

    public function test_home_features_safe_projects_and_articles_beyond_unusable_first_24(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $unsafe = $this->project('unusable-home-project-'.$i);
            $unsafe->update(['published_at' => now()->subMinutes(2)]);
            $this->evidence($unsafe, 'public', true,
                'https://docs.example.org/private?auth=secret');

            $article = \App\Models\Article::create([
                'slug' => 'unusable-home-article-'.$i,
                'title_en' => 'Unavailable home article '.$i,
                'title_ar' => 'مقال غير صالح '.$i,
                'canonical_lang' => 'en',
            ]);
            $revision = $article->revisions()->create([
                'revision_no' => 1, 'body_md' => '# Verified at publication',
                'status' => 'validated',
                'citations_json' => [['source_url' => 'https://docs.example.org/public',
                    'verified' => true]],
                'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            ]);
            $article->publishVerified();
            $article->forceFill(['published_at' => now()->subMinute()])->save();
            // Revoke evidence in persisted storage without changing the initial
            // SQL publication flags. The live publicRevision gate must win.
            \Illuminate\Support\Facades\DB::table('article_revisions')
                ->where('id', $revision->id)
                ->update(['citations_json' => json_encode([
                    ['source_url' => 'https://docs.example.org/secret?token=private',
                        'verified' => true],
                ], JSON_THROW_ON_ERROR)]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $safe = $this->project('approved-home-project-'.$i);
            $safe->update(['published_at' => now()->subDays(2)]);
            $this->evidence($safe, 'public', true, 'https://docs.example.org/approved');

            $article = \App\Models\Article::create([
                'slug' => 'approved-home-article-'.$i,
                'title_en' => 'Approved home article '.$i,
                'title_ar' => 'مقال منشور معتمد '.$i,
                'canonical_lang' => 'en',
            ]);
            $article->revisions()->create([
                'revision_no' => 1, 'body_md' => '# Reviewed',
                'status' => 'validated',
                'citations_json' => [['source_url' => 'https://docs.example.org/public',
                    'verified' => true]],
                'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
            ]);
            $article->publishVerified();
            $article->forceFill(['published_at' => now()->subDays(2)])->save();
        }

        $page = $this->get('/?lang=en')->assertOk()
            ->assertSee('approved-home-project-1')
            ->assertSee('approved-home-project-2')
            ->assertSee('approved-home-project-3')
            ->assertSee('Approved home article 1')
            ->assertSee('Approved home article 2')
            ->assertSee('Approved home article 3')
            ->assertDontSee('unusable-home-project-1')
            ->assertDontSee('Unavailable home article 1')
            ->assertDontSee('token=private');
        $this->get('/?lang=ar')->assertOk()
            ->assertSee('مقال منشور معتمد 1')
            ->assertSee('approved-home-project-1')
            ->assertDontSee('مقال غير صالح 1');
    }

    public function test_public_resume_never_exposes_pending_private_cv(): void
    {
        $user = User::create(['email' => 'resume@example.test', 'status' => 'pending_activation']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Unpublished Person',
            'bio_en' => 'Confidential CV details',
            'bio_ar' => 'معلومات خاصة',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Private Employer',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'started_on' => '2024-01-01',
            'verified' => true,
            'publishable' => true,
        ]);
        $this->get('/resume')->assertOk()->assertDontSee('Unpublished Person')
            ->assertDontSee('Private Employer')->assertDontSee('Confidential CV details');
        $user->update(['status' => 'active']);
        $this->get('/resume')->assertOk()
            ->assertSee('Unpublished Person')->assertSee('Private Employer')
            ->assertDontSee('Confidential CV details');
    }

    public function test_contact_is_explicitly_unavailable_without_verified_delivery_configuration(): void
    {
        config(['mail.contact_recipient' => null, 'mail.default' => 'log']);
        $this->get('/contact?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('استقبال الرسائل غير مفعل');
        $this->post('/contact', [
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Technical inquiry',
            'message' => 'A real technical inquiry, for review.',
        ])->assertStatus(503);
    }


    public function test_contact_validation_errors_are_linked_to_fields_in_both_locales(): void
    {
        config(['mail.contact_recipient' => 'contact@example.test', 'mail.default' => 'smtp']);
        Mail::fake();

        $invalid = [
            'name' => 'V',
            'email' => 'not-email',
            'subject' => 'a',
            'message' => 'short',
        ];

        foreach (['en', 'ar'] as $locale) {
            $page = $this->from('/contact?lang='.$locale)
                ->followingRedirects()
                ->post('/contact?lang='.$locale, $invalid);

            $page->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('aria-describedby="contact-name-error"', false)
                ->assertSee('aria-describedby="contact-email-error"', false)
                ->assertSee('aria-describedby="contact-subject-error"', false)
                ->assertSee('aria-describedby="contact-message-error"', false)
                ->assertSee('id="contact-email-error" class="contact-error" role="alert"', false)
                ->assertSee('id="contact-message-error" class="contact-error" role="alert"', false)
                ->assertSee('aria-invalid="true"', false);
        }

        Mail::assertNothingSent();
    }

    public function test_contact_form_validates_and_honeypot_never_sends(): void
    {
        config(['mail.contact_recipient' => 'contact@example.test', 'mail.default' => 'smtp']);
        Mail::fake();
        $this->get('/contact')->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="message"', false);
        $this->post('/contact', [
            'name' => 'V',
            'email' => 'not-email',
            'subject' => 'a',
            'message' => 'short',
        ])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
        $this->post('/contact', [
            'name' => 'Visitor',
            'email' => 'visitor@example.test',
            'subject' => 'Technical inquiry',
            'message' => 'This is a technical inquiry for review.',
            'website' => 'https://bot.test/',
        ])->assertRedirect();
        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHomeLandingTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, bool $published = true, bool $safe = true): void
    {
        $project = Project::create([
            'slug' => $slug, 'title' => 'Project '.$slug,
            'summary_en' => 'Documented engineering delivery',
            'summary_ar' => 'تسليم هندسي موثق',
            'role_en' => 'Software engineer', 'role_ar' => 'مهندس برمجيات',
            'status' => 'completed', 'visibility' => 'public',
            'published_at' => $published ? now()->subDay() : null,
        ]);

        $project->evidence()->create([
            'evidence_type' => 'public_document',
            'url' => $safe ? 'https://docs.example.test/proof' : 'https://github.com/owner/private-project',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
    }

    private function article(string $slug, bool $publish): void
    {
        $article = Article::create([
            'slug' => $slug, 'title_en' => 'Article '.$slug,
            'title_ar' => 'مقال '.$slug, 'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Technically reviewed',
            'status' => 'validated',
            'citations_json' => [['source_url' => 'https://example.org/verified', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        if ($publish) {
            $article->publishVerified();
        }
    }

    public function test_home_matches_bilingual_reference_structure_with_real_navigation(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Waleed Atiya')
            ->assertSee('Enterprise Technology &amp; AI Solutions Architecture', false)
            ->assertSee('class="w26-portrait"', false)
            ->assertSee('class="w26-devices"', false)
            ->assertSee('class="w26-ribbon"', false)
            ->assertDontSee('Verified projects &amp; case studies', false)
            ->assertDontSee('Latest source-checked articles')
            ->assertDontSee('No evidence-verified public case studies')
            ->assertDontSee('No source-verified published articles')
            ->assertDontSee('id="landing-projects-title"', false)
            ->assertDontSee('id="landing-articles-title"', false)
            ->assertSee('body:has(.w26-landing) .site-footer { display: none; }', false)
            ->assertSee('href="'.route('public.github', ['lang' => 'en']).'"', false)
            ->assertDontSee('1,248')
            ->assertDontSee('PMP®')
            ->assertDontSee('20+ Years');

        $this->get('/?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('استكشف المشاريع')
            ->assertDontSee('لا توجد دراسات حالة عامة')
            ->assertDontSee('لا توجد مقالات منشورة وموثقة');
    }

    public function test_home_has_no_featured_panels_but_verified_content_remains_in_its_directories(): void
    {
        $this->project('verified-case');
        $this->project('draft-case', false);
        $this->project('unsafe-case', true, false);
        $this->article('verified-guide', true);
        $this->article('private-draft', false);

        $this->get('/')->assertOk()
            ->assertDontSee('Project verified-case')
            ->assertDontSee('Article verified-guide')
            ->assertDontSee('Project draft-case')
            ->assertDontSee('Project unsafe-case')
            ->assertDontSee('Article private-draft');

        $this->get('/projects?lang=en')->assertOk()
            ->assertSee('Project verified-case')
            ->assertDontSee('Project draft-case')
            ->assertDontSee('Project unsafe-case');
        $this->get('/blog?lang=en')->assertOk()
            ->assertSee('Article verified-guide')
            ->assertDontSee('Article private-draft');

        $this->get('/?lang=ar')->assertOk()
            ->assertDontSee('مقال verified-guide')
            ->assertDontSee('تسليم هندسي موثق');
    }

    public function test_home_never_publishes_claims_of_inactive_owner_or_unapproved_cv(): void
    {
        $user = User::create(['email' => 'home-owner@example.test', 'status' => 'pending_activation']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Home Profile',
            'bio_en' => 'Private employer secret',
            'publish_status' => 'published',
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id, 'claim_en' => 'Claim pending approval',
            'claim_ar' => 'ادعاء قيد التحقق',
            'evidence_url' => 'https://example.org/public-source',
            'status' => 'pending', 'is_public' => true,
        ]);

        $this->get('/')->assertOk()->assertDontSee('Claim pending approval')
            ->assertDontSee('Private employer secret');
        $user->update(['status' => 'active']);
        $this->get('/')->assertDontSee('Claim pending approval');

        ProfessionalClaim::query()->update(['status' => 'approved']);
        // Removing the lower homepage panels never leaks even approved CV claims.
        $this->get('/')->assertOk()
            ->assertDontSee('Claim pending approval')
            ->assertDontSee('https://example.org/public-source')
            ->assertDontSee('Private employer secret');
    }
}

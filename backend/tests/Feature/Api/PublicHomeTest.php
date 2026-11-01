<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHomeTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $slug, bool $safe = true, bool $visible = true): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Project '.$slug,
            'summary_en' => 'Public engineering record',
            'summary_ar' => 'سجل هندسي منشور',
            'role_en' => 'Engineer', 'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visible ? 'public' : 'private',
            'published_at' => now()->subDays(2),
        ]);
        $project->evidence()->create([
            'evidence_type' => 'public_document',
            'url' => $safe
                ? 'https://docs.example.org/public-evidence'
                : 'https://docs.example.org/private?token=secret',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        return $project;
    }

    private function article(string $slug, bool $published = true, bool $revoke = false): Article
    {
        $article = Article::create([
            'slug' => $slug, 'title_en' => 'Guide '.$slug,
            'title_ar' => 'دليل موثق '.$slug, 'canonical_lang' => 'en',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Verified published guidance',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/guide', 'verified' => true,
            ]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        if ($published) {
            $article->publishVerified();
        }
        if ($revoke) {
            $revision->update(['validation_json' => [
                'citations_passed' => false, 'technical_passed' => true,
            ]]);
        }

        return $article;
    }

    public function test_empty_home_has_no_fabricated_profile_projects_or_articles(): void
    {
        foreach (['en', 'ar'] as $lang) {
            $this->getJson('/api/v1/public/home?lang='.$lang)->assertOk()
                ->assertJsonPath('data.profile', null)
                ->assertJsonCount(0, 'data.featured_projects')
                ->assertJsonCount(0, 'data.latest_articles')
                ->assertJsonPath('meta.lang', $lang)
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->getJson('/api/v1/public/home?lang=fr')->assertUnprocessable();
        $this->getJson('/api/v1/public/home?lang[]=ar')->assertUnprocessable();
    }

    public function test_only_active_published_owner_claims_qualify_for_home(): void
    {
        $stranger = User::create(['email' => 'stranger-home@example.test', 'status' => 'active']);
        Profile::create([
            'user_id' => $stranger->id, 'public_name' => 'Private stranger',
            'publish_status' => 'published',
        ]);
        $owner = User::create(['email' => 'real-owner-home@example.test', 'status' => 'disabled']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $owner->id, 'public_name' => 'Reviewed public owner',
            'bio_en' => 'Private CV content',
            'publish_status' => 'published',
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id, 'claim_en' => 'Approved source-backed claim',
            'claim_ar' => 'معلومة منشورة وموثقة',
            'evidence_url' => 'https://docs.example.org/owner-proof',
            'status' => 'approved', 'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id, 'claim_en' => 'Unapproved secret claim',
            'claim_ar' => 'ادعاء غير موثق',
            'evidence_url' => 'https://docs.example.org/unapproved',
            'status' => 'review', 'is_public' => true,
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id, 'claim_en' => 'Internal secret evidence',
            'claim_ar' => 'دليل داخلي سري',
            'evidence_url' => 'https://gateway.internal/owner-cv',
            'status' => 'approved', 'is_public' => true,
        ]);

        $this->getJson('/api/v1/public/home')->assertOk()
            ->assertJsonPath('data.profile', null)
            ->assertDontSee('Private stranger')
            ->assertDontSee('Reviewed public owner');

        $owner->update(['status' => 'active']);
        $this->getJson('/api/v1/public/home?lang=en')->assertOk()
            ->assertJsonPath('data.profile.name', 'Reviewed public owner')
            ->assertJsonCount(1, 'data.profile.claims')
            ->assertJsonPath('data.profile.claims.0.text', 'Approved source-backed claim')
            ->assertDontSee('Private CV content')
            ->assertDontSee('Unapproved secret claim')
            ->assertDontSee('Internal secret evidence');
        $this->getJson('/api/v1/public/home?lang=ar')->assertOk()
            ->assertJsonPath('data.profile.claims.0.text', 'معلومة منشورة وموثقة')
            ->assertDontSee('دليل داخلي سري');
    }

    public function test_featured_cards_skip_revoked_evidence_before_latest_three_cap(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $badProject = $this->project('unsafe-case-'.$i, false);
            $badProject->update(['published_at' => now()->subMinute()]);
            $badArticle = $this->article('revoked-article-'.$i, true, true);
            $badArticle->forceFill(['published_at' => now()->subMinute()])->save();
        }
        $this->project('private-case', true, false);
        $this->article('draft-guide', false);
        for ($i = 1; $i <= 4; $i++) {
            $goodProject = $this->project('approved-case-'.$i);
            $goodProject->update(['published_at' => now()->subDays(3)->subMinutes($i)]);
            $goodArticle = $this->article('approved-article-'.$i);
            $goodArticle->forceFill(['published_at' => now()->subDays(3)->subMinutes($i)])->save();
        }

        foreach (['en', 'ar'] as $lang) {
            $response = $this->getJson('/api/v1/public/home?lang='.$lang)->assertOk()
                ->assertJsonCount(3, 'data.featured_projects')
                ->assertJsonCount(3, 'data.latest_articles')
                ->assertDontSee('unsafe-case-')
                ->assertDontSee('revoked-article-')
                ->assertDontSee('private-case')
                ->assertDontSee('draft-guide')
                ->assertDontSee('token=secret')
                ->assertDontSee('approved-case-4')
                ->assertDontSee('approved-article-4');
            $response->assertJsonPath('data.featured_projects.0.slug', 'approved-case-1')
                ->assertJsonPath('data.latest_articles.0.slug', 'approved-article-1')
                ->assertJsonPath('data.featured_projects.0.summary',
                    $lang === 'ar' ? 'سجل هندسي منشور' : 'Public engineering record')
                ->assertJsonPath('data.latest_articles.0.title',
                    ($lang === 'ar' ? 'دليل موثق ' : 'Guide ').'approved-article-1');
            $this->assertStringContainsString('lang='.$lang,
                $response->json('data.featured_projects.0.url'));
            $this->assertStringContainsString('lang='.$lang,
                $response->json('data.latest_articles.0.url'));
        }
    }

    public function test_live_evidence_withdrawal_removes_featured_cards_immediately(): void
    {
        $project = $this->project('withdrawn-case');
        $article = $this->article('withdrawn-guide');
        $this->getJson('/api/v1/public/home')->assertOk()
            ->assertJsonCount(1, 'data.featured_projects')
            ->assertJsonCount(1, 'data.latest_articles');

        $project->evidence()->update(['url' => 'https://docs.example.org/source?token=revoked']);
        $article->revisions()->where('revision_no', 1)->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);

        $this->getJson('/api/v1/public/home')->assertOk()
            ->assertJsonCount(0, 'data.featured_projects')
            ->assertJsonCount(0, 'data.latest_articles')
            ->assertDontSee('withdrawn-case')
            ->assertDontSee('withdrawn-guide')
            ->assertDontSee('token=revoked');
    }
}

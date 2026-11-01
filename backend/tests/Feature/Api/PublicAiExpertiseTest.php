<?php

namespace Tests\Feature\Api;

use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublicAiExpertiseTest extends TestCase
{
    use RefreshDatabase;

    private function profile(
        string $email,
        string $role = 'owner',
        string $status = 'active',
        string $publication = 'published'
    ): Profile {
        $user = User::create(['email' => $email, 'status' => $status]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Test owner',
            'bio_en' => 'PRIVATE UNPUBLISHED BIO',
            'publish_status' => $publication,
        ]);
    }

    private function skill(
        Profile $profile,
        string $slug,
        string $name,
        string $category = 'Software & Integration',
        string $evidence = 'verified'
    ): Skill {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => $name,
            'name_ar' => 'هندسة الذكاء الاصطناعي '.$slug,
            'category' => $category,
            'evidence_status' => $evidence,
        ]);
        DB::table('profile_skill')->insert([
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);

        return $skill;
    }

    private function project(
        string $slug,
        string $title = 'AI integration project',
        string $visibility = 'public',
        bool $published = true
    ): Project {
        return Project::create([
            'slug' => $slug,
            'title' => $title,
            'summary_en' => 'Reviewed machine learning integration',
            'summary_ar' => 'مشروع ذكاء اصطناعي موثق',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => $published ? now()->subDays(3) : null,
        ]);
    }

    private function evidence(Project $project, string $url = 'https://docs.example.org/verified-proof'): ProjectEvidence
    {
        return ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'public_document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);
    }

    public function test_empty_catalog_is_honest_and_never_fetches_ai_or_fake_demos(): void
    {
        Http::fake();
        foreach (['en', 'ar'] as $lang) {
            $response = $this->getJson('/api/v1/public/expertise/ai?lang='.$lang)->assertOk()
                ->assertJsonCount(0, 'data.skills')
                ->assertJsonCount(0, 'data.projects')
                ->assertJsonCount(0, 'data.demos')
                ->assertJsonCount(1, 'data.safety_notes')
                ->assertJsonPath('meta.lang', $lang);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
    }

    public function test_only_active_owner_verified_ai_skills_are_localized_and_revocable(): void
    {
        $notOwner = $this->profile('reader@example.test', 'reader');
        $this->skill($notOwner, 'private-reader-ai', 'AI restricted reader');
        $inactive = $this->profile('disabled@example.test', 'owner', 'disabled');
        $this->skill($inactive, 'private-disabled-ai', 'AI disabled');
        $draft = $this->profile('draft@example.test', 'owner', 'active', 'draft');
        $this->skill($draft, 'private-draft-ai', 'AI draft');

        $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(0, 'data.skills')
            ->assertDontSee('private-reader-ai')
            ->assertDontSee('private-disabled-ai')
            ->assertDontSee('private-draft-ai');

        $owner = $this->profile('owner@example.test');
        $ai = $this->skill($owner, 'verified-prompt-workflows', 'AI prompt engineering');
        $this->skill($owner, 'verified-ai-category', 'Technical orchestration', 'AI & Automation');
        $this->skill($owner, 'plain-maintenance', 'Maintenance operations', 'Database & Reliability');
        $this->skill($owner, 'private-ai-draft', 'AI secret model', 'AI & Automation', 'unverified');

        $response = $this->getJson('/api/v1/public/expertise/ai?lang=ar')
            ->assertOk()->assertJsonCount(2, 'data.skills')
            ->assertJsonPath('meta.lang', 'ar')
            ->assertDontSee('plain-maintenance')
            ->assertDontSee('private-ai-draft')
            ->assertDontSee('PRIVATE UNPUBLISHED BIO');
        $this->assertEqualsCanonicalizing(
            ['verified-prompt-workflows', 'verified-ai-category'],
            array_column($response->json('data.skills'), 'slug')
        );
        $this->assertStringContainsString('هندسة الذكاء الاصطناعي', $response->json('data.skills.0.name'));

        $this->getJson('/api/v1/public/expertise/ai?lang=en')->assertOk()
            ->assertJsonCount(2, 'data.skills')
            ->assertDontSee('private-reader-ai');

        $ai->update(['evidence_status' => 'unverified']);
        $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(1, 'data.skills')
            ->assertDontSee('verified-prompt-workflows');

        $owner->update(['publish_status' => 'draft']);
        $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(0, 'data.skills');
    }

    public function test_only_evidence_safe_public_ai_projects_have_working_case_study_links(): void
    {
        $verified = $this->project('verified-ai-platform');
        $proof = $this->evidence($verified);
        $private = $this->project('hidden-private-ai', 'AI private initiative', 'private');
        $this->evidence($private);
        $draft = $this->project('hidden-draft-ai', 'AI draft project', 'public', false);
        $this->evidence($draft);
        $unsigned = $this->project('hidden-signed-ai');
        $this->evidence($unsigned, 'https://docs.example.org/internal?token=secret');
        $internal = $this->project('hidden-local-ai');
        $this->evidence($internal, 'https://service.internal/private');
        $unrelated = $this->project('ordinary-maintenance', 'Maintenance procedures');
        $unrelated->update(['summary_en' => 'Routine database administration', 'summary_ar' => 'صيانة قواعد البيانات']);
        $this->evidence($unrelated);

        $res = $this->getJson('/api/v1/public/expertise/ai?lang=ar')
            ->assertOk()->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.projects.0.slug', $verified->slug)
            ->assertJsonPath('data.projects.0.name', 'AI integration project')
            ->assertJsonPath('data.projects.0.summary', 'مشروع ذكاء اصطناعي موثق')
            ->assertJsonPath('data.projects.0.role', 'مهندس')
            ->assertJsonPath('data.projects.0.evidence_urls.0', 'https://docs.example.org/verified-proof')
            ->assertJsonPath('data.projects.0.url', route('public.project', ['slug' => $verified->slug, 'lang' => 'ar']))
            ->assertJsonCount(0, 'data.demos')
            ->assertDontSee('hidden-private-ai')
            ->assertDontSee('hidden-draft-ai')
            ->assertDontSee('hidden-signed-ai')
            ->assertDontSee('hidden-local-ai')
            ->assertDontSee('ordinary-maintenance')
            ->assertDontSee('token=secret')
            ->assertDontSee('service.internal');
        $this->assertCount(6, $res->json('data.projects.0'));

        $proof->update(['url' => 'https://docs.example.org/private?access_token=revoked']);
        $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(0, 'data.projects')
            ->assertDontSee('verified-ai-platform');
    }

    public function test_unusable_first_thirteen_projects_never_consume_ten_verified_slots(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $bad = $this->project('hidden-ai-'.$i);
            $bad->update(['published_at' => now()->subMinute()]);
            $this->evidence($bad, 'https://docs.example.org/private?token=secret');
        }
        for ($i = 1; $i <= 11; $i++) {
            $good = $this->project('approved-ai-'.$i);
            $this->evidence($good);
        }

        $res = $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(10, 'data.projects')
            ->assertDontSee('hidden-ai-')
            ->assertDontSee('token=secret');
        $this->assertCount(10, array_unique(array_column($res->json('data.projects'), 'slug')));
    }

    public function test_invalid_language_and_arbitrary_page_input_fail_closed(): void
    {
        foreach ([
            '/api/v1/public/expertise/ai?lang=fr',
            '/api/v1/public/expertise/ai?lang[]=ar',
            '/api/v1/public/expertise/ai?page=2',
            '/api/v1/public/expertise/ai?page[]=1',
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
    }
}

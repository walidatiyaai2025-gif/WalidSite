<?php

namespace Tests\Feature\Api;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicPortfolioSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $visibility = 'public', bool $published = true): Project
    {
        return Project::create([
            'slug' => Str::lower(Str::random(12)),
            'title' => 'Reviewed source project',
            'summary_en' => 'Evidence supported work',
            'summary_ar' => 'عمل معتمد بالمصادر',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => $published ? now()->subDay() : null,
        ]);
    }

    private function proof(Project $project, string $visibility = 'public', bool $verified = true, string $url = 'https://docs.example.test/public-proof'): void
    {
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'public_document',
            'url' => $url,
            'visibility' => $visibility,
            'verified_at' => $verified ? now()->subDay() : null,
        ]);
    }

    public function test_empty_contract_returns_no_fake_project_data(): void
    {
        $this->getJson('/api/v1/public/projects')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/public/profile')->assertOk()->assertJsonPath('data', null);
    }

    public function test_neither_unpublished_private_nor_unverified_projects_are_exposed(): void
    {
        $private = $this->project('private');
        $this->proof($private);
        $draft = $this->project('public', false);
        $this->proof($draft);
        $unverified = $this->project('public', true);
        $this->proof($unverified, 'public', false);
        $privateEvidence = $this->project('public');
        $this->proof($privateEvidence, 'private');

        $this->getJson('/api/v1/public/projects')
            ->assertOk()->assertJsonCount(0, 'data');
        foreach ([$private, $draft, $unverified, $privateEvidence] as $project) {
            $this->getJson('/api/v1/public/projects/'.$project->slug)->assertNotFound();
        }
    }

    public function test_verified_public_project_returns_real_sanitized_en_and_ar_dto(): void
    {
        $project = $this->project();
        $this->proof($project);
        $this->proof($project, 'private', true, 'https://private.example.test/not-public');
        $this->proof($project, 'public', true, 'https://github.com/owner/private');
        $this->proof($project, 'public', true, 'https://docs.example.test/?token=secret');

        $this->getJson('/api/v1/public/projects')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $project->slug)
            ->assertJsonPath('data.0.name', 'Reviewed source project')
            ->assertJsonPath('data.0.role', 'Engineer')
            ->assertJsonPath('data.0.public_repo_url', null)
            ->assertJsonCount(1, 'data.0.evidence_urls')
            ->assertJsonPath('data.0.evidence_urls.0', 'https://docs.example.test/public-proof')
            ->assertDontSee('secret')->assertDontSee('private.example.test');

        $this->getJson('/api/v1/public/projects/'.$project->slug.'?lang=ar')
            ->assertOk()->assertJsonPath('data.role', 'مهندس')
            ->assertJsonPath('data.summary', 'عمل معتمد بالمصادر');
    }

    public function test_raw_owner_import_is_hidden_until_approved_evidenced_claims(): void
    {
        $user = User::create(['email' => 'profile@example.test', 'status' => 'pending_activation']);
        $profile = Profile::create([
            'user_id' => $user->id, 'public_name' => 'Source Name',
            'bio_en' => 'Unverified private CV details',
            'bio_ar' => 'بيانات سيرة غير محققة',
            'publish_status' => 'review',
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Confidential assertion',
            'claim_ar' => 'ادعاء غير منشور',
            'status' => 'review',
            'is_public' => false,
        ]);
        $this->getJson('/api/v1/public/profile')->assertJsonPath('data', null)->assertDontSee('Confidential');

        $profile->update(['publish_status' => 'published']);
        $this->getJson('/api/v1/public/profile')->assertJsonPath('data.name', 'Source Name')
            ->assertJsonCount(0, 'data.claims')->assertDontSee('Unverified private CV');

        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Reviewed public claim',
            'claim_ar' => 'معلومة موثقة',
            'evidence_url' => 'https://docs.example.test/proof',
            'status' => 'approved',
            'is_public' => true,
        ]);
        $this->getJson('/api/v1/public/profile?lang=ar')
            ->assertJsonPath('data.claims.0.text', 'معلومة موثقة')
            ->assertJsonPath('data.claims.0.evidence_url', 'https://docs.example.test/proof')
            ->assertDontSee('Confidential assertion');
    }
}

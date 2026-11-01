<?php

namespace Tests\Feature\Api;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Role;
use App\Models\ProjectEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_internal_evidence_links_never_qualify_as_public_project_proof(): void
    {
        $unproven = $this->project();
        foreach ([
            'https://127.0.0.1/admin',
            'https://[::1]/private',
            'https://localhost/login',
            'https://service.internal/status',
            'https://server.local/config',
            'https://metadata.kubernetes.default.svc/secrets',
            'https://printer.lan/admin',
            'https://gateway.home/status',
            'https://router.home.arpa/private',
            'https://service.localdomain/internal',
            'https://ROUTER.HOME.ARPA./config',
            'https://docs.example.org:8443/private',
        ] as $url) {
            $this->proof($unproven, 'public', true, $url);
        }

        $safe = $this->project();
        $this->proof($safe, 'public', true, 'https://docs.example.test/public-proof');
        $this->proof($safe, 'public', true, 'https://10.1.2.3/internal');

        $this->getJson('/api/v1/public/projects')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $safe->slug)
            ->assertJsonCount(1, 'data.0.evidence_urls')
            ->assertDontSee($unproven->slug)
            ->assertDontSee('10.1.2.3');

        $this->getJson('/api/v1/public/projects/'.$unproven->slug)->assertNotFound();
        $this->getJson('/api/v1/public/projects/'.$safe->slug)
            ->assertOk()->assertJsonCount(1, 'data.evidence_urls')
            ->assertJsonPath('data.evidence_urls.0', 'https://docs.example.test/public-proof');
    }

    public function test_private_dns_evidence_cannot_be_published_as_owner_claim_proof(): void
    {
        $user = User::create([
            'email' => 'dns-claim-owner@example.test', 'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Verified Portfolio Owner',
            'publish_status' => 'published',
        ]);

        foreach ([
            'https://service.svc/internal',
            'https://router.lan/console',
            'https://node.home/status',
            'https://gateway.home.arpa/private',
            'https://host.localdomain/diagnostic',
        ] as $index => $unsafe) {
            ProfessionalClaim::create([
                'profile_id' => $profile->id,
                'claim_en' => 'Unusable private DNS claim '.$index,
                'claim_ar' => 'ادعاء شبكة خاصة '.$index,
                'evidence_url' => $unsafe,
                'status' => 'approved',
                'is_public' => true,
            ]);
        }
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Independent public evidence',
            'claim_ar' => 'دليل عام صالح',
            'evidence_url' => 'https://docs.example.test/approved',
            'status' => 'approved',
            'is_public' => true,
        ]);

        $this->getJson('/api/v1/public/profile?lang=ar')->assertOk()
            ->assertJsonPath('data.name', 'Verified Portfolio Owner')
            ->assertJsonCount(1, 'data.claims')
            ->assertJsonPath('data.claims.0.text', 'دليل عام صالح')
            ->assertJsonPath('data.claims.0.evidence_url', 'https://docs.example.test/approved')
            ->assertDontSee('service.svc')
            ->assertDontSee('router.lan')
            ->assertDontSee('gateway.home.arpa')
            ->assertDontSee('Unusable private DNS claim');
    }

    public function test_project_page_counts_exclude_unsafe_evidence_without_leaking_hidden_rows(): void
    {
        $unsafe = $this->project();
        $this->proof($unsafe, 'public', true, 'https://docs.example.test/private?access_token=secret');
        $this->proof($unsafe, 'public', true, 'https://10.1.0.2/admin');

        $this->getJson('/api/v1/public/projects')
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 0)
            ->assertDontSee($unsafe->slug)
            ->assertDontSee('access_token');

        $this->getJson('/api/v1/public/projects/'.$unsafe->slug)->assertNotFound();
    }

    public function test_project_pages_are_filled_from_safe_evidence_and_report_exact_totals(): void
    {
        $hiddenSlugs = [];
        foreach (range(1, 13) as $i) {
            // Unsafe records rank first in the SQL sort order, but MUST NOT
            // occupy the public API's page slots or inflate its total.
            $unsafe = $this->project();
            $unsafe->update(['published_at' => now()->subMinutes(2)]);
            $this->proof($unsafe, 'public', true, 'https://docs.example.test/doc?token=private');
            $hiddenSlugs[] = $unsafe->slug;
        }

        $publicSlugs = [];
        foreach (range(1, 13) as $i) {
            $safe = $this->project();
            $safe->update(['published_at' => now()->subDays(3)]);
            $this->proof($safe, 'public', true, 'https://docs.example.test/public-proof');
            $publicSlugs[] = $safe->slug;
        }

        $first = $this->getJson('/api/v1/public/projects?page=1');
        $first->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);
        $second = $this->getJson('/api/v1/public/projects?page=2');
        $second->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);
        $third = $this->getJson('/api/v1/public/projects?page=3');
        $third->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('meta.last_page', 2);

        $returnedSlugs = array_merge(
            array_column($first->json('data'), 'slug'),
            array_column($second->json('data'), 'slug')
        );
        $this->assertCount(13, array_unique($returnedSlugs));
        $this->assertEqualsCanonicalizing($publicSlugs, $returnedSlugs);
        foreach ($hiddenSlugs as $slug) {
            $first->assertDontSee($slug);
            $second->assertDontSee($slug);
        }
        $first->assertDontSee('token=private');
    }

    public function test_project_proof_revocation_immediately_updates_public_data_and_count(): void
    {
        $project = $this->project();
        $this->proof($project, 'public', true, 'https://docs.example.test/approved');

        $this->getJson('/api/v1/public/projects')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

        // A previously approved record remains otherwise published but its
        // sole evidence link is no longer safe for anonymous use.
        ProjectEvidence::where('project_id', $project->id)
            ->update(['url' => 'https://docs.example.test/approved?session=revoked']);

        $this->getJson('/api/v1/public/projects')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1)
            ->assertDontSee($project->slug);
        $this->getJson('/api/v1/public/projects/'.$project->slug)->assertNotFound();
    }

    public function test_public_project_listing_has_chunk_bounded_evidence_queries(): void
    {
        foreach (range(1, 125) as $i) {
            $project = $this->project();
            $this->proof($project, 'public', true, 'https://docs.example.test/proof');
        }

        $evidenceQueries = 0;
        DB::listen(static function ($event) use (&$evidenceQueries): void {
            if (str_contains(strtolower($event->sql), 'project_evidence')) {
                $evidenceQueries++;
            }
        });

        $this->getJson('/api/v1/public/projects')->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.total', 125)
            ->assertJsonPath('meta.last_page', 11);

        // Two lazy() batches plus the final end-of-cursor query, NOT 125
        // separate evidence selects. The upper bound also tolerates the
        // portable whereHas() existence checks on SQLite and MariaDB.
        $this->assertLessThanOrEqual(8, $evidenceQueries,
            'Project evidence must be loaded by chunk, not once per project.');
    }

    public function test_eager_loaded_public_evidence_obeys_per_project_fifty_row_cap(): void
    {
        $expectedVisible = [];
        $all = [];
        foreach ([1, 2, 3] as $group) {
            $project = $this->project();
            $all[$group] = $project;
            foreach (range(1, 51) as $index) {
                $shouldBeSafe = ($group < 3 && $index === 1)
                    || ($group === 3 && $index === 51);
                $url = $shouldBeSafe
                    ? 'https://docs.example.test/approved-'.$group
                    : 'https://docs.example.test/proof?token=private';

                $evidence = new ProjectEvidence([
                    'project_id' => $project->id,
                    'evidence_type' => 'public_document',
                    'url' => $url,
                    'visibility' => 'public',
                    'verified_at' => now()->subDay(),
                ]);
                // Enforce deterministic UUID sorting independent of wall clock.
                $evidence->id = sprintf('00000000-0000-4000-8000-%012d', $group * 100 + $index);
                $evidence->save();
            }
            if ($group < 3) {
                $expectedVisible[] = $project->slug;
            }
        }

        $list = $this->getJson('/api/v1/public/projects');
        $list->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')
            ->assertDontSee('token=private');
        $this->assertEqualsCanonicalizing($expectedVisible,
            array_column($list->json('data'), 'slug'));

        foreach ([1, 2] as $group) {
            $this->getJson('/api/v1/public/projects/'.$all[$group]->slug)
                ->assertOk()->assertJsonCount(1, 'data.evidence_urls')
                ->assertJsonPath('data.evidence_urls.0',
                    'https://docs.example.test/approved-'.$group);
        }
        // A safe 51st link cannot bypass the first-50 limit on either route.
        $list->assertDontSee($all[3]->slug);
        $this->getJson('/api/v1/public/projects/'.$all[3]->slug)
            ->assertNotFound();
    }

    public function test_public_profile_ignores_non_owner_and_inactive_owner_records(): void
    {
        $other = User::create(['email' => 'unrelated@example.test', 'status' => 'active']);
        Profile::create([
            'user_id' => $other->id,
            'public_name' => 'NON OWNER PRIVATE NAME',
            'publish_status' => 'published',
        ]);

        $inactive = User::create(['email' => 'inactive-owner@example.test', 'status' => 'disabled']);
        $inactive->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        Profile::create([
            'user_id' => $inactive->id,
            'public_name' => 'INACTIVE OWNER PRIVATE NAME',
            'publish_status' => 'published',
        ]);

        $this->getJson('/api/v1/public/profile')->assertOk()->assertJsonPath('data', null)
            ->assertDontSee('PRIVATE NAME');

        $owner = User::create(['email' => 'active-owner@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Approved Public Owner',
            'publish_status' => 'published',
        ]);

        $this->getJson('/api/v1/public/profile')->assertOk()
            ->assertJsonPath('data.name', 'Approved Public Owner')
            ->assertJsonCount(0, 'data.claims')
            ->assertDontSee('NON OWNER PRIVATE NAME')
            ->assertDontSee('INACTIVE OWNER PRIVATE NAME');
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
        // A published but inactive/non-owner record must remain private.
        $this->getJson('/api/v1/public/profile')->assertJsonPath('data', null)
            ->assertDontSee('Source Name');
        $user->update(['status' => 'active']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
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

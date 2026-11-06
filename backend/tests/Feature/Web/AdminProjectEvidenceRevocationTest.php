<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADMIN-09: revoke a once-approved proof without leaking source credentials,
 * leaving a public case study visible only while safe approved proof exists.
 */
final class AdminProjectEvidenceRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests exercise multiple negative cases; the real routes keep
        // production throttling. No network is needed to inspect proof URLs.
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
    }

    private function account(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(18)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function project(bool $published = true): Project
    {
        return Project::create([
            'slug' => 'revokable-'.Str::lower(Str::random(12)),
            'title' => 'Revokable public portfolio project',
            'summary_en' => 'Source reviewed public engineering outcome.',
            'summary_ar' => 'إنجاز تقني منشور يعتمد على مصدر تمت مراجعته.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $published ? 'public' : 'private',
            'published_at' => $published ? now()->subMinute() : null,
        ]);
    }

    private function proof(Project $project, string $label)
    {
        return $project->evidence()->create([
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/'.$label,
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);
    }

    public function test_revoking_last_approved_source_atomically_withdraws_public_project_and_audits(): void
    {
        $owner = $this->account('owner');
        $project = $this->project();
        $source = $this->proof($project, 'last-public-proof');
        $this->actingAs($owner, 'web');

        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->get('/projects/'.$project->slug)->assertOk();

        $review = $this->get('/admin/projects/'.$project->id.'/evidence/approved?lang=ar')
            ->assertOk()
            ->assertSee('إلغاء اعتماد الأدلة المنشورة')
            ->assertSee($source->url)
            ->assertSee('name="revocation_confirmed"', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, substr_count($review->getContent(), 'name="revocation_confirmed"'));
        $this->get('/admin/projects?lang=en')->assertOk()
            ->assertSee('Review or revoke approved evidence');

        $this->post('/admin/projects/'.$project->id.'/evidence/'.$source->id.'/revoke?lang=ar', [
            'revocation_confirmed' => '1',
        ])->assertRedirect(route('admin.operations.projects.evidence.approved', [
            'project' => $project->id, 'lang' => 'ar', 'page' => 1,
        ]))->assertSessionHas('status',
            'أُلغي اعتماد الدليل وسُحب المشروع تلقائياً لعدم بقاء دليل عام صالح.');

        $this->assertDatabaseHas('project_evidence', [
            'id' => $source->id, 'visibility' => 'private', 'verified_at' => null,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'visibility' => 'private', 'published_at' => null,
        ]);
        $this->assertDatabaseHas('project_audit_events', [
            'project_id' => $project->id, 'evidence_id' => $source->id,
            'actor_id' => $owner->id, 'action' => 'evidence_revoked',
        ]);
        $this->assertDatabaseHas('project_audit_events', [
            'project_id' => $project->id, 'actor_id' => $owner->id,
            'action' => 'project_withdrawn',
        ]);
        $this->assertFalse(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->get('/projects/'.$project->slug)->assertNotFound();
        $this->get('/projects')->assertOk()->assertDontSee($project->title);
        $this->get('/admin/audit?domain=project&action=evidence_revoked')
            ->assertOk()->assertSee('evidence_revoked')->assertDontSee($source->url);
        $this->post('/admin/projects/'.$project->id.'/evidence/'.$source->id.'/revoke', [
            'revocation_confirmed' => '1',
        ])->assertStatus(409);
        $this->assertSame(1, DB::table('project_audit_events')
            ->where('project_id', $project->id)->where('action', 'evidence_revoked')->count());
        Http::assertNothingSent();
    }

    public function test_revoking_one_of_two_verified_links_preserves_other_proof_and_published_case_study(): void
    {
        $project = $this->project();
        $revoked = $this->proof($project, 'revoked-proof');
        $retained = $this->proof($project, 'retained-proof');
        $this->actingAs($this->account('owner'), 'web');

        $this->post('/admin/projects/'.$project->id.'/evidence/'.$revoked->id.'/revoke?lang=en', [
            'revocation_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('status',
            'Evidence revoked; other reviewed sources remain unchanged.');

        $project->refresh();
        $this->assertSame('public', $project->visibility);
        $this->assertNotNull($project->published_at);
        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->get('/projects/'.$project->slug)->assertOk()
            ->assertSee($retained->url)->assertDontSee($revoked->url);
        $this->assertDatabaseHas('project_evidence', [
            'id' => $retained->id, 'visibility' => 'public',
        ]);
        $this->assertSame(0, DB::table('project_audit_events')
            ->where('project_id', $project->id)->where('action', 'project_withdrawn')->count());
        Http::assertNothingSent();
    }

    public function test_only_owner_can_revoke_and_forged_or_stale_actions_fail_closed(): void
    {
        $project = $this->project();
        $source = $this->proof($project, 'protected-proof');
        $other = $this->project();
        $path = '/admin/projects/'.$project->id.'/evidence/'.$source->id.'/revoke';
        $listing = '/admin/projects/'.$project->id.'/evidence/approved';

        $this->post($path, ['revocation_confirmed' => '1'])
            ->assertRedirect('/admin/login');
        $this->get($listing)->assertRedirect('/admin/login');

        foreach (['reader', 'admin'] as $role) {
            $this->actingAs($this->account($role), 'web');
            $this->get($listing)->assertForbidden();
            $this->post($path, ['revocation_confirmed' => '1'])->assertForbidden();
            if ($role === 'admin') {
                $this->get('/admin/projects')->assertOk()
                    ->assertDontSee('Review or revoke approved evidence');
            } else {
                $this->get('/admin/projects')->assertForbidden();
            }
        }

        $this->actingAs($this->account('owner'), 'web');
        $this->post($path)->assertRedirect()->assertSessionHasErrors('revocation_confirmed');
        $this->post($path, ['revocation_confirmed' => '0'])
            ->assertRedirect()->assertSessionHasErrors('revocation_confirmed');
        $this->post($path, [
            'revocation_confirmed' => '1', 'verified_at' => now()->toIso8601String(),
        ])->assertRedirect()->assertSessionHasErrors('evidence');
        $this->post($path.'?lang=fr', ['revocation_confirmed' => '1'])
            ->assertRedirect()->assertSessionHasErrors('lang');
        $this->post('/admin/projects/'.$other->id.'/evidence/'.$source->id.'/revoke', [
            'revocation_confirmed' => '1',
        ])->assertNotFound();
        $this->post('/admin/projects/not-a-uuid/evidence/'.$source->id.'/revoke', [
            'revocation_confirmed' => '1',
        ])->assertNotFound();
        $this->get($listing.'?page=invalid')->assertRedirect()
            ->assertSessionHasErrors('page');
        $this->assertSame('public', $source->fresh()->visibility);
        $this->assertNotNull($source->fresh()->verified_at);
        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->assertDatabaseMissing('project_audit_events', ['action' => 'evidence_revoked']);
        Http::assertNothingSent();
    }

    public function test_owner_can_revoke_previously_approved_source_on_private_draft_without_publishing(): void
    {
        $project = $this->project(false);
        $source = $this->proof($project, 'reviewed-private-project-proof');
        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/projects/'.$project->id.'/evidence/approved')->assertOk()
            ->assertSee($source->url);

        $this->post('/admin/projects/'.$project->id.'/evidence/'.$source->id.'/revoke', [
            'revocation_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);
        $this->assertNull($source->fresh()->verified_at);
        $this->get('/admin/projects/'.$project->id.'/evidence?lang=en')
            ->assertOk()->assertSee($source->url);
        $this->post('/admin/projects/'.$project->id.'/publish', [
            'publication_confirmed' => '1',
        ])->assertRedirect()->assertSessionHasErrors('project');
        Http::assertNothingSent();
    }
}

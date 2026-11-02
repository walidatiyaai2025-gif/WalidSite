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

final class AdminProjectDurableAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prove transition permissions rather than exercise rate limits
        // across a repeated matrix. Production throttling stays enabled.
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
    }

    private function user(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function project(): Project
    {
        return Project::create([
            'slug' => 'audited-'.Str::lower(Str::random(12)),
            'title' => 'Audited technical project',
            'summary_en' => 'Reviewed English technical project summary',
            'summary_ar' => 'وصف مشروع عربي تقني قابل للمراجعة',
            'role_en' => 'Software engineer',
            'role_ar' => 'مهندس برمجيات',
            'status' => 'research',
            'visibility' => 'private',
        ]);
    }

    public function test_real_owner_project_journey_persists_five_redacted_transactional_decisions(): void
    {
        $owner = $this->user('owner');
        $project = $this->project();
        $this->actingAs($owner, 'web');

        $base = '/admin/projects/'.$project->id;
        $privateUrl = 'https://docs.example.org/PRIVATE-REVIEW-URL-DO-NOT-LEAK';
        $this->post($base.'/evidence', [
            'evidence_type' => 'document', 'url' => $privateUrl,
        ])->assertRedirect()->assertSessionHas('status');
        $first = $project->evidence()->firstOrFail();
        $this->assertNull($first->verified_at);
        $this->assertSame('private', $first->visibility);

        $draft = [
            'title' => 'Edited technical project',
            'summary_en' => 'Reviewed English technical project summary',
            'summary_ar' => 'وصف مشروع عربي تقني قابل للمراجعة',
            'role_en' => 'Software engineer',
            'role_ar' => 'مهندس برمجيات',
        ];
        $this->patch($base, $draft)->assertRedirect()->assertSessionHas('status');
        $this->post($base.'/evidence/'.$first->id.'/approve', [
            'source_reviewed' => '1', 'publication_rights_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $this->post($base.'/publish', ['publication_confirmed' => '1'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('public', $project->fresh()->visibility);
        $this->assertNotNull($project->fresh()->published_at);

        $actions = DB::table('project_audit_events')
            ->where('project_id', $project->id)->orderBy('created_at')->get();
        $this->assertCount(4, $actions);
        $this->assertEqualsCanonicalizing([
            'evidence_submitted', 'draft_updated', 'evidence_approved', 'project_published',
        ], $actions->pluck('action')->all());
        $this->assertEqualsCanonicalizing(
            [$first->id, $first->id],
            $actions->whereNotNull('evidence_id')->pluck('evidence_id')->all()
        );
        foreach ($actions as $row) {
            $this->assertSame($owner->id, $row->actor_id);
        }

        $audit = $this->get('/admin/audit?domain=project&lang=en')->assertOk()
            ->assertSee('Project &amp; evidence decisions', false)
            ->assertSee('evidence_approved')
            ->assertSee('project_published')
            ->assertSee($project->id)
            ->assertDontSee($privateUrl)
            ->assertDontSee($owner->email)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin/audit?domain=project&lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('سجل قرارات المشاريع والأدلة')
            ->assertDontSee($privateUrl);

        $csv = $this->get('/admin/audit?domain=project&lang=en&format=csv')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Export-Truncated', 'false');
        $body = $csv->streamedContent();
        $this->assertStringContainsString('evidence_approved', $body);
        $this->assertStringContainsString('project_published', $body);
        $this->assertStringContainsString($project->id, $body);
        $this->assertStringNotContainsString($privateUrl, $body);
        $this->assertStringNotContainsString($owner->email, $body);
        Http::assertNothingSent();
    }

    public function test_discard_keeps_irreversible_receipt_and_denied_mutations_never_emit_success_events(): void
    {
        $owner = $this->user('owner');
        $project = $this->project();
        $this->actingAs($owner, 'web');
        $id = $project->id;
        $url = 'https://docs.example.org/OWNER-PRIVATE-SOURCE';
        $this->post("/admin/projects/{$id}/evidence", [
            'evidence_type' => 'document', 'url' => $url,
        ])->assertRedirect()->assertSessionHas('status');
        $candidate = $project->evidence()->firstOrFail();

        $other = $this->project();
        $this->delete('/admin/projects/'.$other->id.'/evidence/'.$candidate->id)
            ->assertNotFound();
        $this->post("/admin/projects/{$id}/evidence/{$candidate->id}/approve", [
            'source_reviewed' => '1',
        ])->assertRedirect()->assertSessionHasErrors('publication_rights_confirmed');
        $this->post("/admin/projects/{$id}/publish", ['publication_confirmed' => '1'])
            ->assertRedirect()->assertSessionHasErrors('project');

        $this->actingAs($this->user('admin'), 'web');
        $this->delete("/admin/projects/{$id}/evidence/{$candidate->id}")
            ->assertForbidden();
        $this->post("/admin/projects/{$id}/publish", ['publication_confirmed' => '1'])
            ->assertForbidden();

        $this->actingAs($owner, 'web');
        $this->delete("/admin/projects/{$id}/evidence/{$candidate->id}")
            ->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseMissing('project_evidence', ['id' => $candidate->id]);
        $this->delete("/admin/projects/{$id}/evidence/{$candidate->id}")
            ->assertNotFound();

        $events = DB::table('project_audit_events')
            ->where('project_id', $id)->orderBy('created_at')->get();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(
            ['evidence_submitted', 'evidence_discarded'],
            $events->pluck('action')->all()
        );
        $this->assertSame($candidate->id, $events->last()->evidence_id);
        $this->assertSame('private', $project->fresh()->visibility);
        $this->get('/admin/audit?domain=project')->assertOk()
            ->assertSee('evidence_discarded')->assertDontSee($url);
        Http::assertNothingSent();
    }

    public function test_project_audit_is_admin_only_and_filters_csv_and_dates_are_fail_closed(): void
    {
        $project = $this->project();
        $actor = $this->user('owner');
        $this->actingAs($actor, 'web');
        $this->patch('/admin/projects/'.$project->id, [
            'title' => $project->title,
            'summary_en' => $project->summary_en,
            'summary_ar' => $project->summary_ar,
            'role_en' => $project->role_en,
            'role_ar' => $project->role_ar,
        ])->assertRedirect();

        $this->get('/admin/audit?domain=project&action=draft_updated')
            ->assertOk()->assertSee('draft_updated');
        $this->get('/admin/audit?domain=editorial')
            ->assertOk()->assertDontSee('draft_updated');
        $this->get('/admin/audit?domain=project&action=media_rights_approved')
            ->assertOk()->assertDontSee('draft_updated');
        $this->get('/admin/audit?domain=project&day=2000-01-01')
            ->assertOk()->assertDontSee('draft_updated');
        foreach ([
            'domain=unknown', 'domain[]=project', 'project_page=0',
            'project_page[]=3', 'action[]=draft_updated', 'format=xlsx',
            'day=2099-12-30',
        ] as $query) {
            $this->get('/admin/audit?'.$query)->assertRedirect()
                ->assertSessionHasErrors();
        }

        $this->actingAs($this->user('reader'), 'web');
        $this->get('/admin/audit?domain=project')->assertForbidden();
        $this->get('/admin/audit?domain=project&format=csv')->assertForbidden();

        auth('web')->logout();
        $this->get('/admin/audit?domain=project')->assertRedirect('/admin/login');
        $this->assertDatabaseCount('project_audit_events', 1);
        Http::assertNothingSent();
    }
}

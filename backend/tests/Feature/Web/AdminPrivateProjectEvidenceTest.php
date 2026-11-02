<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminPrivateProjectEvidenceTest extends TestCase
{
    use RefreshDatabase;

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

    private function project(bool $published = false): Project
    {
        return Project::create([
            'slug' => 'private-evidence-'.Str::lower(Str::random(12)),
            'title' => 'Project awaiting independent evidence review',
            'summary_en' => 'Unverified private technical project.',
            'summary_ar' => 'مسودة مشروع تقنية خاصة غير معتمدة.',
            'status' => 'research',
            'visibility' => $published ? 'public' : 'private',
            'published_at' => $published ? now()->subDay() : null,
        ]);
    }

    private function form(string $url = 'https://github.com/verified-owner/public-project'): array
    {
        return ['evidence_type' => 'repository', 'url' => $url];
    }

    public function test_only_owner_can_queue_private_unpublished_project_evidence(): void
    {
        Http::fake();
        $project = $this->project();
        $path = '/admin/projects/'.$project->id.'/evidence';

        $this->post($path, $this->form())->assertRedirect('/admin/login');
        foreach (['reader', 'admin'] as $role) {
            $this->actingAs($this->account($role), 'web')
                ->post($path, $this->form())->assertForbidden();
        }
        $this->assertDatabaseCount('project_evidence', 0);

        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/projects?lang=ar')->assertOk()
            ->assertSee('إضافة دليل خاص للمراجعة')
            ->assertSee('name="evidence_type"', false)
            ->assertSee('name="url"', false)
            ->assertSee('name="_token"', false);
        $this->post($path.'?lang=ar', $this->form())
            ->assertRedirect(route('admin.operations.projects', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تمت إضافة الدليل إلى المسودة الخاصة للمراجعة دون نشر أو اعتماد.');
        $this->assertDatabaseHas('project_evidence', [
            'project_id' => $project->id,
            'evidence_type' => 'repository',
            'url' => 'https://github.com/verified-owner/public-project',
            'visibility' => 'private',
            'verified_at' => null,
        ]);
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);
        $this->get('/admin/projects')->assertOk()->assertSee('0 / 1');
        Http::assertNothingSent();
    }

    public function test_duplicate_urls_and_published_projects_fail_closed(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $project = $this->project();
        $path = '/admin/projects/'.$project->id.'/evidence';

        $this->post($path, $this->form())->assertRedirect()->assertSessionHas('status');
        $this->post($path, $this->form('https://GITHUB.com/verified-owner/public-project'))
            ->assertRedirect()->assertSessionHasErrors('url');
        $this->assertSame(1, DB::table('project_evidence')
            ->where('project_id', $project->id)->count());

        $published = $this->project(true);
        $this->post('/admin/projects/'.$published->id.'/evidence', $this->form())
            ->assertStatus(409);
        $this->get('/admin/projects?visibility=public')->assertOk()
            ->assertDontSee('Queue private evidence for review');
        $this->assertSame(1, DB::table('project_evidence')->count());
        Http::assertNothingSent();
    }

    public function test_disallowed_fields_and_unsafe_urls_never_create_review_evidence(): void
    {
        // This matrix intentionally sends more than ten requests. Isolate
        // validation from the independent production rate-limit protection.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $project = $this->project();
        $path = '/admin/projects/'.$project->id.'/evidence';
        foreach ([
            'http://github.com/owner/repo',
            'https://localhost/private',
            'https://127.0.0.1/internal',
            'https://example.internal/secret',
            'https://foo.nip.io/internal',
            'https://example.local/secret',
            'https://user:pass@github.com/owner/repo',
            'https://github.com:8443/owner/repo',
            'https://github.com/owner/repo?token=private',
            'https://github.com/owner/repo#private',
            'https://github.com/owner/repo%0A',
        ] as $url) {
            $this->post($path, $this->form($url))
                ->assertRedirect()->assertSessionHasErrors('url');
        }
        $this->post($path, ['evidence_type' => 'secrets', 'url' => 'https://github.com/owner/repo'])
            ->assertRedirect()->assertSessionHasErrors('evidence_type');
        $this->post($path, $this->form() + ['visibility' => 'public'])
            ->assertRedirect()->assertSessionHasErrors('evidence');
        $this->post($path, $this->form() + ['verified_at' => now()->toDateString()])
            ->assertRedirect()->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('project_evidence', 0);
        Http::assertNothingSent();
    }

    public function test_private_review_queue_is_owner_only_and_rejection_keeps_project_unpublished(): void
    {
        Http::fake();
        $project = $this->project();
        $owner = $this->account('owner');
        $this->actingAs($owner, 'web');
        $this->post('/admin/projects/'.$project->id.'/evidence', $this->form())->assertRedirect();
        $candidate = $project->evidence()->firstOrFail();
        $discard = '/admin/projects/'.$project->id.'/evidence/'.$candidate->id;

        $this->actingAs($this->account('admin'), 'web');
        $this->get('/admin/projects')->assertOk()
            ->assertDontSee($candidate->url)
            ->assertDontSee('Discard unverified evidence');
        $this->delete($discard)->assertForbidden();
        $this->assertDatabaseHas('project_evidence', ['id' => $candidate->id]);

        $this->actingAs($owner, 'web');
        $this->get('/admin/projects?lang=en')->assertOk()
            ->assertSee('Review pending private evidence')
            ->assertSee($candidate->url)
            ->assertSee('Discard unverified evidence');
        $this->delete($discard.'?lang=ar')->assertRedirect(
            route('admin.operations.projects', ['lang' => 'ar'])
        )->assertSessionHas('status', 'تم رفض الدليل الخاص غير المعتمد.');
        $this->assertDatabaseMissing('project_evidence', ['id' => $candidate->id]);
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);
        $this->delete($discard)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_review_rejects_other_project_candidates_public_or_verified_evidence_and_extra_fields(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $project = $this->project();
        $other = $this->project();
        foreach ([$project, $other] as $item) {
            $this->post('/admin/projects/'.$item->id.'/evidence', $this->form())->assertRedirect();
        }
        $candidate = $project->evidence()->firstOrFail();
        $path = '/admin/projects/'.$project->id.'/evidence/'.$candidate->id;
        $this->delete($path, ['visibility' => 'public'])->assertRedirect()
            ->assertSessionHasErrors('evidence');
        $this->delete('/admin/projects/'.$other->id.'/evidence/'.$candidate->id)->assertNotFound();
        $candidate->update(['verified_at' => now(), 'visibility' => 'public']);
        $this->delete($path)->assertStatus(409);
        $this->assertDatabaseHas('project_evidence', ['id' => $candidate->id]);
        $published = $this->project(true);
        $this->delete('/admin/projects/'.$published->id.'/evidence/'.$candidate->id)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_accepted_evidence_types_stay_private_and_do_not_auto_verify(): void
    {
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $project = $this->project();
        foreach (['document', 'repository', 'publication'] as $index => $type) {
            $this->post('/admin/projects/'.$project->id.'/evidence?lang=en', [
                'evidence_type' => $type,
                'url' => 'https://docs.example.org/public-proof-'.$index,
            ])->assertRedirect()->assertSessionHas('status');
        }
        $this->assertSame(3, DB::table('project_evidence')
            ->where('project_id', $project->id)
            ->where('visibility', 'private')->whereNull('verified_at')->count());
        Http::assertNothingSent();
    }
    public function test_owner_can_review_every_pending_private_candidate_beyond_first_ten_without_fetching_urls(): void
    {
        Http::fake();
        $owner = $this->account('owner');
        $project = $this->project();
        for ($number = 1; $number <= 12; $number++) {
            $candidate = $project->evidence()->create([
                'evidence_type' => 'document',
                'url' => 'https://docs.example.org/private-proof-'.sprintf('%02d', $number),
                'visibility' => 'private',
                'verified_at' => null,
            ]);
            $candidate->forceFill([
                'created_at' => now()->subMinutes(13 - $number),
            ])->save();
        }

        $this->actingAs($owner, 'web');
        $this->get('/admin/projects?lang=en')->assertOk()
            ->assertSee('Review all private evidence (paginated)')
            ->assertSee(route('admin.operations.projects.evidence.review', [
                'project' => $project->id, 'lang' => 'en',
            ]), false);

        $pageOne = $this->get('/admin/projects/'.$project->id.'/evidence?lang=en');
        $pageOne->assertOk()->assertSee('Pending private evidence review')
            ->assertSee('private-proof-12')->assertDontSee('private-proof-01')
            ->assertSee('Evidence review pages')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');

        $pageTwo = $this->get('/admin/projects/'.$project->id.'/evidence?lang=ar&page=2');
        $pageTwo->assertOk()->assertSee('مراجعة الأدلة الخاصة المعلقة')
            ->assertSee('dir="rtl"', false)
            ->assertSee('private-proof-01')->assertSee('private-proof-02')
            ->assertDontSee('private-proof-12')
            ->assertSee('name="_token"', false)
            ->assertSee('رفض الدليل غير المعتمد')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('project_evidence', 12);
        Http::assertNothingSent();
    }

    public function test_owner_discard_from_later_review_page_returns_to_same_private_queue(): void
    {
        Http::fake();
        $project = $this->project();
        $candidate = $project->evidence()->create([
            'evidence_type' => 'publication',
            'url' => 'https://example.org/private-evidence',
            'visibility' => 'private',
            'verified_at' => null,
        ]);
        $this->actingAs($this->account('owner'), 'web');
        $path = '/admin/projects/'.$project->id.'/evidence/'.$candidate->id;
        $this->delete($path.'?return=review&page=2&lang=ar')
            ->assertRedirect(route('admin.operations.projects.evidence.review', [
                'project' => $project->id, 'lang' => 'ar', 'page' => 2,
            ]))->assertSessionHas('status', 'تم رفض الدليل الخاص غير المعتمد.');
        $this->assertDatabaseMissing('project_evidence', ['id' => $candidate->id]);
        $this->assertNull($project->fresh()->published_at);
        $this->assertSame('private', $project->fresh()->visibility);
        $this->delete($path.'?return=https://outside.example.org')
            ->assertRedirect()->assertSessionHasErrors('return');
        Http::assertNothingSent();
    }

    public function test_full_private_review_queue_rejects_guests_nonowners_public_projects_and_invalid_pages(): void
    {
        Http::fake();
        $private = $this->project();
        $public = $this->project(true);
        $path = '/admin/projects/'.$private->id.'/evidence';
        $this->get($path)->assertRedirect('/admin/login');
        foreach (['reader', 'admin'] as $role) {
            $this->actingAs($this->account($role), 'web')
                ->get($path.'?lang=ar')->assertForbidden()
                ->assertDontSee('private-proof');
            // The reader role is denied the admin operations index entirely.
            // Admin access is governed independently from owner-only evidence.
            $index = $this->get('/admin/projects');
            if ($role === 'reader') {
                $index->assertForbidden();
            } else {
                $index->assertOk()
                    ->assertDontSee('Review all private evidence (paginated)');
            }
        }
        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/projects/'.$public->id.'/evidence')->assertStatus(409);
        $this->get($path.'?page=0')->assertRedirect()->assertSessionHasErrors('page');
        $this->get($path.'?page[]=2')->assertRedirect()->assertSessionHasErrors('page');
        $this->get($path.'?lang=fr')->assertRedirect()->assertSessionHasErrors('lang');
        $this->get($path.'?lang=ar')->assertOk()
            ->assertSee('لا توجد أدلة معلقة في هذه الصفحة.');
        Http::assertNothingSent();
    }

    public function test_owner_explicitly_approves_source_and_rights_before_evidence_becomes_public(): void
    {
        // These matrices exercise validation/authorization repeatedly; keep the
        // production throttle enabled on the route but isolate it from feature assertions.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        $project = $this->project();
        $candidate = $project->evidence()->create([
            'evidence_type' => 'repository',
            'url' => 'https://github.com/verified-owner/reviewed-public-project',
            'visibility' => 'private',
            'verified_at' => null,
        ]);
        $path = '/admin/projects/'.$project->id.'/evidence/'.$candidate->id.'/approve';
        $confirmation = [
            'source_reviewed' => '1',
            'publication_rights_confirmed' => '1',
        ];

        $this->actingAs($this->account('admin'), 'web')
            ->post($path, $confirmation)->assertForbidden();
        $this->assertDatabaseHas('project_evidence', [
            'id' => $candidate->id, 'visibility' => 'private', 'verified_at' => null,
        ]);

        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/projects/'.$project->id.'/evidence?lang=en')->assertOk()
            ->assertSee('Approve evidence')
            ->assertSee('I reviewed the source itself and confirmed it supports this project.')
            ->assertSee('I confirmed this source may be shared publicly.')
            ->assertSee('Approved public evidence for this project');

        $this->post($path, ['source_reviewed' => '1'])
            ->assertRedirect()->assertSessionHasErrors('publication_rights_confirmed');
        $this->assertSame('private', $candidate->fresh()->visibility);
        $this->assertNull($candidate->fresh()->verified_at);

        $this->post($path.'?lang=ar&page=1', $confirmation)
            ->assertRedirect(route('admin.operations.projects.evidence.review', [
                'project' => $project->id, 'lang' => 'ar', 'page' => 1,
            ]))
            ->assertSessionHas('status', 'تم اعتماد الدليل للمشاركة العامة بعد تأكيد مراجعة المصدر وحقوق النشر. بقي المشروع خاصاً حتى خطوة النشر المنفصلة.');

        $candidate->refresh();
        $this->assertSame('public', $candidate->visibility);
        $this->assertNotNull($candidate->verified_at);
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);
        $this->get('/admin/projects/'.$project->id.'/evidence?lang=en')->assertOk()
            ->assertSee('Approved public evidence for this project')
            ->assertSee('Project publication gate')
            ->assertSee('Publish project')
            ->assertDontSee('reviewed-public-project');
        Http::assertNothingSent();
    }

    public function test_project_publication_requires_owner_confirmation_verified_evidence_and_complete_bilingual_content(): void
    {
        // These matrices exercise validation/authorization repeatedly; keep the
        // production throttle enabled on the route but isolate it from feature assertions.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        $project = $this->project();
        $project->forceFill([
            'role_en' => 'Database and delivery engineering',
            'role_ar' => 'هندسة قواعد البيانات والتسليم',
        ])->save();
        $path = '/admin/projects/'.$project->id.'/publish';

        $this->actingAs($this->account('owner'), 'web');
        $this->post($path, ['publication_confirmed' => '1'])
            ->assertRedirect()->assertSessionHasErrors('project');
        $this->assertSame('private', $project->fresh()->visibility);

        $candidate = $project->evidence()->create([
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/reviewed-project-proof',
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->account('admin'), 'web')
            ->post($path, ['publication_confirmed' => '1'])->assertForbidden();
        $this->assertSame('private', $project->fresh()->visibility);

        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/projects/'.$project->id.'/evidence?lang=en')->assertOk()
            ->assertSee('Project publication gate')
            ->assertSee('I confirm the project content and approved evidence are ready for public publication.');
        $this->post($path)->assertRedirect()
            ->assertSessionHasErrors('publication_confirmed');

        $this->post($path.'?lang=ar', ['publication_confirmed' => '1'])
            ->assertRedirect(route('admin.operations.projects', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم نشر المشروع بعد اجتياز بوابة الدليل المعتمد والمحتوى الثنائي.');

        $project->refresh();
        $this->assertSame('public', $project->visibility);
        $this->assertNotNull($project->published_at);
        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->assertDatabaseHas('project_evidence', [
            'id' => $candidate->id, 'visibility' => 'public',
        ]);
        $this->get('/admin/projects/'.$project->id.'/evidence')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_evidence_approval_and_project_publication_fail_closed_on_extra_fields_cross_project_or_incomplete_content(): void
    {
        // These matrices exercise validation/authorization repeatedly; keep the
        // production throttle enabled on the route but isolate it from feature assertions.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        $this->actingAs($this->account('owner'), 'web');
        $project = $this->project();
        $other = $this->project();
        $candidate = $project->evidence()->create([
            'evidence_type' => 'publication',
            'url' => 'https://example.org/source-reviewed-proof',
            'visibility' => 'private',
            'verified_at' => null,
        ]);
        $confirmation = [
            'source_reviewed' => '1',
            'publication_rights_confirmed' => '1',
        ];
        $approve = '/admin/projects/'.$project->id.'/evidence/'.$candidate->id.'/approve';

        $this->post($approve, $confirmation + ['verified_at' => now()->toIso8601String()])
            ->assertRedirect()->assertSessionHasErrors('evidence');
        $this->post('/admin/projects/'.$other->id.'/evidence/'.$candidate->id.'/approve', $confirmation)
            ->assertNotFound();
        $this->assertSame('private', $candidate->fresh()->visibility);
        $this->assertNull($candidate->fresh()->verified_at);

        $this->post($approve, $confirmation)->assertRedirect();
        $candidate->refresh();
        $this->post($approve, $confirmation)->assertStatus(409);

        // Evidence approval alone is insufficient: the draft still lacks
        // bilingual role fields, so publication must remain fail-closed.
        $this->post('/admin/projects/'.$project->id.'/publish', [
            'publication_confirmed' => '1',
        ])->assertRedirect()->assertSessionHasErrors('project');
        $this->post('/admin/projects/'.$project->id.'/publish', [
            'publication_confirmed' => '1',
            'visibility' => 'public',
        ])->assertRedirect()->assertSessionHasErrors('project');
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);
        Http::assertNothingSent();
    }

    public function test_project_publish_requires_reachable_proof_not_merely_reviewed_database_flags(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Http::fake();
        $project = $this->project();
        $project->forceFill([
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
        ])->save();

        // An approved GitHub URL alone has not passed the public repository
        // allowlist, and is excluded by the public portfolio serializer.
        $project->evidence()->create([
            'evidence_type' => 'repository',
            'url' => 'https://github.com/not-reviewed/private-repo',
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);
        $this->actingAs($this->account('owner'), 'web');
        $path = '/admin/projects/'.$project->id.'/publish';
        $confirm = ['publication_confirmed' => '1'];

        $this->post($path, $confirm)->assertRedirect()->assertSessionHasErrors('project');
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);

        $project->evidence()->create([
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/reviewed-project-proof',
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);
        $validSlug = $project->slug;
        $project->update(['slug' => 'invalid_slug']);
        $this->post($path, $confirm)->assertRedirect()->assertSessionHasErrors('project');
        $this->assertSame('private', $project->fresh()->visibility);
        $project->update(['slug' => $validSlug]);

        $this->post($path, $confirm)->assertRedirect()->assertSessionHas('status');
        $this->get('/projects/'.$validSlug)->assertOk()
            ->assertSee('https://docs.example.org/reviewed-project-proof')
            ->assertDontSee('not-reviewed/private-repo');
        Http::assertNothingSent();
    }

}

<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminProjectPublicationReadinessJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
    }

    private function user(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(18)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function project(string $title, array $fields = []): Project
    {
        return Project::create(array_merge([
            'slug' => 'readiness-'.Str::lower(Str::random(12)),
            'title' => $title,
            'summary_en' => 'Evidence-backed technical work',
            'summary_ar' => 'مشروع تقني تدعمه الأدلة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'research',
            'visibility' => 'private',
        ], $fields));
    }

    private function reviewed(Project $project, string $url): ProjectEvidence
    {
        return ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => now('UTC')->subMinute(),
        ]);
    }

    private function projectRow(string $html, Project $project): string
    {
        // Test the precise row, not a readiness label belonging to another
        // project in the same paginated admin response.
        $pattern = '#<tr><td>'.preg_quote($project->title, '#').'.*?</tr>#s';
        $this->assertSame(1, preg_match($pattern, $html, $matches));
        return $matches[0];
    }

    public function test_owner_sees_real_readiness_then_separately_approves_publishes_and_withdraws(): void
    {
        $owner = $this->user('owner');
        $project = $this->project('Bilingual source review lifecycle');
        $this->actingAs($owner, 'web');

        $initial = $this->get('/admin/projects?lang=en')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $initialRow = $this->projectRow($initial->getContent(), $project);
        $this->assertStringContainsString('Usable public evidence links', $initialRow);
        $this->assertStringContainsString('Publication blocked', $initialRow);
        $this->assertStringNotContainsString('Ready for a separate owner-confirmed', $initialRow);

        $base = '/admin/projects/'.$project->id;
        $privateUrl = 'https://docs.example.org/OWNER-REVIEWED-SOURCE';
        $this->post($base.'/evidence', [
            'evidence_type' => 'document',
            'url' => $privateUrl,
        ])->assertRedirect()->assertSessionHas('status');
        $candidate = $project->evidence()->firstOrFail();
        $this->assertSame('private', $candidate->visibility);
        $this->assertNull($candidate->verified_at);
        $this->assertStringContainsString(
            'Publication blocked',
            $this->projectRow($this->get('/admin/projects')->assertOk()->getContent(), $project)
        );

        $this->post($base.'/evidence/'.$candidate->id.'/approve', [
            'source_reviewed' => '1',
            'publication_rights_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/admin/projects?lang='.$locale)->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
            $row = $this->projectRow($page->getContent(), $project);
            $this->assertStringContainsString('1 / 1', $row);
            $this->assertStringContainsString(
                $locale === 'ar' ? 'روابط أدلة صالحة للعرض العام' : 'Usable public evidence links',
                $row
            );
            $this->assertStringContainsString(
                $locale === 'ar'
                    ? 'المشروع مؤهل لطلب النشر المنفصل'
                    : 'Ready for a separate owner-confirmed publication request.',
                $row
            );
        }
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);

        // The readiness indicator cannot publish on its own: this separate
        // owner-confirmed operation has the original transactional safeguards.
        $this->post($base.'/publish', ['publication_confirmed' => '1'])
            ->assertRedirect()->assertSessionHas('status');
        $this->get('/projects/'.$project->slug)->assertOk()
            ->assertSee($project->title);
        $this->assertSame('public', $project->fresh()->visibility);
        $this->post($base.'/withdraw', ['withdrawal_confirmed' => '1'])
            ->assertRedirect()->assertSessionHas('status');
        $this->get('/projects/'.$project->slug)->assertNotFound();
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertEqualsCanonicalizing([
            'evidence_submitted', 'evidence_approved',
            'project_published', 'project_withdrawn',
        ], DB::table('project_audit_events')->where('project_id', $project->id)
            ->pluck('action')->all());
        Http::assertNothingSent();
    }

    public function test_approved_flags_do_not_fake_safe_public_links_or_complete_bilingual_content(): void
    {
        $unsafe = $this->project('Unsafe repository URL');
        $unsafeUrl = 'https://github.com/example/private-or-unverified';
        $this->reviewed($unsafe, $unsafeUrl);

        $invalidSlug = $this->project('Unrouteable underscore project', [
            'slug' => 'unrouteable_project',
        ]);
        $this->reviewed($invalidSlug, 'https://docs.example.org/verified-public');

        $incomplete = $this->project('Missing Arabic professional role', ['role_ar' => null]);
        $this->reviewed($incomplete, 'https://docs.example.org/verified-role');

        $future = $this->project('Future dated review is not a grant');
        ProjectEvidence::create([
            'project_id' => $future->id, 'evidence_type' => 'document',
            'url' => 'https://docs.example.org/not-yet-approved',
            'visibility' => 'public', 'verified_at' => now('UTC')->addDay(),
        ]);

        $this->actingAs($this->user('owner'), 'web');
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/admin/projects?lang='.$locale)->assertOk();
            $html = $page->getContent();
            foreach ([$unsafe, $invalidSlug] as $project) {
                $row = $this->projectRow($html, $project);
                $this->assertStringContainsString('1 / 1', $row);
                $this->assertStringContainsString(
                    $locale === 'ar' ? 'لا يمكن النشر حتى اعتماد' : 'Publication blocked:',
                    $row
                );
                $this->assertStringNotContainsString('Ready for a separate owner-confirmed', $row);
            }
            $row = $this->projectRow($html, $incomplete);
            $this->assertStringContainsString('1 / 1', $row);
            $this->assertStringContainsString(
                $locale === 'ar' ? 'يلزم إكمال العنوان' : 'Complete title, summary and role',
                $row
            );
            $this->assertStringNotContainsString('Ready for a separate owner-confirmed', $row);
            $futureRow = $this->projectRow($html, $future);
            $this->assertStringContainsString('0 / 1', $futureRow);
            $this->assertStringContainsString(
                $locale === 'ar' ? 'لا يمكن النشر حتى اعتماد' : 'Publication blocked:',
                $futureRow
            );
            // Approved raw URLs are not dumped into the listing. The owner
            // reviews only through dedicated, separately authorized workflows.
            $this->assertStringNotContainsString($unsafeUrl, $html);
        }

        foreach ([$unsafe, $invalidSlug, $incomplete, $future] as $project) {
            $this->post('/admin/projects/'.$project->id.'/publish', [
                'publication_confirmed' => '1',
            ])->assertRedirect()->assertSessionHasErrors();
            $this->assertSame('private', $project->fresh()->visibility);
        }
        Http::assertNothingSent();
    }

    public function test_guest_reader_and_nonowner_admin_cannot_gain_review_privileges_or_private_urls(): void
    {
        $project = $this->project('Restricted private evidence review');
        $privateUrl = 'https://docs.example.org/PRIVATE-CANDIDATE-DO-NOT-SHOW';
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $privateUrl, 'visibility' => 'private',
            'verified_at' => null,
        ]);

        $this->get('/admin/projects')->assertRedirect('/admin/login');
        $this->actingAs($this->user('reader'), 'web')
            ->get('/admin/projects')->assertForbidden();

        $this->actingAs($this->user('admin'), 'web');
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/admin/projects?lang='.$locale)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
            $row = $this->projectRow($page->getContent(), $project);
            $this->assertStringNotContainsString($privateUrl, $row);
            $this->assertStringNotContainsString('Publication blocked:', $row);
            $this->assertStringNotContainsString('Ready for a separate owner-confirmed', $row);
            $this->assertStringNotContainsString('Review pending private evidence', $row);
        }
        $this->post('/admin/projects/'.$project->id.'/publish', [
            'publication_confirmed' => '1',
        ])->assertForbidden();
        $this->get('/admin/projects/'.$project->id.'/evidence')->assertForbidden();
        $this->assertSame('private', $project->fresh()->visibility);
        Http::assertNothingSent();
    }
}

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

final class AdminProjectPublicationWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::fake();
    }

    private function account(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function publishedProject(): Project
    {
        $project = Project::create([
            'slug' => 'withdraw-'.Str::lower(Str::random(12)),
            'title' => 'Withdrawable evidence-backed project',
            'summary_en' => 'Reviewed public engineering portfolio project.',
            'summary_ar' => 'مشروع هندسي عام تمت مراجعة أدلته.',
            'role_en' => 'Engineering',
            'role_ar' => 'هندسة',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subMinute(),
        ]);
        $project->evidence()->create([
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/reviewed-project-proof',
            'visibility' => 'public',
            'verified_at' => now()->subMinute(),
        ]);

        return $project;
    }

    public function test_owner_withdrawal_immediately_hides_public_project_without_deleting_evidence(): void
    {
        $project = $this->publishedProject();
        $owner = $this->account('owner');
        $this->actingAs($owner, 'web');

        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->get('/admin/projects?lang=en')->assertOk()
            ->assertSee('Withdraw published project')
            ->assertSee('name="withdrawal_confirmed"', false)
            ->assertSee(route('admin.operations.projects.withdraw', [
                'project' => $project->id, 'lang' => 'en',
            ]), false)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->post('/admin/projects/'.$project->id.'/withdraw?lang=ar', [
            'withdrawal_confirmed' => '1',
        ])->assertRedirect(route('admin.operations.projects', ['lang' => 'ar']))
            ->assertSessionHas('status', 'تم سحب المشروع من العرض العام. الأدلة المعتمدة محفوظة، وإعادة النشر تتطلب تأكيداً منفصلاً.');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'visibility' => 'private', 'published_at' => null,
        ]);
        $this->assertSame(1, DB::table('project_evidence')
            ->where('project_id', $project->id)
            ->where('visibility', 'public')
            ->whereNotNull('verified_at')->count());
        $this->assertFalse(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->get('/projects/'.$project->slug)->assertNotFound();
        $this->get('/projects')->assertOk()->assertDontSee($project->title);
        $this->get('/admin/projects?lang=ar')->assertOk()
            ->assertDontSee('سحب المشروع من النشر')
            ->assertSee('مراجعة جميع الأدلة الخاصة (صفحات)');
        $this->post('/admin/projects/'.$project->id.'/withdraw', [
            'withdrawal_confirmed' => '1',
        ])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_withdrawal_requires_owner_role_explicit_confirmation_and_exact_form_schema(): void
    {
        $project = $this->publishedProject();
        $path = '/admin/projects/'.$project->id.'/withdraw';

        $this->post($path, ['withdrawal_confirmed' => '1'])
            ->assertRedirect('/admin/login');

        foreach (['reader', 'admin'] as $role) {
            $this->actingAs($this->account($role), 'web')
                ->post($path, ['withdrawal_confirmed' => '1'])->assertForbidden();
            $this->get('/admin/projects')->assertDontSee('Withdraw published project');
        }

        $this->actingAs($this->account('owner'), 'web');
        $this->post($path)->assertRedirect()->assertSessionHasErrors('withdrawal_confirmed');
        $this->post($path, ['withdrawal_confirmed' => '0'])
            ->assertRedirect()->assertSessionHasErrors('withdrawal_confirmed');
        $this->post($path, [
            'withdrawal_confirmed' => '1', 'visibility' => 'private',
        ])->assertRedirect()->assertSessionHasErrors('project');
        $this->post($path.'?lang=fr', ['withdrawal_confirmed' => '1'])
            ->assertRedirect()->assertSessionHasErrors('lang');
        $this->post('/admin/projects/not-a-uuid/withdraw', [
            'withdrawal_confirmed' => '1',
        ])->assertNotFound();

        $this->assertSame('public', $project->fresh()->visibility);
        $this->assertNotNull($project->fresh()->published_at);
        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        Http::assertNothingSent();
    }

    public function test_private_draft_cannot_be_withdrawn_and_republication_still_checks_evidence(): void
    {
        $project = $this->publishedProject();
        $this->actingAs($this->account('owner'), 'web');
        $this->post('/admin/projects/'.$project->id.'/withdraw', [
            'withdrawal_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        // Re-publication uses the existing evidence-gated flow; withdrawal
        // never sets visibility back to public or clears approved evidence.
        $this->post('/admin/projects/'.$project->id.'/publish', [
            'publication_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('public', $project->fresh()->visibility);
        $this->assertTrue(Project::query()->publiclyVerified()->whereKey($project->id)->exists());

        $this->post('/admin/projects/'.$project->id.'/withdraw', [
            'withdrawal_confirmed' => '1',
        ])->assertRedirect();
        $project->evidence()->update([
            'verified_at' => null, 'visibility' => 'private',
        ]);
        $this->post('/admin/projects/'.$project->id.'/publish', [
            'publication_confirmed' => '1',
        ])->assertRedirect()->assertSessionHasErrors('project');
        $this->assertFalse(Project::query()->publiclyVerified()->whereKey($project->id)->exists());
        $this->assertSame('private', $project->fresh()->visibility);
        $this->assertNull($project->fresh()->published_at);
        Http::assertNothingSent();
    }
}

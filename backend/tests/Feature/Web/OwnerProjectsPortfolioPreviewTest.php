<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\OwnerProjectsPortfolioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OwnerProjectsPortfolioPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function owner(): User
    {
        $owner = User::query()->create([
            'email' => Str::random(12).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return $owner;
    }

    public function test_seed_is_idempotent_and_publishes_only_real_public_repo_evidence(): void
    {
        $this->seed(OwnerProjectsPortfolioSeeder::class);
        $this->assertDatabaseCount('projects', 40);
        $this->assertSame(7, Project::query()->where('visibility', 'public')->count());
        $this->assertSame(33, Project::query()->where('visibility', 'private')->count());
        $this->assertDatabaseCount('project_evidence', 7);

        $diwan = Project::query()->where('title', 'DiwanMAM')->firstOrFail();
        $this->assertSame('private', $diwan->visibility);
        $this->assertNull($diwan->published_at);
        $this->get('/projects/cv-diwanmam')->assertNotFound();

        $foodex = Project::query()->where('title', 'FOODEX')->firstOrFail();
        $this->assertSame('public', $foodex->visibility);
        $this->assertSame('enterprise', $foodex->portfolio_category);
        $this->assertSame('https://raw.githubusercontent.com/walidatiyaai2025-gif/FOOD/main/README.md', $foodex->evidence()->firstOrFail()->url);
        $this->get('/projects/github-foodex?lang=en')->assertOk()
            ->assertSee('FOODEX')
            ->assertSee('Published evidence')
            ->assertSee('https://raw.githubusercontent.com/walidatiyaai2025-gif/FOOD/main/README.md');

        $foodex->update(['summary_en' => 'Owner edited factual portfolio description']);
        $this->seed(OwnerProjectsPortfolioSeeder::class);
        $this->assertDatabaseCount('projects', 40);
        $this->assertDatabaseCount('project_evidence', 7);
        $this->assertSame('Owner edited factual portfolio description', $foodex->fresh()->summary_en);
    }

    public function test_primary_projects_route_is_live_six_section_bilingual_catalog_not_a_mockup(): void
    {
        $this->seed(OwnerProjectsPortfolioSeeder::class);
        $page = $this->get('/projects?lang=en')->assertOk()
            ->assertSee('class="portfolio-live"', false)
            ->assertSee('Projects Portfolio')
            ->assertSee('FOODEX')
            ->assertSee('href="'.route('public.project', ['slug'=>'github-foodex','lang'=>'en']).'"', false)
            ->assertDontSee('DiwanMAM');

        foreach (['featured','government','education','workflows','ai','enterprise'] as $category) {
            $this->get('/projects?lang=en&category='.$category)->assertOk()
                ->assertSee('id="portfolio-live-catalog"', false)
                ->assertSee('portfolio-live');
        }
        $this->get('/projects?lang=en&preview=cv')->assertOk()
            ->assertSee('Projects Portfolio')
            ->assertDontSee('const PROJECT_PAGES');
        $this->get('/projects?lang=ar&category=ai')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مدير ووردبريس الذكي')
            ->assertSee('منتجات وذكاء اصطناعي');
        $this->get('/projects?lang=en&category%5B0%5D=SECRET')->assertOk()
            ->assertDontSee('SECRET');
        $this->assertSame(1, substr_count($page->getContent(), '<h1'));
    }

    public function test_owner_can_create_edit_review_publish_and_withdraw_real_project(): void
    {
        $this->actingAs($this->owner(), 'web');
        $this->get('/admin/projects?lang=en')->assertOk()
            ->assertSee('Create a real project in the database');
        $form = [
            'slug'=>'real-admin-work',
            'title'=>'Real Admin Work',
            'title_ar'=>'عمل إداري حقيقي',
            'summary_en'=>'Real project with an evidenced implementation.',
            'summary_ar'=>'مشروع حقيقي يستند إلى تنفيذ ودليل عام.',
            'role_en'=>'Project engineer',
            'role_ar'=>'مهندس المشروع',
            'status'=>'in_progress',
            'portfolio_category'=>'enterprise',
            'portfolio_featured'=>'1',
        ];
        $this->post('/admin/projects?lang=en', $form)->assertRedirect()
            ->assertSessionHas('status');
        $draft=Project::query()->where('slug','real-admin-work')->firstOrFail();
        $this->assertSame('private', $draft->visibility);
        $this->get('/projects/real-admin-work')->assertNotFound();
        $this->patch('/admin/projects/'.$draft->id.'?lang=en', [
            'title'=>'Real Admin Work revised',
            'title_ar'=>'عمل إداري تم تعديله',
            'summary_en'=>'Revised documented implementation.',
            'summary_ar'=>'تنفيذ موثق خضع للتعديل والمراجعة.',
            'role_en'=>'Technical project manager',
            'role_ar'=>'مدير مشروع تقني',
            'portfolio_category'=>'ai',
            'portfolio_featured'=>'0',
            'status'=>'completed',
        ])->assertRedirect();
        $this->assertSame('ai', $draft->fresh()->portfolio_category);
        $this->assertSame('completed', $draft->fresh()->status);
        $this->assertFalse($draft->fresh()->portfolio_featured);
        $this->post('/admin/projects/'.$draft->id.'/publish', ['publication_confirmed'=>'1'])
            ->assertRedirect()->assertSessionHasErrors();
        $this->post('/admin/projects/'.$draft->id.'/evidence', [
            'evidence_type'=>'document',
            'url'=>'https://docs.example.org/verified-public-evidence',
        ])->assertRedirect();
        $proof=$draft->evidence()->firstOrFail();
        $this->post('/admin/projects/'.$draft->id.'/evidence/'.$proof->id.'/approve', [
            'source_reviewed'=>'1',
            'publication_rights_confirmed'=>'1',
        ])->assertRedirect();
        $this->post('/admin/projects/'.$draft->id.'/publish', [
            'publication_confirmed'=>'1',
        ])->assertRedirect()->assertSessionHas('status');
        $this->get('/projects/real-admin-work?lang=ar')->assertOk()
            ->assertSee('عمل إداري تم تعديله')
            ->assertSee('أدلة منشورة')->assertSee('docs.example.org');
        $this->post('/admin/projects/'.$draft->id.'/withdraw', [
            'withdrawal_confirmed'=>'1',
        ])->assertRedirect();
        $this->get('/projects/real-admin-work')->assertNotFound();
        $this->assertDatabaseHas('projects', ['id'=>$draft->id,'visibility'=>'private']);
        $this->assertSame(1, DB::table('project_audit_events')
            ->where('project_id',$draft->id)->where('action','draft_created')->count());
    }

    public function test_non_owner_cannot_create_or_edit_and_rejects_unknown_attributes(): void
    {
        $this->get('/admin/projects')->assertRedirect('/admin/login');
        $reader = User::query()->create([
            'email'=>'reader@example.test','status'=>'active','password_hash'=>null,
        ]);
        $reader->roles()->attach(Role::firstOrCreate(['name'=>'admin'])->id);
        $this->actingAs($reader,'web');
        $this->post('/admin/projects', [
            'slug'=>'forbidden','title'=>'Denied',
            'summary_en'=>'Private data',
        ])->assertForbidden();
        $this->actingAs($this->owner(),'web');
        $this->post('/admin/projects', [
            'slug'=>'invalid-project', 'title'=>'Draft',
            'title_ar'=>'مسودة', 'summary_en'=>'This is a real project.',
            'summary_ar'=>'هذا مشروع حقيقي مثبت.',
            'role_en'=>'Engineer', 'role_ar'=>'مهندس',
            'status'=>'research', 'portfolio_category'=>'ai', 'visibility'=>'public',
        ])->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseMissing('projects',['slug'=>'invalid-project']);
    }
}

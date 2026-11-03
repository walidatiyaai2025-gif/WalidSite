<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAnalyticsPublicDiscoveryParityTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(18)).'@example.test',
            'password_hash' => null,
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function project(
        string $slug,
        string $url = 'https://docs.example.org/proof',
        string $visibility = 'public'
    ): Project {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Verified ' . $slug,
            'summary_en' => 'Public evidence-backed engineering case.',
            'summary_ar' => 'دليل هندسي منشور ومتحقق منه.',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => $url,
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        return $project;
    }

    private function article(string $slug, bool $publish = true): Article
    {
        $article = Article::create([
            'slug' => $slug,
            'title_en' => 'Verified ' . $slug,
            'title_ar' => 'مقال موثق ' . $slug,
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => 'Evidence-backed technical guidance.',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/article-evidence',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
            ],
        ]);
        if ($publish) {
            $article->publishVerified();
        }

        return $article;
    }

    public function test_admin_summary_counts_match_the_actual_public_web_and_api_discovery(): void
    {
        Http::fake();

        $this->project('available-project');
        $this->project('unsafe-proof-project', 'https://localhost/private');
        $this->project('invalid_slug_project');
        $this->project('unpublished-project', 'https://docs.example.org/proof', 'private');

        $this->article('available-guide');
        $revoked = $this->article('revoked-guide');
        $revoked->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);
        $this->article('invalid_slug_guide');
        $this->article('draft-guide', false);

        $this->get('/projects?lang=en')->assertOk()
            ->assertSee('available-project')
            ->assertDontSee('unsafe-proof-project')
            ->assertDontSee('invalid_slug_project')
            ->assertDontSee('unpublished-project');

        $this->get('/blog?lang=en')->assertOk()
            ->assertSee('available-guide')
            ->assertDontSee('revoked-guide')
            ->assertDontSee('invalid_slug_guide')
            ->assertDontSee('draft-guide');

        $this->getJson('/api/v1/public/projects?lang=en')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'available-project');

        $this->actingAs($this->account('admin'), 'web');
        foreach (['en', 'ar'] as $locale) {
            $this->get('/admin/analytics?lang='.$locale)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('<strong>1</strong>', false)
                ->assertSee(
                    $locale === 'ar'
                        ? '<span>المشاريع المعتمدة</span><strong>1</strong>'
                        : '<span>Verified public projects</span><strong>1</strong>',
                    false
                )
                ->assertSee(
                    $locale === 'ar'
                        ? '<span>مقالات مرخصة منشورة</span><strong>1</strong>'
                        : '<span>Published articles (status)</span><strong>1</strong>',
                    false
                )
                ->assertDontSee('unsafe-proof-project')
                ->assertDontSee('revoked-guide');
        }
        Http::assertNothingSent();
    }

    public function test_revocations_immediately_remove_discovery_metrics_without_losing_role_protection(): void
    {
        Http::fake();
        $project = $this->project('later-withdrawn-proof');
        $article = $this->article('later-withdrawn-citations');

        $this->get('/admin/analytics')->assertRedirect('/admin/login');
        $this->actingAs($this->account('reader'), 'web')
            ->get('/admin/analytics')->assertForbidden();
        $this->actingAs($this->account('owner'), 'web')
            ->get('/admin/analytics')->assertOk()
            ->assertSee('<span>Verified public projects</span><strong>1</strong>', false)
            ->assertSee('<span>Published articles (status)</span><strong>1</strong>', false);

        $project->evidence()->firstOrFail()->update(['verified_at' => null]);
        $article->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false,
                'technical_passed' => true,
            ],
        ]);

        $this->get('/admin/analytics?lang=en')->assertOk()
            ->assertSee('<span>Verified public projects</span><strong>0</strong>', false)
            ->assertSee('<span>Published articles (status)</span><strong>0</strong>', false);
        $this->get('/admin/analytics?lang=ar')->assertOk()
            ->assertSee('<span>المشاريع المعتمدة</span><strong>0</strong>', false)
            ->assertSee('<span>مقالات مرخصة منشورة</span><strong>0</strong>', false);
        $this->get('/projects')->assertOk()->assertDontSee('later-withdrawn-proof');
        $this->get('/blog')->assertOk()->assertDontSee('later-withdrawn-citations');
        $this->getJson('/api/v1/public/projects')
            ->assertOk()->assertJsonPath('meta.total', 0);

        Http::assertNothingSent();
    }
}

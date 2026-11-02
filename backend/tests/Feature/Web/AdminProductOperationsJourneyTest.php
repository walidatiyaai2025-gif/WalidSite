<?php

namespace Tests\Feature\Web;

use App\Models\AiProvider;
use App\Models\Project;
use App\Models\ProjectEvidence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminProductOperationsJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function project(string $slug, string $visibility = 'public'): Project
    {
        return Project::create([
            'slug' => $slug,
            'title' => 'Persisted '.$slug,
            'summary_en' => 'Verified public case evidence',
            'summary_ar' => 'أدلة دراسة حالة موثقة',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $visibility,
            'published_at' => now()->subDay(),
        ]);
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Internal provider',
            'provider_type' => 'ollama-compatible',
            'base_url' => 'http://127.0.0.1:11434',
            'secret_ciphertext' => 'NEVER_EXPOSE_PRIVATE_TOKEN',
            'enabled' => true,
            'health_status' => 'unverified',
            'currency' => 'USD',
        ]);
    }

    public function test_four_contract_screens_are_session_authorized_and_linked_from_dashboard(): void
    {
        $routes = [
            '/admin/github' => 'GitHub verification',
            '/admin/projects' => 'Projects & CV evidence',
            '/admin/audience' => 'Audience & notification consent',
            '/admin/analytics' => 'Persisted analytics',
        ];
        Http::fake();
        foreach ($routes as $path => $title) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->post('/admin/github/sync')->assertRedirect('/admin/login');
        $this->get('/admin/analytics?format=csv')->assertRedirect('/admin/login');

        $this->actingAs($this->account('reader'), 'web');
        foreach (array_keys($routes) as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/admin/github/sync')->assertForbidden();
        $this->get('/admin/analytics?format=csv')->assertForbidden();

        $this->actingAs($this->account('owner'), 'web');
        foreach ($routes as $path => $title) {
            $this->get($path)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertSee($title)
                ->assertSee('lang="en"', false)
                ->assertSee('dir="ltr"', false)
                ->assertSee('noindex,nofollow', false)
                ->assertSee('id="content" tabindex="-1"', false)
                ->assertSee('href="#content"', false)
                ->assertSee('fonts.googleapis.com/css2?family=Inter', false);

            $this->get($path.'?lang=ar')->assertOk()
                ->assertSee('dir="rtl"', false)
                ->assertSee('lang="ar"', false)
                ->assertSee('التحليلات')
                ->assertSee('href="'.route('admin.audit.index', ['lang'=>'ar']).'"', false);
        }
        $this->get('/admin')->assertOk()
            ->assertSee('href="'.route('admin.github.index', ['lang'=>'en']).'"', false)
            ->assertSee('href="'.route('admin.projects.index', ['lang'=>'en']).'"', false)
            ->assertSee('href="'.route('admin.audience.index', ['lang'=>'en']).'"', false)
            ->assertSee('href="'.route('admin.analytics.index', ['lang'=>'en']).'"', false);
        Http::assertNothingSent();
    }

    public function test_github_sync_verifies_only_public_allowlisted_repos_and_fails_closed(): void
    {
        config(['portfolio.github_public_repos' => ['example/open-proof', 'example/private-case']]);
        Http::fake([
            'api.github.com/repos/example/open-proof' => Http::response([
                'full_name'=>'example/open-proof',
                'private'=>false,
                'visibility'=>'public',
                'html_url'=>'https://github.com/example/open-proof',
                'language'=>'PHP',
                'description'=>'Independently public metadata',
            ]),
            'api.github.com/repos/example/private-case' => Http::response([
                'full_name'=>'example/private-case',
                'private'=>true,
                'visibility'=>'private',
                'html_url'=>'https://github.com/example/private-case',
            ]),
        ]);
        $this->actingAs($this->account('admin'), 'web');
        $this->get('/admin/github?lang=ar')->assertOk()
            ->assertSee('example/open-proof')
            ->assertSee('Independently public metadata')
            ->assertSee('>1</strong>', false)
            ->assertDontSee('example/private-case')
            ->assertSee('name="_token"', false)
            ->assertSee('method="post"', false);
        $this->post('/admin/github/sync?lang=ar')->assertRedirect('/admin/github?lang=ar')
            ->assertSessionHas('status');
        Http::assertSentCount(4);

        Http::fake(['api.github.com/*'=>Http::response([],503)]);
        $this->post('/admin/github/sync?lang=en')->assertRedirect('/admin/github?lang=en')
            ->assertSessionHas('error');
        $this->get('/admin/github')->assertOk()
            ->assertDontSee('example/private-case')
            ->assertDontSee('example/open-proof');
    }

    public function test_projects_filter_and_visibility_never_publish_private_or_unverified_proofs(): void
    {
        $public = $this->project('public-real-proof');
        ProjectEvidence::create([
            'project_id'=>$public->id,
            'evidence_type'=>'document',
            'url'=>'https://docs.example.org/public-reference',
            'visibility'=>'public',
            'verified_at'=>now()->subDay(),
        ]);
        $private = $this->project('internal-secret-project', 'private');
        $unverified = $this->project('missing-evidence');
        $this->actingAs($this->account('admin'), 'web');

        $this->get('/admin/projects?lang=ar&visibility=public')->assertOk()
            ->assertSee('public-real-proof')
            ->assertSee('missing-evidence')
            ->assertDontSee('internal-secret-project')
            ->assertSee('href="'.route('public.project', ['slug'=>$public->slug,'lang'=>'ar']).'"', false)
            ->assertSee('غير جاهز للنشر');
        $this->get('/admin/projects?visibility=private')->assertOk()
            ->assertSee('internal-secret-project')
            ->assertDontSee('public-real-proof');
        $this->get('/admin/projects?status=research')->assertOk()
            ->assertSee('No matching projects.');
        $this->get('/projects')->assertOk()
            ->assertDontSee('internal-secret-project')
            ->assertDontSee('missing-evidence');
        foreach (['status=unapproved','visibility[]=private','page=0','lang=wrong'] as $query) {
            $this->get('/admin/projects?'.$query)->assertRedirect()->assertSessionHasErrors();
        }
        $this->get('/admin/projects?status=completed&lang=ar&x=unsafe-marker')->assertOk()
            ->assertDontSee('unsafe-marker');
    }

    public function test_audience_counts_only_unrevoked_opted_in_active_users_and_never_sends(): void
    {
        $admin = $this->account('admin');
        $active = $this->account('reader');
        $revoked = $this->account('reader');
        $revoked->update(['status'=>'suspended']);
        foreach ([$active, $revoked] as $user) {
            DB::table('notification_preferences')->insert([
                'id'=>(string) Str::uuid(),
                'user_id'=>$user->id,
                'purpose'=>'product_updates',
                'channel'=>'push',
                'enabled'=>true,
                'consented_at'=>now()->subDay(),
                'revoked_at'=>$user->id===$revoked->id ? now() : null,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        }
        Http::fake();
        $this->actingAs($admin, 'web');
        $this->get('/admin/audience')->assertOk()
            ->assertSee('Currently consented recipients')
            ->assertSee('>1</strong>', false)
            ->assertSee('Delivery disabled')
            ->assertDontSee($active->email)
            ->assertDontSee($revoked->email)
            ->assertDontSee($active->id)
            ->assertDontSee('Send test push');
        $this->get('/admin/audience?lang=ar')->assertOk()
            ->assertSee('حالة الإشعارات');
        Http::assertNothingSent();
    }

    public function test_analytics_uses_stored_utc_period_and_safe_csv_without_cross_currency_totals(): void
    {
        $provider = $this->provider();
        $jobId = (string) Str::uuid();
        DB::table('ai_jobs')->insert([
            'id'=>$jobId,
            'provider_id'=>$provider->id,
            'job_type'=>'draft',
            'idempotency_key'=>'admin-product-analytics-fixture',
            'payload_hash'=>str_repeat('a',64),
            'status'=>'completed',
            'created_at'=>now('UTC'),
            'updated_at'=>now('UTC'),
        ]);
        foreach ([
            ['USD', 250, now('UTC'), 'usage-usd'],
            ['KWD', 19, now('UTC'), 'usage-kwd'],
            ['USD', 1000, now('UTC')->subDays(40), 'usage-old'],
        ] as [$currency,$minor,$created,$key]) {
            DB::table('ai_usage')->insert([
                'id'=>(string) Str::uuid(),
                'usage_key'=>$key,
                'job_id'=>$jobId,
                'provider_id'=>$provider->id,
                'cost_minor'=>$minor,
                'cost_currency'=>$currency,
                'created_at'=>$created,
            ]);
        }
        $this->actingAs($this->account('owner'), 'web');
        $this->get('/admin/analytics?period=7d')->assertOk()
            ->assertSee('USD')->assertSee('KWD')
            ->assertSee('>250</td>', false)->assertSee('>19</td>', false)
            ->assertDontSee('>1250</td>', false)
            ->assertDontSee('>1269</td>', false)
            ->assertDontSee('NEVER_EXPOSE_PRIVATE_TOKEN')
            ->assertDontSee('127.0.0.1');
        $this->get('/admin/analytics?period=all')->assertOk()
            ->assertSee('>1250</td>', false);
        $csv = $this->get('/admin/analytics?period=7d&format=csv')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $export = $csv->streamedContent();
        $this->assertStringContainsString('ai_usage,7d,USD,250,minor_currency_units', $export);
        $this->assertStringContainsString('ai_usage,7d,KWD,19,minor_currency_units', $export);
        $this->assertStringNotContainsString('NEVER_EXPOSE_PRIVATE_TOKEN', $export);
        $this->assertStringNotContainsString('1000,minor_currency_units', $export);
        foreach (['period=week','period[]=7d','format=pdf','lang=xx'] as $query) {
            $this->get('/admin/analytics?'.$query)->assertRedirect()->assertSessionHasErrors();
        }
    }
}

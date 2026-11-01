<?php

namespace Tests\Feature\AI;

use App\AI\EditorialScheduleRunner;
use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EditorialScheduleRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    private function actor(string $role = 'admin'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'status' => 'active', 'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function article(string $risk = 'low', bool $approved = true): Article
    {
        $article = Article::create([
            'slug' => 'due-'.Str::lower(Str::random(12)),
            'title_en' => 'Reviewed guide',
            'title_ar' => 'دليل مُراجع',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1, 'body_md' => 'Verified technical steps',
            'citations_json' => [[
                'source_url' => 'https://example.org/source', 'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true, 'technical_passed' => true,
                'risk_level' => $risk, 'auto_publish_approved' => $approved,
                'contains_professional_claims' => false,
            ],
            'status' => 'validated',
        ]);
        return $article;
    }

    private function enable(User $actor, int $refreshDays = 30): void
    {
        $this->actingAs($actor, 'web')->post('/admin/editorial/automation/policy', [
            'enabled' => '1', 'max_per_tick' => '10', 'refresh_days' => $refreshDays,
        ])->assertRedirect();
    }

    private function schedule(User $actor, Article $article): void
    {
        $this->actingAs($actor, 'web')->post('/admin/editorial/automation/schedule', [
            'article_id' => $article->id,
            'due_at' => now()->addMinute()->format('Y-m-d H:i:s'),
            'confirmed' => '1',
        ])->assertRedirect();
    }

    public function test_publication_is_disabled_by_default_with_zero_side_effects(): void
    {
        $actor = $this->actor();
        $article = $this->article();
        $this->schedule($actor, $article);
        $this->travel(2)->minutes();

        $this->assertSame('disabled', app(EditorialScheduleRunner::class)->run()['state']);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseCount('editorial_events', 1);
    }

    public function test_admin_policy_and_schedule_are_persisted_and_publish_exactly_once(): void
    {
        $actor = $this->actor();
        $article = $this->article();

        $this->enable($actor, 2);
        $this->schedule($actor, $article);
        $this->assertDatabaseHas('editorial_policies', [
            'id' => 1, 'enabled' => 1, 'max_per_tick' => 10, 'refresh_days' => 2,
        ]);
        $this->assertDatabaseHas('editorial_schedules', [
            'article_id' => $article->id, 'revision_no' => 1,
            'approved_by' => $actor->id, 'status' => 'pending',
        ]);
        $this->get('/admin/editorial/automation?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('دليل مُراجع');

        $this->travel(2)->minutes();
        $runner = app(EditorialScheduleRunner::class);
        $first = $runner->run();
        $this->assertSame(1, $first['checked']);
        $this->assertSame(1, $first['published']);
        $this->assertSame(0, $first['review']);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);

        $second = $runner->run();
        $this->assertSame(0, $second['published']);
        $this->assertDatabaseCount('editorial_events', 3); // policy, schedule, publish
        $this->assertDatabaseHas('editorial_schedules', [
            'article_id' => $article->id, 'status' => 'published',
        ]);
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertOk();
    }

    public function test_risky_and_unapproved_articles_never_enter_auto_publish_queue(): void
    {
        $actor = $this->actor();
        $this->enable($actor);
        foreach ([
            $this->article('high'),
            $this->article('unknown'),
            $this->article('low', false),
        ] as $article) {
            $this->actingAs($actor, 'web')->post('/admin/editorial/automation/schedule', [
                'article_id' => $article->id,
                'due_at' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
                'confirmed' => '1',
            ])->assertSessionHasErrors('article_id');
        }
        $this->assertDatabaseCount('editorial_schedules', 0);
        $this->assertSame(0, app(EditorialScheduleRunner::class)->run()['published']);
    }

    public function test_last_minute_evidence_revocation_moves_queue_to_manual_review(): void
    {
        $actor = $this->actor();
        $article = $this->article();
        $this->enable($actor);
        $this->schedule($actor, $article);

        $article->revisions()->firstOrFail()->update([
            'validation_json' => [
                'citations_passed' => false, 'technical_passed' => true,
                'risk_level' => 'low', 'auto_publish_approved' => true,
                'contains_professional_claims' => false,
            ],
        ]);
        $this->travel(2)->minutes();
        $result = app(EditorialScheduleRunner::class)->run();
        $this->assertSame(0, $result['published']);
        $this->assertSame(1, $result['review']);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseHas('editorial_schedules', [
            'article_id' => $article->id, 'status' => 'requires_review',
        ]);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id, 'action' => 'requires_review',
            'reason' => 'evidence_or_risk_gate',
        ]);
    }

    public function test_refresh_deadline_unpublishes_and_invalidates_old_approval(): void
    {
        $actor = $this->actor();
        $article = $this->article();
        $this->enable($actor, 1);
        $this->schedule($actor, $article);
        $this->travel(2)->minutes();
        $runner = app(EditorialScheduleRunner::class);
        $runner->run();
        $this->assertSame('published', $article->fresh()->status);

        // Switching off NEW publication must not leave old public content
        // beyond its enforced refresh deadline.
        DB::table('editorial_policies')->where('id', 1)->update(['enabled' => false]);
        $this->travel(2)->days();
        $result = $runner->run();
        $this->assertSame('disabled', $result['state']);
        $this->assertSame(1, $result['refreshed']);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_revision_no);
        $this->assertFalse($article->revisions()->firstOrFail()->hasVerifiedEvidence());
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();

        $this->assertSame(0, $runner->run()['refreshed']);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id, 'action' => 'refresh_required',
        ]);
    }

    public function test_only_admin_roles_can_change_policy_or_schedule_and_events_are_redacted(): void
    {
        $article = $this->article();
        $payload = ['enabled' => '1', 'max_per_tick' => 5, 'refresh_days' => 1];

        $this->post('/admin/editorial/automation/policy', $payload)
            ->assertRedirect('/admin/login');
        $this->get('/admin/editorial/automation')->assertRedirect('/admin/login');
        $this->actingAs($this->actor('reader'), 'web');
        $this->post('/admin/editorial/automation/policy', $payload)->assertForbidden();
        $this->get('/admin/editorial/automation')->assertForbidden();
        $this->post('/admin/editorial/automation/schedule', [
            'article_id' => $article->id, 'due_at' => now()->addDay(),
            'confirmed' => 1,
        ])->assertForbidden();
        $this->assertDatabaseCount('editorial_policies', 0);

        $this->actingAs($this->actor('owner'), 'web');
        $this->post('/admin/editorial/automation/policy', [
            'enabled' => 1, 'max_per_tick' => 100, 'refresh_days' => 0,
        ])->assertSessionHasErrors(['max_per_tick', 'refresh_days']);
        $page = $this->get('/admin/editorial/automation')->assertOk();
        $page->assertDontSee('https://example.org/source');
        $page->assertDontSee('Verified technical steps');
    }

    public function test_high_risk_is_owner_manual_only_with_reasoned_event(): void
    {
        $article = $this->article('high');
        $admin = $this->actor('admin');
        $this->actingAs($admin, 'web')->post(
            '/admin/editorial/automation/'.$article->id.'/manual-review',
            ['reason' => 'source_reviewed', 'confirmed' => 1]
        )->assertForbidden();
        $this->assertSame('draft', $article->fresh()->status);

        $owner = $this->actor('owner');
        $this->actingAs($owner, 'web')->post(
            '/admin/editorial/automation/'.$article->id.'/manual-review',
            ['reason' => 'technical_reviewed', 'confirmed' => 1]
        )->assertRedirect();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id, 'actor_id' => $owner->id,
            'action' => 'manual_published', 'reason' => 'technical_reviewed',
        ]);
        $this->assertDatabaseCount('editorial_schedules', 0);
    }

    public function test_limit_and_lock_cannot_start_unbounded_parallel_runner(): void
    {
        $this->enable($this->actor());
        foreach ([0, -1, 21] as $limit) {
            try {
                app(EditorialScheduleRunner::class)->run($limit);
                $this->fail('Invalid limit was accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('between 1 and 20', $e->getMessage());
            }
        }
        $lock = Cache::lock('editorial:due-runner:v1', 120);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame('locked', app(EditorialScheduleRunner::class)->run()['state']);
        } finally {
            $lock->release();
        }
    }
}

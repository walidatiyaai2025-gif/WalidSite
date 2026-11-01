<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileSavedResourcesTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $status = 'active'): User
    {
        return User::create([
            'email' => $name.'@example.test',
            'status' => $status,
        ]);
    }

    private function token(User $user, array $abilities = ['mobile']): string
    {
        return $user->createToken('unit-phone', $abilities, now()->addHour())->plainTextToken;
    }

    private function article(string $slug): Article
    {
        $article = Article::create([
            'slug' => $slug, 'title_en' => 'Verified '.$slug,
            'title_ar' => 'موثق '.$slug, 'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1, 'body_md' => '# Cited technical guide',
            'status' => 'validated',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/guide',
                'verified' => true,
            ]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();

        return $article;
    }

    private function runbook(string $slug): string
    {
        $id = (string) Str::uuid();
        $title = 'Reviewed '.$slug;
        $body = '# Reviewed procedure '.$slug;
        DB::table('runbooks')->insert([
            'id' => $id, 'slug' => $slug, 'title' => $title,
            'body_md' => $body, 'version' => 1,
            'offline_ok' => true, 'validated_at' => now()->subMinutes(5),
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(), 'runbook_id' => $id,
            'version' => 1, 'body_md' => $body,
            'reviewed_title' => $title, 'checksum' => hash('sha256', $body),
            'created_at' => now()->subMinutes(6),
        ]);

        return $id;
    }

    public function test_guest_requires_token_and_admin_only_or_inactive_tokens_are_denied(): void
    {
        $this->getJson('/api/v1/mobile/saved')->assertUnauthorized();
        $this->postJson('/api/v1/mobile/saved', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/mobile/saved/article/'.Str::uuid())->assertUnauthorized();

        $owner = $this->user('admin-only');
        $this->withToken($this->token($owner, ['admin']))
            ->getJson('/api/v1/mobile/saved')->assertForbidden();
        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article', 'resource_id' => (string) Str::uuid(),
        ])->assertForbidden();
        Auth::forgetGuards();

        $inactive = $this->user('disabled', 'inactive');
        $this->withToken($this->token($inactive))
            ->getJson('/api/v1/mobile/saved')->assertForbidden();
        $this->assertDatabaseCount('saved_resources', 0);
    }

    public function test_user_can_save_list_and_remove_both_verified_resource_types_idempotently(): void
    {
        $user = $this->user('reader');
        $article = $this->article('published-guide');
        $runbook = $this->runbook('offline-procedure');
        Http::fake();
        $this->withToken($this->token($user));

        $this->getJson('/api/v1/mobile/saved')->assertOk()->assertJsonCount(0, 'data');
        foreach ([
            ['article', $article->id],
            ['runbook', $runbook],
        ] as [$type, $id]) {
            $this->postJson('/api/v1/mobile/saved', [
                'resource_type' => $type, 'resource_id' => $id,
            ])->assertOk()->assertJsonPath('data.saved', true)
                ->assertJsonPath('data.resource_id', $id);
            $this->postJson('/api/v1/mobile/saved', [
                'resource_type' => $type, 'resource_id' => $id,
            ])->assertOk()->assertJsonPath('data.saved', true);
        }
        $this->assertDatabaseCount('saved_resources', 2);

        $en = $this->getJson('/api/v1/mobile/saved?lang=en')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $ar = $this->getJson('/api/v1/mobile/saved?lang=ar')->assertOk()
            ->assertJsonCount(2, 'data');
        $entries = collect($en->json('data'))->keyBy('resource_type');
        $this->assertSame('Verified published-guide', $entries['article']['title']);
        $this->assertSame(
            route('api.public.article', ['slug' => $article->slug], false),
            $entries['article']['detail_url']
        );
        $this->assertSame('Reviewed offline-procedure', $entries['runbook']['title']);
        $this->assertSame(
            route('api.mobile.solution', ['id' => $runbook], false),
            $entries['runbook']['detail_url']
        );
        $this->assertSame('موثق published-guide',
            collect($ar->json('data'))->keyBy('resource_type')['article']['title']);
        $this->assertStringContainsString('no-store', (string) $en->headers->get('Cache-Control'));

        $this->deleteJson('/api/v1/mobile/saved/article/'.$article->id)->assertOk()
            ->assertJsonPath('data.removed', true);
        $this->deleteJson('/api/v1/mobile/saved/article/'.$article->id)->assertOk()
            ->assertJsonPath('data.removed', false);
        $this->getJson('/api/v1/mobile/saved')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.resource_id', $runbook);
        Http::assertNothingSent();
    }

    public function test_saved_records_are_strictly_partitioned_by_user_and_never_leak_credentials(): void
    {
        $a = $this->user('alice');
        $b = $this->user('bob');
        $article = $this->article('private-bookmark');
        $this->withToken($this->token($a))->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article', 'resource_id' => $article->id,
        ])->assertOk();

        Auth::forgetGuards();
        $this->withToken($this->token($b))->getJson('/api/v1/mobile/saved')
            ->assertOk()->assertJsonCount(0, 'data')->assertDontSee($article->id);
        $this->deleteJson('/api/v1/mobile/saved/article/'.$article->id)->assertOk()
            ->assertJsonPath('data.removed', false);
        $this->assertDatabaseCount('saved_resources', 1);

        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article', 'resource_id' => $article->id,
        ])->assertOk();
        $this->assertDatabaseCount('saved_resources', 2);
        DB::table('users')->where('id', $a->id)->delete();
        $this->assertDatabaseCount('saved_resources', 1);
        $this->getJson('/api/v1/mobile/saved')->assertOk()
            ->assertJsonCount(1, 'data')->assertDontSee('alice@example.test');
    }

    public function test_article_and_runbook_revocation_hide_existing_bookmarks_immediately(): void
    {
        $user = $this->user('revoke-reader');
        $article = $this->article('revoke-article');
        $runbook = $this->runbook('revoke-runbook');
        $this->withToken($this->token($user));
        foreach ([['article', $article->id], ['runbook', $runbook]] as [$type, $id]) {
            $this->postJson('/api/v1/mobile/saved', [
                'resource_type' => $type, 'resource_id' => $id,
            ])->assertOk();
        }
        $this->getJson('/api/v1/mobile/saved')->assertOk()->assertJsonCount(2, 'data');

        $article->revisions()->firstOrFail()->update([
            'validation_json' => ['citations_passed' => false, 'technical_passed' => true],
        ]);
        DB::table('runbooks')->where('id', $runbook)
            ->update(['title' => 'Unreviewed private replacement']);
        Http::fake();

        $this->getJson('/api/v1/mobile/saved')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false)
            ->assertDontSee('Unreviewed private replacement');
        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article', 'resource_id' => $article->id,
        ])->assertNotFound();
        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'runbook', 'resource_id' => $runbook,
        ])->assertNotFound();
        $this->assertDatabaseCount('saved_resources', 2);
        Http::assertNothingSent();
    }

    public function test_validation_unknown_resources_and_out_of_scope_inputs_fail_closed(): void
    {
        $user = $this->user('validator');
        $this->withToken($this->token($user));
        foreach ([
            ['resource_type' => 'draft', 'resource_id' => (string) Str::uuid()],
            ['resource_type' => 'article', 'resource_id' => 'not-uuid'],
            ['resource_type' => ['article'], 'resource_id' => (string) Str::uuid()],
            ['resource_type' => 'runbook', 'resource_id' => 'invalid'],
        ] as $input) {
            $this->postJson('/api/v1/mobile/saved', $input)->assertUnprocessable();
        }
        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article', 'resource_id' => (string) Str::uuid(),
        ])->assertNotFound();
        foreach (['?lang=fr', '?lang[]=ar', '?page=0', '?page[]=1'] as $query) {
            $this->getJson('/api/v1/mobile/saved'.$query)->assertUnprocessable();
        }
        $this->deleteJson('/api/v1/mobile/saved/invalid/'.Str::uuid())->assertNotFound();
        $this->deleteJson('/api/v1/mobile/saved/article/not-uuid')->assertNotFound();
        $this->assertDatabaseCount('saved_resources', 0);
    }

    public function test_pagination_discards_hidden_bookmarks_before_assigning_visible_slots(): void
    {
        $user = $this->user('pagination');
        $token = $this->token($user);
        for ($i = 1; $i <= 13; $i++) {
            DB::table('saved_resources')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $user->id,
                'resource_type' => 'article', 'resource_id' => (string) Str::uuid(),
                'saved_at' => now()->subMinutes($i),
            ]);
        }
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $article = $this->article(sprintf('public-%02d', $i));
            $ids[] = $article->id;
            DB::table('saved_resources')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $user->id,
                'resource_type' => 'article', 'resource_id' => $article->id,
                'saved_at' => now()->subHours(2)->subMinutes($i),
            ]);
        }
        $this->withToken($token);
        $first = $this->getJson('/api/v1/mobile/saved?page=1')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.has_more_pages', true);
        $second = $this->getJson('/api/v1/mobile/saved?page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $this->getJson('/api/v1/mobile/saved?page=3')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more_pages', false);
        $got = array_merge(
            array_column($first->json('data'), 'resource_id'),
            array_column($second->json('data'), 'resource_id')
        );
        $this->assertSame($ids, $got);
    }

    public function test_production_plaintext_denied_before_processing_bearer_credentials(): void
    {
        $user = $this->user('secure-reader');
        $token = $this->token($user);
        $previous = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->withToken($token)
                ->getJson('http://localhost/api/v1/mobile/saved')->assertForbidden();
            $this->postJson('http://localhost/api/v1/mobile/saved', [
                'resource_type' => 'article', 'resource_id' => (string) Str::uuid(),
            ])->assertForbidden();
            $this->getJson('https://localhost/api/v1/mobile/saved')
                ->assertOk()->assertJsonCount(0, 'data');
        } finally {
            $this->app->instance('env', $previous);
        }
    }
}

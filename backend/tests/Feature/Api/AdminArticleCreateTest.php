<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W1 / #619: the one missing canonical OpenAPI article creation operation.
 * A research request is NOT verified editorial content or a publish approval.
 */
final class AdminArticleCreateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', string $status = 'active'): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => $status,
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function token(string $role = 'owner', string $ability = 'admin',
        string $status = 'active'): string
    {
        return $this->user($role, $status)
            ->createToken('editorial-draft-test', [$ability], now()->addHour())
            ->plainTextToken;
    }

    private function input(string $language = 'en', string $mode = 'draft'): array
    {
        return [
            'title' => $language === 'ar' ? 'مراجعة النسخ الاحتياطي' : 'Restore review',
            'topic' => 'Research safe backup recovery and source citations.',
            'language' => $language,
            'publication_mode' => $mode,
        ];
    }

    public function test_owner_can_create_private_english_draft_without_publication_or_provider_calls(): void
    {
        Http::fake();
        $this->withToken($this->token());
        $response = $this->postJson('/api/v1/admin/articles', $this->input())
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.canonical_lang', 'en')
            ->assertJsonPath('data.title', 'Restore review')
            ->assertJsonPath('data.revision_no', 1)
            ->assertJsonPath('data.requires_editorial_review', true)
            ->assertJsonPath('data.auto_publication_started', false);
        $this->assertStringContainsString(
            'no-store', (string) $response->headers->get('Cache-Control')
        );

        $article = Article::query()->findOrFail($response->json('data.id'));
        $this->assertSame($response->json('data.slug'), $article->slug);
        $this->assertSame('Restore review', $article->title_en);
        $this->assertSame('', $article->title_ar);
        $this->assertSame('draft', $article->status);
        $this->assertNull($article->published_at);
        $this->assertNull($article->published_revision_no);
        $revision = $article->revisions()->sole();
        $this->assertSame(1, $revision->revision_no);
        $this->assertSame('', $revision->body_md);
        $this->assertSame([], $revision->citations_json);
        $this->assertSame('draft', $revision->status);
        $this->assertSame([
            'research_topic' => 'Research safe backup recovery and source citations.',
            'requested_publication_mode' => 'draft',
            'citations_passed' => false,
            'technical_passed' => false,
            'auto_publish_approved' => false,
        ], $revision->validation_json);
        $this->assertFalse($article->isReadyToPublish());

        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        $this->getJson('/api/v1/public/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/admin/articles/'.$article->id.'/publish', [])
            ->assertUnprocessable();
        $this->assertSame('draft', $article->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_arabic_auto_eligible_is_an_unpublished_request_not_approval(): void
    {
        Http::fake();
        $this->withToken($this->token('admin'));
        $response = $this->postJson('/api/v1/admin/articles', $this->input('ar', 'auto_eligible'))
            ->assertOk()
            ->assertJsonPath('data.canonical_lang', 'ar')
            ->assertJsonPath('data.title', 'مراجعة النسخ الاحتياطي')
            ->assertJsonPath('data.publication_mode_requested', 'auto_eligible')
            ->assertJsonPath('data.auto_publication_started', false);
        $article = Article::findOrFail($response->json('data.id'));
        $this->assertSame('', $article->title_en);
        $this->assertSame('مراجعة النسخ الاحتياطي', $article->title_ar);
        $this->assertSame('draft', $article->status);
        $this->assertSame('auto_eligible', $article->revisions()->sole()
            ->validation_json['requested_publication_mode']);
        $this->assertFalse($article->revisions()->sole()
            ->validation_json['auto_publish_approved']);
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('article_revisions', 1);
        Http::assertNothingSent();
    }

    public function test_guest_mobile_reader_inactive_and_web_sessions_cannot_create_drafts(): void
    {
        $url = '/api/v1/admin/articles';
        $this->postJson($url, $this->input())->assertUnauthorized();

        foreach ([
            ['owner', 'mobile', 'active'],
            ['reader', 'admin', 'active'],
            ['admin', 'admin', 'inactive'],
        ] as [$role, $ability, $status]) {
            Auth::forgetGuards();
            $this->withToken($this->token($role, $ability, $status))
                ->postJson($url, $this->input())->assertForbidden();
        }

        Auth::forgetGuards();
        $this->actingAs($this->user('owner'), 'web')
            ->postJson($url, $this->input())->assertForbidden();
        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('article_revisions', 0);
    }

    public function test_dto_rejects_missing_bad_types_unknown_and_unsafe_publication_fields(): void
    {
        // Exercise every malformed DTO independently of the separate live
        // throttle gate (10/min). Keep Sanctum and HTTPS middleware enabled.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->withToken($this->token());
        $valid = $this->input();
        $invalid = [
            [], ['title' => ''],
            ['title' => str_repeat('X', 251)],
            ['topic' => str_repeat('X', 2001)],
            ['topic' => []], ['language' => 'fr'],
            ['language' => ['en']], ['publication_mode' => 'published'],
            ['publication_mode' => true],
            ['status' => 'published'],
            ['published_at' => now()->toISOString()],
            ['citations_json' => [['verified' => true]]],
            ['title_ar' => 'Fake translation'],
            ['validation_json' => ['technical_passed' => true]],
            ['body_md' => 'Unreviewed body'],
            ['secret_ciphertext' => 'PRIVATE'],
        ];

        foreach ($invalid as $delta) {
            $input = array_replace($valid, $delta);
            if ($delta === []) {
                $input = [];
            }
            $this->postJson('/api/v1/admin/articles', $input)
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('articles', 0);
        $this->assertDatabaseCount('article_revisions', 0);
    }

    public function test_production_http_refuses_creation_before_payload_validation(): void
    {
        $original = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->withToken($this->token());
            $this->postJson('http://localhost/api/v1/admin/articles', [])
                ->assertForbidden();
            $this->postJson('https://localhost/api/v1/admin/articles', $this->input())
                ->assertOk()->assertJsonPath('data.status', 'draft');
            $this->assertDatabaseCount('articles', 1);
        } finally {
            $this->app->instance('env', $original);
        }
    }
}

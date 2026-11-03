<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminMakerCheckerSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function token(User $user): string
    {
        return $user->createToken('maker-checker', ['admin'], now()->addHour())
            ->plainTextToken;
    }

    private function draft(?User $author): Article
    {
        Auth::forgetGuards();
        if ($author !== null) {
            $this->actingAs($author, 'web');
        }
        $article = Article::create([
            'slug' => 'maker-checker-'.Str::lower(Str::random(12)),
            'title_en' => 'Reviewed database guide',
            'title_ar' => 'دليل قاعدة بيانات موثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Verified database recovery',
            'citations_json' => [[
                'source_url' => 'https://docs.example.org/recovery',
                'verified' => true,
            ]],
            'validation_json' => [
                'citations_passed' => true,
                'technical_passed' => true,
                'risk_level' => 'low',
                'contains_professional_claims' => false,
            ],
            'status' => 'validated',
        ]);
        Auth::forgetGuards();

        return $article;
    }

    private function approve(string $token, Article $article)
    {
        Auth::forgetGuards();
        return $this->withToken($token)
            ->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
                'decision' => 'approve',
                'reason' => 'source_reviewed',
            ]);
    }

    public function test_admin_and_owner_cannot_approve_their_own_authored_revision(): void
    {
        foreach (['admin', 'owner'] as $role) {
            $author = $this->actor($role);
            $article = $this->draft($author);
            $revision = $article->revisions()->sole();

            $this->assertSame($author->id, $revision->author_user_id);
            $this->assertSame($author->id, $revision->last_editor_user_id);
            $this->approve($this->token($author), $article)->assertForbidden();
            $this->assertSame('draft', $article->fresh()->status);
            $this->assertNull($revision->fresh()->reviewed_by_user_id);
            $this->assertDatabaseMissing('editorial_events', [
                'article_id' => $article->id,
                'action' => 'manual_published',
            ]);
        }
    }

    public function test_second_authorized_reviewer_records_identity_and_publishes(): void
    {
        $author = $this->actor();
        $reviewer = $this->actor();
        $article = $this->draft($author);

        $response = $this->approve($this->token($reviewer), $article)
            ->assertOk()->assertJsonPath('data.state', 'published')
            ->assertDontSee($author->email)->assertDontSee($reviewer->email);

        $revision = $article->revisions()->sole();
        $this->assertSame($author->id, $revision->fresh()->author_user_id);
        $this->assertSame($reviewer->id, $revision->fresh()->reviewed_by_user_id);
        $this->assertNotNull($revision->fresh()->reviewed_at);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'actor_id' => $reviewer->id,
            'action' => 'manual_published',
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_legacy_unknown_authorship_fails_closed_in_approval_and_generic_publish(): void
    {
        $legacy = $this->draft(null);
        $reviewer = $this->actor('owner');
        $token = $this->token($reviewer);
        $this->assertNull($legacy->revisions()->sole()->author_user_id);
        $this->approve($token, $legacy)->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->postJson(
            '/api/v1/admin/articles/'.$legacy->id.'/publish', []
        )->assertForbidden();

        $this->assertSame('draft', $legacy->fresh()->status);
        $this->assertDatabaseMissing('editorial_events', [
            'article_id' => $legacy->id, 'action' => 'manual_published',
        ]);
    }

    public function test_last_human_editor_cannot_reapprove_even_if_not_original_author(): void
    {
        $author = $this->actor();
        $editor = $this->actor();
        $reviewer = $this->actor();
        $article = $this->draft($author);
        Auth::forgetGuards();
        $this->actingAs($editor, 'web');

        $revision = $article->revisions()->sole();
        $revision->update([
            'validation_json' => array_merge($revision->validation_json, [
                'technical_passed' => true,
            ]),
        ]);
        $this->assertSame($editor->id, $revision->fresh()->last_editor_user_id);
        Auth::forgetGuards();

        $this->approve($this->token($editor), $article)->assertForbidden();
        $this->approve($this->token($reviewer), $article)->assertOk();
        $this->assertSame($reviewer->id,
            $revision->fresh()->reviewed_by_user_id);
    }

    public function test_revoked_reviewer_role_or_bearer_cannot_approve(): void
    {
        $article = $this->draft($this->actor());
        $reviewer = $this->actor();
        $bearer = $reviewer->createToken('revoked-checker', ['admin'], now()->addHour());
        $reviewer->roles()->detach();
        $this->approve($bearer->plainTextToken, $article)->assertForbidden();

        $reviewer->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);
        $bearer->accessToken->delete();
        $this->approve($bearer->plainTextToken, $article)->assertUnauthorized();
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->revisions()->sole()->reviewed_by_user_id);
    }

    public function test_api_article_creation_sets_durable_author_without_spoofable_fields(): void
    {
        $author = $this->actor();
        Auth::forgetGuards();
        $this->withToken($this->token($author));
        $id = $this->postJson('/api/v1/admin/articles', [
            'title' => 'Private draft',
            'topic' => 'Evidence-backed problem',
            'language' => 'en',
            'publication_mode' => 'draft',
        ])->assertOk()->json('data.id');

        $revision = Article::findOrFail($id)->revisions()->sole();
        $this->assertSame($author->id, $revision->author_user_id);
        $this->assertSame($author->id, $revision->last_editor_user_id);
        $this->assertNull($revision->reviewed_by_user_id);
    }
}

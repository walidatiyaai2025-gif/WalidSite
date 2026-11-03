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

final class AdminApprovalRejectionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $actor = User::create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $actor->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $actor;
    }

    private function bearer(User $user): string
    {
        return $user->createToken('approval-boundary', ['admin'], now()->addHour())
            ->plainTextToken;
    }

    private function article(): Article
    {
        $article = Article::create([
            'slug' => 'rejected-review-'.Str::lower(Str::random(10)),
            'title_en' => 'Evidence backed draft',
            'title_ar' => 'مسودة موثقة',
            'canonical_lang' => 'en',
        ]);
        $this->revision($article, 1);

        return $article;
    }

    private function revision(Article $article, int $number): void
    {
        $article->revisions()->create([
            'revision_no' => $number,
            'body_md' => '# Verified recovery guide',
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
    }

    public function test_api_rejection_blocks_reapproval_and_generic_publish_of_same_revision(): void
    {
        $reviewer = $this->actor();
        $article = $this->article();
        $this->withToken($this->bearer($reviewer));
        $this->assertTrue($article->isReadyToPublish());

        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'reject',
            'reason' => 'technical_reviewed',
        ])->assertOk()->assertJsonPath('data.state', 'rejected');

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertFalse($article->fresh()->isReadyToPublish());
        $this->assertFalse($article->revisions()->sole()->hasVerifiedEvidence());
        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'approve',
            'reason' => 'technical_reviewed',
        ])->assertUnprocessable()->assertJsonValidationErrors('approval');
        $this->postJson('/api/v1/admin/articles/'.$article->id.'/publish', [])
            ->assertUnprocessable()->assertJsonValidationErrors('article');

        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'revision_no' => 1,
            'actor_id' => $reviewer->id,
            'action' => 'manual_rejected',
            'reason' => 'technical_reviewed',
        ]);
        $this->assertSame(0, DB::table('editorial_events')
            ->where('article_id', $article->id)
            ->where('action', 'manual_published')->count());
        $this->assertNull($article->fresh()->published_revision_no);
        $this->getJson('/api/v1/public/articles/'.$article->slug)->assertNotFound();
    }

    public function test_rejected_revision_cannot_be_revalidated_in_place_but_fresh_revision_can_publish(): void
    {
        $reviewer = $this->actor();
        $article = $this->article();
        $this->withToken($this->bearer($reviewer));
        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'reject',
            'reason' => 'source_reviewed',
        ])->assertOk();

        // Even a direct retry of the former validation flags is not a new
        // independently reviewed revision.
        $old = $article->revisions()->sole();
        $old->update([
            'status' => 'validated',
            'validation_json' => $old->validation_json,
        ]);
        $this->assertFalse($old->fresh()->hasVerifiedEvidence());

        $this->revision($article, 2);
        $this->assertTrue($article->fresh()->isReadyToPublish());
        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'approve',
            'reason' => 'source_reviewed',
        ])->assertOk()->assertJsonPath('data.revision_no', 2)
            ->assertJsonPath('data.state', 'published');

        $this->assertSame(2, $article->fresh()->published_revision_no);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'revision_no' => 1,
            'action' => 'manual_rejected',
        ]);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'revision_no' => 2,
            'action' => 'manual_published',
        ]);
    }

    public function test_web_approval_after_api_rejection_and_guest_or_reader_bypass_are_denied(): void
    {
        $article = $this->article();
        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'reject', 'reason' => 'source_reviewed',
        ])->assertUnauthorized();

        $reader = $this->actor('reader');
        $this->withToken($this->bearer($reader));
        $this->postJson('/api/v1/admin/approvals/'.$article->id.'/decision', [
            'decision' => 'reject', 'reason' => 'source_reviewed',
        ])->assertForbidden();

        Auth::forgetGuards();
        $reviewer = $this->actor();
        $this->actingAs($reviewer, 'web');
        $this->post('/admin/approvals/'.$article->id.'/decision?lang=ar', [
            'decision' => 'reject',
            'reason' => 'technical_reviewed',
        ])->assertRedirect();

        $this->post('/admin/approvals/'.$article->id.'/decision?lang=en', [
            'decision' => 'approve',
            'reason' => 'technical_reviewed',
        ])->assertRedirect()->assertSessionHasErrors('approval');
        $this->post('/admin/content/'.$article->id.'/publish')
            ->assertRedirect()->assertSessionHasErrors('publish');
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(1, DB::table('editorial_events')
            ->where('article_id', $article->id)
            ->where('action', 'manual_rejected')->count());
    }
}

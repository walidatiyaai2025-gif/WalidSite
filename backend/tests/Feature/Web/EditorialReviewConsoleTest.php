<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EditorialReviewConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    private function article(bool $verified = true): Article
    {
        $article = Article::create([
            'slug' => 'article-'.Str::lower(Str::random(10)),
            'title_en' => 'Technical review candidate',
            'title_ar' => 'مقال تقني للمراجعة',
            'canonical_lang' => 'ar',
        ]);
        $revision = $article->revisions()->create([
            'revision_no' => 1,
            'status' => $verified ? 'validated' : 'draft',
            'body_md' => '# Reviewed material',
            'citations_json' => [[
                'source_url' => 'https://example.org/review',
                'verified' => $verified,
            ]],
            'validation_json' => [
                'citations_passed' => $verified,
                'technical_passed' => $verified,
                'risk_level' => 'low',
                'contains_professional_claims' => false,
            ],
        ]);
        // The original creator must be someone other than the reviewer.
        $author = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        DB::table('article_revisions')->where('id', $revision->id)->update([
            'author_user_id' => $author->id,
            'last_editor_user_id' => $author->id,
        ]);

        return $article;
    }

    public function test_guest_and_reader_cannot_inspect_or_change_editorial_content(): void
    {
        $article = $this->article();
        $index = '/admin/content?article='.$article->id;
        $this->get($index)->assertRedirect('/admin/login');
        $this->post('/admin/content/'.$article->id.'/publish')
            ->assertRedirect('/admin/login');
        $this->post('/admin/content/'.$article->id.'/unpublish')
            ->assertRedirect('/admin/login');

        $this->actingAs($this->userWithRole('reader'), 'web');
        $this->get($index)->assertForbidden();
        $this->post('/admin/content/'.$article->id.'/publish')->assertForbidden();
        $this->post('/admin/content/'.$article->id.'/unpublish')->assertForbidden();
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_admin_can_review_bilingual_safe_gate_summary_without_leaking_body(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');
        $article = $this->article(false);
        $article->revisions()->firstOrFail()->update([
            'body_md' => 'SECRET-DRAFT-BODY',
            'citations_json' => [[
                'source_url' => 'https://private.example.org/internal?access_token=secret',
                'verified' => false,
            ]],
        ]);
        $this->get('/admin/content?lang=ar&article='.$article->id)->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('استوديو مراجعة المحتوى')
            ->assertSee('مقال تقني للمراجعة')
            ->assertSee('النشر محجوب')
            ->assertSee('سجل الإصدارات')
            ->assertDontSee('SECRET-DRAFT-BODY')
            ->assertDontSee('access_token=secret');
        $this->get('/admin/content?article='.$article->id)->assertOk()
            ->assertSee('Content review studio')
            ->assertSee('Revision history')
            ->assertSee('Publishing blocked')
            ->assertDontSee('Approve and publish verified revision');
        $this->get('/admin/content?article=not-a-uuid')
            ->assertRedirect()->assertSessionHasErrors('article');
    }

    public function test_only_a_validated_revision_can_publish_and_unpublish_revokes_public_access(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $article = $this->article();

        $this->get('/admin')->assertOk()->assertSee('Editorial review');
        $this->get('/admin/content?article='.$article->id)->assertOk()
            ->assertSee('Approve and publish verified revision')
            ->assertSee('name="_token"', false);
        $this->post('/admin/content/'.$article->id.'/publish')
            ->assertRedirect();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->get('/blog/'.$article->slug)->assertOk();

        $this->post('/admin/content/'.$article->id.'/unpublish')
            ->assertRedirect();
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_revision_no);
        $this->get('/blog/'.$article->slug)->assertNotFound();
    }

    public function test_unpublish_requires_real_published_transition_and_never_fabricates_a_second_audit_event(): void
    {
        $owner = $this->userWithRole('owner');
        $this->actingAs($owner, 'web');
        $article = $this->article();
        $url = '/admin/content/'.$article->id.'/unpublish';
        $unpublishedEvents = static fn () => DB::table('editorial_events')
            ->where('article_id', $article->id)
            ->where('action', 'manual_unpublished')->count();

        // A draft never carried a published revision to revoke.
        $this->post($url)->assertStatus(409);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(0, $unpublishedEvents());
        $this->post($url, ['force' => 'true'])
            ->assertRedirect()->assertSessionHasErrors('unpublish');
        $this->assertSame(0, $unpublishedEvents());

        // The independent reviewer really publishes this reviewed revision.
        $this->post('/admin/content/'.$article->id.'/publish')->assertRedirect();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $article->fresh()->published_revision_no);
        $this->get('/blog/'.$article->slug)->assertOk();

        // A reader cannot revoke an already published public article.
        $this->actingAs($this->userWithRole('reader'), 'web');
        $this->post($url)->assertForbidden();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(0, $unpublishedEvents());

        $this->actingAs($owner, 'web');
        $this->post($url.'?lang=ar')->assertRedirect()
            ->assertSessionHas('status', 'تم إلغاء نشر المقال.');
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertNull($article->fresh()->published_revision_no);
        $this->assertNull($article->fresh()->published_at);
        $this->get('/blog/'.$article->slug)->assertNotFound();
        $this->assertSame(1, $unpublishedEvents());

        // The second submit must be a conflict, not a success/audit fiction.
        $this->post($url.'?lang=en')->assertStatus(409);
        $this->assertSame(1, $unpublishedEvents());
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseHas('editorial_events', [
            'article_id' => $article->id,
            'revision_no' => 1,
            'action' => 'manual_unpublished',
            'reason' => 'owner_or_admin_revoked',
        ]);
    }

    public function test_unvalidated_draft_cannot_publish_even_when_posted_directly(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');
        $article = $this->article(false);
        $this->from('/admin/content?article='.$article->id)
            ->post('/admin/content/'.$article->id.'/publish')
            ->assertRedirect('/admin/content?article='.$article->id)
            ->assertSessionHasErrors('publish');
        $this->assertSame('draft', $article->fresh()->status);
        $this->get('/blog/'.$article->slug)->assertNotFound();
    }

    public function test_uncleared_image_rights_block_publish(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $article = $this->article();
        $asset = Asset::create([
            'asset_key' => 'restricted-image', 'mime_type' => 'image/png',
            'storage_key' => 'private/do-not-expose.png',
            'sha256' => str_repeat('a', 64), 'origin' => 'owner-supplied',
            'rights_status' => 'pending', 'publishable' => false,
        ]);
        $article->update(['hero_asset_id' => $asset->id]);
        $this->get('/admin/content?article='.$article->id)->assertOk()
            ->assertSee('Not cleared')
            ->assertDontSee('private/do-not-expose.png');
        $this->post('/admin/content/'.$article->id.'/publish')
            ->assertSessionHasErrors('publish');
        $this->get('/blog/'.$article->slug)->assertNotFound();

        $asset->update(['rights_status' => 'cleared', 'publishable' => true]);
        $this->post('/admin/content/'.$article->id.'/publish')->assertRedirect();
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_direct_web_publish_refuses_self_editor_and_unknown_author_but_records_independent_checker(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin, 'web');

        $self = $this->article();
        DB::table('article_revisions')->where('article_id', $self->id)->update([
            'author_user_id' => $admin->id,
            'last_editor_user_id' => $admin->id,
        ]);
        $this->get('/admin/content?article='.$self->id.'&lang=en')
            ->assertOk()
            ->assertSee('Publishing blocked: an independent checker')
            ->assertDontSee('Approve and publish verified revision');
        $this->post('/admin/content/'.$self->id.'/publish')->assertForbidden();
        $this->assertSame('draft', $self->fresh()->status);

        $edited = $this->article();
        DB::table('article_revisions')->where('article_id', $edited->id)->update([
            'last_editor_user_id' => $admin->id,
        ]);
        $this->get('/admin/content?article='.$edited->id.'&lang=ar')
            ->assertOk()->assertSee('النشر محجوب: يتطلب الإصدار مراجعة مستقلة');
        $this->post('/admin/content/'.$edited->id.'/publish')->assertForbidden();

        $legacy = $this->article();
        DB::table('article_revisions')->where('article_id', $legacy->id)->update([
            'author_user_id' => null,
            'last_editor_user_id' => null,
        ]);
        $this->post('/admin/content/'.$legacy->id.'/publish')->assertForbidden();
        $this->assertDatabaseMissing('editorial_events', [
            'article_id' => $legacy->id, 'action' => 'manual_published',
        ]);

        $reviewable = $this->article();
        $revision = $reviewable->revisions()->firstOrFail();
        $this->get('/admin/content?article='.$reviewable->id.'&lang=en')
            ->assertOk()->assertSee('Approve and publish verified revision');
        $this->post('/admin/content/'.$reviewable->id.'/publish')
            ->assertRedirect();
        $this->assertSame('published', $reviewable->fresh()->status);
        $this->assertDatabaseHas('article_revisions', [
            'id' => $revision->id,
            'reviewed_by_user_id' => $admin->id,
        ]);
        $this->assertNotNull($revision->fresh()->reviewed_at);
    }

}

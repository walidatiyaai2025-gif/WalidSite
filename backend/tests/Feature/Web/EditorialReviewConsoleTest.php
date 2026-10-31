<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $article->revisions()->create([
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
            ],
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
            ->assertSessionHasNoErrors()->assertStatus(302);
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
}

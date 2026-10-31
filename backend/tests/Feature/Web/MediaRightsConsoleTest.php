<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaRightsConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'email' => Str::random(16).'@example.test',
            'password_hash' => null,
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function media(bool $cleared = true): Asset
    {
        return Asset::create([
            'asset_key' => 'picture-'.Str::random(9),
            'mime_type' => 'image/png',
            'storage_key' => 'private/SECRET-IMAGE-SOURCE.png',
            'sha256' => str_repeat('a', 64),
            'origin' => 'owner-supplied',
            'rights_status' => $cleared ? 'cleared' : 'pending',
            'publishable' => $cleared,
        ]);
    }

    public function test_guest_and_reader_cannot_view_media_or_revoke_it(): void
    {
        $asset = $this->media();
        $this->get('/admin/ai/images')->assertRedirect('/admin/login');
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')
            ->assertRedirect('/admin/login');

        $this->actingAs($this->userWithRole('reader'), 'web');
        $this->get('/admin/ai/images')->assertForbidden();
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')->assertForbidden();
        $this->assertSame('cleared', $asset->fresh()->rights_status);
        $this->assertTrue($asset->fresh()->publishable);
    }

    public function test_bilingual_review_exposes_only_safe_media_metadata(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');
        $asset = $this->media();

        $this->get('/admin')->assertOk()->assertSee('Media rights');
        $this->get('/admin/ai/images')->assertOk()
            ->assertSee('Media rights review')
            ->assertSee($asset->asset_key)
            ->assertSee('Publicly usable')
            ->assertSee('Revoke public media rights')
            ->assertSee('name="_token"', false)
            ->assertDontSee('SECRET-IMAGE-SOURCE')
            ->assertDontSee('private/');
        $this->get('/admin/ai/images?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مراجعة حقوق الصور');
    }

    public function test_revoking_a_hero_asset_hides_public_article_immediately(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $asset = $this->media();
        $article = Article::create([
            'slug' => 'media-rights-test',
            'title_en' => 'Source-backed guide',
            'title_ar' => 'دليل موثق',
            'canonical_lang' => 'en',
            'hero_asset_id' => $asset->id,
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'status' => 'validated',
            'body_md' => 'Verified guide',
            'citations_json' => [['source_url' => 'https://example.org/source', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();
        $this->get('/blog/'.$article->slug)->assertOk();

        $this->post('/admin/ai/images/'.$asset->id.'/revoke')->assertRedirect();
        $this->assertSame('revoked', $asset->fresh()->rights_status);
        $this->assertFalse($asset->fresh()->publishable);
        $this->get('/blog/'.$article->slug)->assertNotFound();
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')->assertRedirect();
        $this->assertSame('revoked', $asset->fresh()->rights_status);
        $this->get('/admin/ai/images')->assertOk()->assertSee('Public use revoked');
    }

    public function test_unknown_media_id_is_not_found_and_invalid_lang_is_rejected(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $this->post('/admin/ai/images/'.Str::uuid().'/revoke')->assertNotFound();
        $this->get('/admin/ai/images?lang=invalid')
            ->assertRedirect()->assertSessionHasErrors('lang');
    }

    public function test_pending_image_stays_unpublishable_until_a_separate_rights_approval(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $asset = $this->media(false);
        $this->get('/admin/ai/images')->assertOk()
            ->assertSee($asset->asset_key)
            ->assertSee('pending')
            ->assertSee('No');
        $this->assertFalse($asset->fresh()->isPubliclyUsable());
    }
}

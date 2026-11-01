<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
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
    private function generatedArtwork(bool $validEvidence = true): Asset
    {
        Storage::fake('local');
        $key = 'ai-artwork/'.Str::uuid().'.png';
        $bytes = 'test-raster-bytes';
        $asset = Asset::create([
            'asset_key' => 'generated-'.Str::random(8),
            'mime_type' => 'image/png',
            'storage_key' => $key,
            'sha256' => hash('sha256', $bytes),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);
        Storage::disk('local')->put($key, $bytes);
        if ($validEvidence) {
            Storage::disk('local')->put($key.'.provenance.json', json_encode([
                'asset_id' => $asset->id,
                'sha256' => $asset->sha256,
                'provider_id' => 'internal-test',
                'model' => 'test',
                'prompt_sha256' => str_repeat('b', 64),
            ]));
        }
        return $asset;
    }

    public function test_guest_reader_and_invalid_confirmation_cannot_approve_artwork(): void
    {
        $asset = $this->generatedArtwork();
        $url = '/admin/ai/images/'.$asset->id.'/approve';
        $payload = [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ];
        $this->post($url, $payload)->assertRedirect('/admin/login');
        $this->actingAs($this->userWithRole('reader'), 'web');
        $this->post($url, $payload)->assertForbidden();
        $this->actingAs($this->userWithRole('admin'), 'web');
        $this->post($url, array_merge($payload, ['confirm_asset_key' => 'wrong']))
            ->assertSessionHasErrors('confirm_asset_key');
        $this->post($url, array_merge($payload, ['no_owner_impersonation' => '0']))
            ->assertSessionHasErrors('no_owner_impersonation');
        $this->assertFalse($asset->fresh()->publishable);
        $this->assertFalse(Storage::disk('local')->exists($asset->storage_key.'.rights.json'));
    }

    public function test_approval_requires_private_provenance_and_persists_rights_attestation(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $asset = $this->generatedArtwork();
        $payload = [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ];
        $this->get('/admin/ai/images?lang=ar')->assertOk()
            ->assertSee('التحقق واعتماد الحقوق')
            ->assertDontSee($asset->storage_key);
        $this->post('/admin/ai/images/'.$asset->id.'/approve', $payload)
            ->assertRedirect();
        $this->assertSame('cleared', $asset->fresh()->rights_status);
        $this->assertTrue($asset->fresh()->publishable);
        $this->assertTrue(Storage::disk('local')->exists($asset->storage_key.'.rights.json'));
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')->assertRedirect();
        $this->assertFalse($asset->fresh()->isPubliclyUsable());
    }

    public function test_missing_provenance_fails_closed_without_publication(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');
        $asset = $this->generatedArtwork(false);
        $this->post('/admin/ai/images/'.$asset->id.'/approve', [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertSessionHasErrors('approval');
        $this->assertFalse($asset->fresh()->publishable);
        $this->assertFalse(Storage::disk('local')->exists($asset->storage_key.'.rights.json'));
    }

}

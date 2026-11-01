<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    private function generatedMedia(bool $withEvidence = true): Asset
    {
        Storage::fake('local');
        $bytes = 'generated-private-raster-bytes';
        $asset = Asset::create([
            'asset_key' => 'generated-'.Str::random(9),
            'mime_type' => 'image/png',
            'storage_key' => 'placeholder.png',
            'sha256' => hash('sha256', $bytes),
            'origin' => 'ai-generated',
            'rights_status' => 'unverified',
            'publishable' => false,
        ]);
        $path = 'ai-artwork/'.$asset->id.'.png';
        $asset->update(['storage_key' => $path]);
        Storage::disk('local')->put($path, $bytes);
        if ($withEvidence) {
            Storage::disk('local')->put($path.'.provenance.json', json_encode([
                'asset_id' => $asset->id,
                'sha256' => $asset->sha256,
                'provider_id' => 'private-provider-id',
                'model' => 'private-model-name',
                'prompt_sha256' => hash('sha256', 'private prompt'),
            ], JSON_THROW_ON_ERROR));
        }

        return $asset->fresh();
    }

    public function test_guest_and_reader_cannot_view_media_or_revoke_it(): void
    {
        $asset = $this->media();
        $this->get('/admin/ai/images')->assertRedirect('/admin/login');
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')
            ->assertRedirect('/admin/login');
        $this->post('/admin/ai/images/'.$asset->id.'/approve')
            ->assertRedirect('/admin/login');

        $this->actingAs($this->userWithRole('reader'), 'web');
        $this->get('/admin/ai/images')->assertForbidden();
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')->assertForbidden();
        $this->post('/admin/ai/images/'.$asset->id.'/approve', [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertForbidden();
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

    public function test_unverified_generated_artwork_exposes_safe_bilingual_approval_form_only(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'web');
        $asset = $this->generatedMedia();

        $this->get('/admin/ai/images?lang=en')->assertOk()
            ->assertSee('Verify provenance and approve rights')
            ->assertSee('name="confirm_asset_key"', false)
            ->assertSee('name="licensed_for_publication"', false)
            ->assertSee('name="no_owner_impersonation"', false)
            ->assertDontSee('private-provider-id')
            ->assertDontSee('private-model-name')
            ->assertDontSee('private prompt')
            ->assertDontSee($asset->storage_key);
        $this->get('/admin/ai/images?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تحقق واعتمد الحقوق');

        $ownerSupplied = $this->media(false);
        $this->get('/admin/ai/images')->assertOk()
            ->assertSee($ownerSupplied->asset_key)
            ->assertSeeInOrder([$asset->asset_key, 'Verify provenance and approve rights', $ownerSupplied->asset_key]);
        $this->assertFalse($ownerSupplied->fresh()->isPubliclyUsable());
    }

    public function test_owner_can_approve_verified_generated_artwork_and_persist_private_attestation(): void
    {
        $reviewer = $this->userWithRole('owner');
        $this->actingAs($reviewer, 'web');
        $asset = $this->generatedMedia();

        $this->post('/admin/ai/images/'.$asset->id.'/approve?lang=en', [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertRedirect(route('admin.media.index', ['lang' => 'en']))
            ->assertSessionHas('status', 'Artwork provenance verified and publication rights approved.');

        $asset = $asset->fresh();
        $this->assertSame('cleared', $asset->rights_status);
        $this->assertTrue($asset->publishable);
        $this->assertTrue($asset->isPubliclyUsable());

        $rightsPath = $asset->storage_key.'.rights.json';
        Storage::disk('local')->assertExists($rightsPath);
        $attestation = json_decode(Storage::disk('local')->get($rightsPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($asset->id, $attestation['asset_id']);
        $this->assertSame($asset->sha256, $attestation['sha256']);
        $this->assertSame($reviewer->id, $attestation['reviewer_id']);
        $this->assertSame('cleared', $attestation['rights_status']);
        $this->assertTrue($attestation['no_owner_impersonation']);
        $this->assertArrayNotHasKey('provider_id', $attestation);
        $this->assertArrayNotHasKey('model', $attestation);
        $this->assertArrayNotHasKey('prompt', $attestation);
    }

    public function test_rights_approval_fails_closed_without_evidence_or_attestations(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $asset = $this->generatedMedia(false);

        $this->post('/admin/ai/images/'.$asset->id.'/approve', [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertRedirect()->assertSessionHasErrors('approval');
        $this->assertSame('unverified', $asset->fresh()->rights_status);
        $this->assertFalse($asset->fresh()->publishable);
        Storage::disk('local')->assertMissing($asset->storage_key.'.rights.json');

        $asset = $this->generatedMedia();
        $this->post('/admin/ai/images/'.$asset->id.'/approve', [
            'confirm_asset_key' => 'wrong-key',
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertSessionHasErrors('confirm_asset_key');
        $this->post('/admin/ai/images/'.$asset->id.'/approve', [
            'confirm_asset_key' => $asset->asset_key,
        ])->assertSessionHasErrors(['licensed_for_publication', 'no_owner_impersonation']);
        $this->assertSame('unverified', $asset->fresh()->rights_status);
        $this->assertFalse($asset->fresh()->publishable);
    }

    public function test_approved_generated_hero_can_pass_article_image_rights_gate_then_revocation_hides_it(): void
    {
        $this->actingAs($this->userWithRole('owner'), 'web');
        $asset = $this->generatedMedia();
        $article = Article::create([
            'slug' => 'generated-rights-gate',
            'title_en' => 'Generated artwork guide',
            'title_ar' => 'دليل صورة مولدة',
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
        $this->assertFalse($article->fresh()->isReadyToPublish());

        $this->post('/admin/ai/images/'.$asset->id.'/approve', [
            'confirm_asset_key' => $asset->asset_key,
            'licensed_for_publication' => '1',
            'no_owner_impersonation' => '1',
        ])->assertRedirect();
        $this->assertTrue($article->fresh()->isReadyToPublish());

        $article->fresh()->publishVerified();
        $this->get('/blog/'.$article->slug)->assertOk();
        $this->post('/admin/ai/images/'.$asset->id.'/revoke')->assertRedirect();
        $this->get('/blog/'.$article->slug)->assertNotFound();
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

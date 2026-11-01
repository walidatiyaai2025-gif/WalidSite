<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use App\Models\Certification;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CertificationEvidenceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function ownerProfile(): Profile
    {
        $owner = User::create([
            'email' => 'owner-evidence@example.test',
            'status' => 'active',
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return Profile::create([
            'user_id' => $owner->id,
            'public_name' => 'Public Owner',
            'publish_status' => 'published',
        ]);
    }

    private function evidence(array $changes = []): Asset
    {
        return Asset::create(array_merge([
            'asset_key' => 'certificate-'.Str::uuid(),
            'mime_type' => 'image/jpeg',
            'storage_key' => 'certificates/approved.jpg',
            'sha256' => str_repeat('a', 64),
            'origin' => 'owner-supplied',
            'rights_status' => 'cleared',
            'publishable' => true,
        ], $changes));
    }

    private function certification(Profile $profile, string $title, ?string $evidenceId, bool $verified = true): Certification
    {
        return Certification::create([
            'profile_id' => $profile->id,
            'title' => $title,
            'issuer' => 'Public evidence test issuer',
            'verified' => $verified,
            'evidence_asset_id' => $evidenceId,
        ]);
    }

    public function test_both_public_cv_pages_hide_credentials_without_current_usable_evidence(): void
    {
        $profile = $this->ownerProfile();
        $valid = $this->evidence();
        $this->certification($profile, 'Approved Raster Certificate', $valid->id);

        $unsafe = [
            'Revoked Credential' => ['rights_status' => 'revoked'],
            'Pending Rights Credential' => ['rights_status' => 'unverified'],
            'Unpublished Evidence Credential' => ['publishable' => false],
            'Unsupported Origin Credential' => ['origin' => 'untrusted'],
            'Active Content Credential' => ['mime_type' => 'image/svg+xml'],
            'Blank Storage Credential' => ['storage_key' => '  '],
            'Malformed Hash Credential' => ['sha256' => str_repeat('z', 64)],
        ];

        foreach ($unsafe as $title => $invalidAsset) {
            $this->certification($profile, $title, $this->evidence($invalidAsset)->id);
        }
        $this->certification($profile, 'Missing Evidence Credential', (string) Str::uuid());
        $this->certification($profile, 'No Evidence Credential', null);
        $this->certification($profile, 'Unchecked Credential', $valid->id, false);

        $this->assertSame(
            ['Approved Raster Certificate'],
            Certification::query()->publiclyVerified()->pluck('title')->all()
        );
        foreach (['/about', '/resume', '/about?lang=ar', '/resume?lang=ar'] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('Approved Raster Certificate');
            foreach (array_keys($unsafe) as $title) {
                $response->assertDontSee($title);
            }
            foreach (['Missing Evidence Credential', 'No Evidence Credential', 'Unchecked Credential'] as $title) {
                $response->assertDontSee($title);
            }
        }
    }

    public function test_revoking_evidence_immediately_removes_previously_visible_certificate(): void
    {
        $profile = $this->ownerProfile();
        $asset = $this->evidence();
        $this->certification($profile, 'Certificate Revoked After Approval', $asset->id);

        $this->get('/about')->assertOk()->assertSee('Certificate Revoked After Approval');
        $this->get('/resume')->assertOk()->assertSee('Certificate Revoked After Approval');

        $asset->update(['rights_status' => 'revoked', 'publishable' => false]);
        $this->assertSame(0, Certification::query()->publiclyVerified()->count());
        $this->get('/about')->assertOk()->assertDontSee('Certificate Revoked After Approval');
        $this->get('/resume?lang=ar')->assertOk()->assertDontSee('Certificate Revoked After Approval');

        $asset->update(['rights_status' => 'cleared', 'publishable' => true]);
        $this->assertSame(1, Certification::query()->publiclyVerified()->count());
    }
}

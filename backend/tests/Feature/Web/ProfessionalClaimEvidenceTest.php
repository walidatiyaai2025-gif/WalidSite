<?php

namespace Tests\Feature\Web;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfessionalClaimEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_shows_only_published_owner_approved_claims_with_safe_proof_links_in_both_languages(): void
    {
        $user = User::create(['email' => 'proof-owner@example.test', 'status' => 'active']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Verified Owner',
            'publish_status' => 'published',
        ]);

        foreach ([
            ['English approved statement', 'عبارة معتمدة', 'https://evidence.example.org/public-proof', 'approved', true],
            ['Private unpublished claim', 'معلومة خاصة', 'https://evidence.example.org/private', 'pending', true],
            ['Unsafe proof claim', 'رابط غير آمن', 'https://127.0.0.1/internal', 'approved', true],
            ['Unlisted approved claim', 'غير منشور', 'https://evidence.example.org/hidden', 'approved', false],
        ] as [$en, $ar, $url, $status, $public]) {
            ProfessionalClaim::create([
                'profile_id' => $profile->id,
                'claim_en' => $en,
                'claim_ar' => $ar,
                'evidence_url' => $url,
                'status' => $status,
                'is_public' => $public,
            ]);
        }

        $this->get('/about')->assertOk()
            ->assertSee('Evidence-backed professional statements')
            ->assertSee('English approved statement')
            ->assertSee('href="https://evidence.example.org/public-proof"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSee('Private unpublished claim')
            ->assertDontSee('Unsafe proof claim')
            ->assertDontSee('Unlisted approved claim')
            ->assertDontSee('https://127.0.0.1/internal');

        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('عبارة معتمدة')
            ->assertSee('عرض الدليل')
            ->assertDontSee('معلومة خاصة')
            ->assertDontSee('رابط غير آمن');

        $user->update(['status' => 'pending_activation']);
        $this->get('/about')->assertOk()->assertDontSee('English approved statement');
        $user->update(['status' => 'active']);
        $profile->update(['publish_status' => 'review']);
        $this->get('/about')->assertOk()->assertDontSee('English approved statement');
    }

    public function test_non_owner_claims_cannot_appear_on_the_public_about_page(): void
    {
        $user = User::create(['email' => 'non-owner-proof@example.test', 'status' => 'active']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Unrelated Account',
            'publish_status' => 'published',
        ]);
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => 'Unrelated sensitive statement',
            'claim_ar' => 'بيان خاص',
            'evidence_url' => 'https://evidence.example.org/other',
            'status' => 'approved',
            'is_public' => true,
        ]);

        $this->get('/about')->assertOk()
            ->assertDontSee('Unrelated sensitive statement')
            ->assertDontSee('https://evidence.example.org/other');
    }
}

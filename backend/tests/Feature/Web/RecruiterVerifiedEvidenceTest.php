<?php

namespace Tests\Feature\Web;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecruiterVerifiedEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfile(string $email, bool $owner, string $status = 'published'): Profile
    {
        $user = User::create(['email' => $email, 'status' => 'active']);
        if ($owner) {
            $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        }
        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Public profile',
            'publish_status' => $status,
        ]);
    }

    private function claim(Profile $profile, string $en, string $ar, string $url, string $status = 'approved', bool $public = true): void
    {
        ProfessionalClaim::create([
            'profile_id' => $profile->id,
            'claim_en' => $en,
            'claim_ar' => $ar,
            'evidence_url' => $url,
            'status' => $status,
            'is_public' => $public,
        ]);
    }

    public function test_recruiter_displays_only_active_owner_public_approved_evidence_in_both_locales(): void
    {
        $profile = $this->makeProfile('verified-owner@example.test', true);
        $proof = 'https://docs.example.org/approved-evidence';
        $this->claim($profile, 'Reviewed engineering statement', 'عبارة هندسية موثقة', $proof);
        $this->claim($profile, 'Private publisher note', 'ملاحظة خاصة', 'https://docs.example.org/private', 'approved', false);
        $this->claim($profile, 'Unapproved record', 'سجل غير معتمد', 'https://docs.example.org/unapproved', 'pending');
        $this->claim($profile, 'Unsafe claim text', 'دليل غير آمن', 'https://docs.example.org/nope?secret=value');
        $other = $this->makeProfile('other-reader@example.test', false);
        $this->claim($other, 'Non-owner claim', 'ادعاء شخص آخر', 'https://docs.example.org/other');

        foreach (['en' => 'Reviewed engineering statement', 'ar' => 'عبارة هندسية موثقة'] as $locale => $expected) {
            foreach (['database', 'delivery'] as $track) {
                $this->get(route('public.hire', ['lang' => $locale, 'track' => $track]))
                    ->assertOk()
                    ->assertSee($expected)
                    ->assertSee('href="'.$proof.'"', false)
                    ->assertSee('rel="noopener noreferrer"', false)
                    ->assertSee($locale === 'ar' ? 'أدلة مهنية عامة معتمدة للنشر' : 'Published supporting evidence')
                    ->assertSee($locale === 'ar' ? 'ولا تعني اعتمادًا خاصًا بالمجال المحدد' : 'not endorsements of the selected career track')
                    ->assertDontSee('Private publisher note')
                    ->assertDontSee('Unapproved record')
                    ->assertDontSee('Unsafe claim text')
                    ->assertDontSee('Non-owner claim')
                    ->assertDontSee('secret=value')
                    ->assertDontSee('docs.example.org/private');
            }
            $this->get(route('public.about', ['lang' => $locale]))
                ->assertOk()->assertSee($expected);
        }
    }

    public function test_unpublished_or_inactive_owner_claims_are_never_recruiter_evidence(): void
    {
        $profile = $this->makeProfile('draft-owner@example.test', true, 'review');
        $this->claim($profile, 'Unpublished employer claim', 'خبرة غير منشورة', 'https://docs.example.org/claim');
        $this->get('/hire?lang=en')->assertOk()
            ->assertDontSee('Unpublished employer claim')
            ->assertDontSee('Published supporting evidence');

        $profile->update(['publish_status' => 'published']);
        $profile->user->update(['status' => 'pending_activation']);
        $this->get('/hire?lang=ar')->assertOk()
            ->assertDontSee('خبرة غير منشورة')
            ->assertDontSee('أدلة مهنية عامة معتمدة للنشر');
    }

    public function test_recruiter_claim_text_is_escaped_and_not_assigned_to_unverified_track(): void
    {
        $profile = $this->makeProfile('escaped-owner@example.test', true);
        $this->claim($profile, '<script>alert("unsafe")</script>', 'نص تجريبي آمن', 'https://docs.example.org/escaped');
        $page = $this->get('/hire?lang=en&track[]=not-a-track')->assertOk()
            ->assertSee('&lt;script&gt;alert', false)
            ->assertDontSee('<script>alert("unsafe")</script>', false)
            ->assertSee('not endorsements of the selected career track');
        $this->assertStringNotContainsString('track%5B', $page->getContent());
    }
}

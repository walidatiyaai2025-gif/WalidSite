<?php

namespace Tests\Feature\Api;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PublicAiExpertiseTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $email, string $role = 'owner', string $status = 'active', string $publication = 'published'): Profile
    {
        $user = User::create(['email' => $email, 'status' => $status]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Fictional test-only owner',
            'bio_en' => 'PRIVATE_UNREVIEWED_AI_BIO',
            'publish_status' => $publication,
        ]);
    }

    private function skill(Profile $profile, string $slug, string $category = 'AI Engineering', string $evidence = 'verified'): Skill
    {
        $item = Skill::create([
            'slug' => $slug,
            'name_en' => 'Reviewed '.$slug,
            'name_ar' => 'مهارة موثقة '.$slug,
            'category' => $category,
            'evidence_status' => $evidence,
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $item->id]);
        return $item;
    }

    public function test_empty_guest_feed_is_bilingual_and_never_invents_ai_demos(): void
    {
        Http::fake();
        foreach (['en', 'ar'] as $lang) {
            $this->getJson('/api/v1/public/expertise/ai?lang='.$lang)->assertOk()
                ->assertJsonCount(0, 'data.skills')
                ->assertJsonCount(0, 'data.workflows')
                ->assertJsonCount(0, 'data.verified_demos')
                ->assertJsonCount(2, 'data.safety_notes')
                ->assertJsonPath('meta.lang', $lang)
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertDontSee('PRIVATE_UNREVIEWED_AI_BIO');
        }
        Http::assertNothingSent();
    }

    public function test_only_active_published_owner_verified_ai_categories_are_exposed(): void
    {
        $other = $this->profile('reader-ai@example.test', 'reader');
        $this->skill($other, 'foreign-ai');
        $disabled = $this->profile('disabled-ai@example.test', 'owner', 'disabled');
        $this->skill($disabled, 'inactive-ai');
        $draft = $this->profile('draft-ai@example.test', 'owner', 'active', 'draft');
        $this->skill($draft, 'draft-ai');

        $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(0, 'data.skills')
            ->assertDontSee('foreign-ai')
            ->assertDontSee('inactive-ai')
            ->assertDontSee('draft-ai');

        $owner = $this->profile('published-ai@example.test');
        $approved = $this->skill($owner, 'generative-reviewed', 'Generative AI');
        $this->skill($owner, 'machine-learning-reviewed', 'Machine Learning');
        $this->skill($owner, 'training-is-not-ai', 'Training');
        $this->skill($owner, 'database-is-not-ai', 'Database & Reliability');
        $revoked = $this->skill($owner, 'unapproved-private-ai', 'AI Engineering', 'unverified');

        $this->getJson('/api/v1/public/expertise/ai?lang=en')->assertOk()
            ->assertJsonCount(2, 'data.skills')
            ->assertJsonPath('data.skills.0.slug', 'generative-reviewed')
            ->assertJsonPath('data.skills.0.name', 'Reviewed generative-reviewed')
            ->assertDontSee('training-is-not-ai')->assertDontSee('database-is-not-ai')
            ->assertDontSee('unapproved-private-ai')->assertDontSee('PRIVATE_UNREVIEWED_AI_BIO');
        $this->getJson('/api/v1/public/expertise/ai?lang=ar')->assertOk()
            ->assertJsonPath('data.skills.0.name', 'مهارة موثقة generative-reviewed')
            ->assertJsonPath('data.safety_notes.0',
                'تتطلب مخرجات الذكاء الاصطناعي مراجعة بشرية قبل استخدامها.');

        $revoked->update(['evidence_status' => 'verified']);
        $this->getJson('/api/v1/public/expertise/ai')->assertJsonCount(3, 'data.skills');
        $approved->update(['evidence_status' => 'unverified']);
        $this->getJson('/api/v1/public/expertise/ai')->assertJsonCount(2, 'data.skills');
        $owner->update(['publish_status' => 'draft']);
        $this->getJson('/api/v1/public/expertise/ai')->assertJsonCount(0, 'data.skills');
    }

    public function test_only_profile_selected_by_canonical_public_owner_policy_is_used(): void
    {
        $first = $this->profile('first-ai-owner@example.test');
        $this->skill($first, 'first-owner-skill');
        $second = $this->profile('second-ai-owner@example.test');
        $this->skill($second, 'second-owner-skill');

        $canonical = $this->getJson('/api/v1/public/profile')->assertOk()->json('data.id');
        $expected = $canonical === $first->id ? 'first-owner-skill' : 'second-owner-skill';
        $other = $canonical === $first->id ? 'second-owner-skill' : 'first-owner-skill';
        $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(1, 'data.skills')
            ->assertJsonPath('data.skills.0.slug', $expected)
            ->assertDontSee($other);
    }

    public function test_cap_applies_after_review_and_invalid_label_checks(): void
    {
        $owner = $this->profile('capped-ai-owner@example.test');
        for ($n = 1; $n <= 5; $n++) {
            $this->skill($owner, sprintf('a-unverified-%02d', $n), 'AI Engineering', 'unverified');
        }
        for ($n = 1; $n <= 14; $n++) {
            $this->skill($owner, sprintf('verified-%02d', $n));
        }
        $this->skill($owner, 'unrelated-software', 'Software & Integration');

        $response = $this->getJson('/api/v1/public/expertise/ai')->assertOk()
            ->assertJsonCount(12, 'data.skills')
            ->assertJsonPath('data.skills.0.slug', 'verified-01')
            ->assertJsonPath('data.skills.11.slug', 'verified-12')
            ->assertDontSee('a-unverified-')
            ->assertDontSee('verified-13')
            ->assertDontSee('unrelated-software')
            ->assertJsonCount(0, 'data.verified_demos');
        $this->assertCount(12, array_unique(array_column($response->json('data.skills'), 'slug')));
    }

    public function test_invalid_locale_is_rejected_without_information_disclosure(): void
    {
        foreach ([
            '/api/v1/public/expertise/ai?lang=fr',
            '/api/v1/public/expertise/ai?lang[]=ar',
            '/api/v1/public/expertise/ai?lang[secret]=en',
        ] as $url) {
            $this->getJson($url)->assertUnprocessable();
        }
    }
}

<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AiEngineeringScreenJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilingual_ai_engineering_surface_is_real_and_fail_closed_without_evidence(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $page = $this->get('/ai-engineering?lang='.$locale)->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee($locale === 'ar' ? 'هندسة الذكاء الاصطناعي' : 'AI Engineering')
                ->assertSee($locale === 'ar' ? 'تصميم سير عمل الذكاء الاصطناعي' : 'AI Workflow Design')
                ->assertSee($locale === 'ar' ? 'ذكاء اصطناعي متعدد المزوّدين' : 'Multi-provider AI')
                ->assertSee($locale === 'ar' ? 'أدلة التسليم' : 'Delivery Evidence')
                ->assertSee('href="'.route('public.projects', ['lang' => $locale]).'"', false)
                ->assertSee('href="'.route('public.github', ['lang' => $locale]).'"', false)
                ->assertDontSee('live provider')
                ->assertDontSee('production model')
                ->assertDontSee('99.9%');

            $page->assertSee($locale === 'ar'
                ? 'لا توجد قدرات ذكاء اصطناعي اجتازت بوابة الأدلة'
                : 'No AI capabilities have cleared the public evidence gate yet.');
        }
    }

    public function test_only_active_published_owner_ai_skills_with_verified_evidence_reach_web06(): void
    {
        $user = User::create(['email' => 'web06-owner@example.test', 'status' => 'active']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Published owner',
            'publish_status' => 'published',
        ]);

        $verified = Skill::create([
            'slug' => 'verified-ai-skill',
            'name_en' => 'Verified AI skill',
            'name_ar' => 'مهارة ذكاء اصطناعي موثقة',
            'category' => 'AI Engineering',
            'evidence_status' => 'verified',
        ]);
        $unverified = Skill::create([
            'slug' => 'private-ai-skill',
            'name_en' => 'PRIVATE AI skill',
            'name_ar' => 'مهارة خاصة',
            'category' => 'AI Engineering',
            'evidence_status' => 'unverified',
        ]);
        DB::table('profile_skill')->insert([
            ['profile_id' => $profile->id, 'skill_id' => $verified->id],
            ['profile_id' => $profile->id, 'skill_id' => $unverified->id],
        ]);

        $this->get('/ai-engineering?lang=en')->assertOk()
            ->assertSee('Verified AI skill')
            ->assertDontSee('PRIVATE AI skill');

        $user->update(['status' => 'inactive']);
        $this->get('/ai-engineering?lang=en')->assertOk()
            ->assertDontSee('Verified AI skill');
    }

    public function test_ai_engineering_rejects_unsupported_locale_instead_of_reflecting_it(): void
    {
        $this->get('/ai-engineering?lang[]=en')->assertSessionHasErrors('lang');
        $this->get('/ai-engineering?lang=SECRET')->assertSessionHasErrors('lang');
    }
}

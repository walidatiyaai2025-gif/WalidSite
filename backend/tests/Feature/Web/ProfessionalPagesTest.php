<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfessionalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $status = 'review'): Profile
    {
        $user = User::create(['email' => uniqid('user', true).'@example.test', 'status' => 'active']);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        return Profile::create([
            'user_id' => $user->id, 'public_name' => 'Real CV Owner',
            'bio_en' => 'Private CV data that must be withheld',
            'bio_ar' => 'بيانات لا تنشر',
            'publish_status' => $status,
        ]);
    }

    public function test_pages_render_bilingual_navigation_and_nonfiction_empty_states(): void
    {
        foreach (['/about', '/expertise', '/hire'] as $route) {
            $this->get($route)->assertOk()
                ->assertSee('lang="en"', false)
                ->assertSee('dir="ltr"', false)
                ->assertSee('Evidence-gated public profile');
            $this->get($route.'?lang=ar')->assertOk()
                ->assertSee('lang="ar"', false)
                ->assertSee('dir="rtl"', false);
        }
        $this->get('/about?lang=ar')->assertSee('href="'.route('public.about', ['lang' => 'en']).'"', false);
    }

    public function test_professional_pages_have_page_specific_bilingual_seo_and_safe_track_links(): void
    {
        $descriptions = [
            'about' => [
                'en' => 'Explore Waleed Atiya public career history and credentials available after evidence review.',
                'ar' => 'اطّلع على الخبرات المهنية والشهادات المنشورة لوليد عطية بعد مراجعة الأدلة.',
            ],
            'expertise' => [
                'en' => 'Explore Waleed Atiya engineering skills approved for public display after evidence verification.',
                'ar' => 'استكشف مهارات وليد عطية الهندسية المعتمدة للنشر بعد التحقق من أدلتها.',
            ],
            'hire' => [
                'en' => 'Filter evidence-reviewed engineering specialties and open the verified public resume by career track.',
                'ar' => 'صفِّ مجالات الخبرة الهندسية المتحقق منها وافتح السيرة العامة حسب المسار المهني.',
            ],
        ];

        foreach ($descriptions as $page => $locales) {
            foreach ($locales as $locale => $description) {
                $route = 'public.'.$page;
                $this->get('/'.$page.'?lang='.$locale)
                    ->assertOk()
                    ->assertSee('name="description" content="'.$description.'"', false)
                    ->assertSee('rel="canonical" href="'.route($route, ['lang' => $locale]).'"', false)
                    ->assertSee('rel="alternate" hreflang="en" href="'.route($route, ['lang' => 'en']).'"', false)
                    ->assertSee('rel="alternate" hreflang="ar" href="'.route($route, ['lang' => 'ar']).'"', false);
            }
        }

        foreach (['database', 'software', 'delivery'] as $track) {
            $this->get('/hire?lang=ar&track='.$track)
                ->assertOk()
                ->assertSee('rel="canonical" href="'.e(route('public.hire', ['lang' => 'ar', 'track' => $track])).'"', false)
                ->assertSee('rel="alternate" hreflang="en" href="'.e(route('public.hire', ['lang' => 'en', 'track' => $track])).'"', false);
        }

        foreach (['/hire?lang=ar&track[]=database', '/hire?lang=ar&track=invalid'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('rel="canonical" href="'.route('public.hire', ['lang' => 'ar']).'"', false)
                ->assertDontSee('rel="canonical" href="'.e(route('public.hire', ['lang' => 'ar', 'track' => 'database'])).'"', false);
        }
    }

    public function test_private_cv_never_leaks_until_published_and_individual_evidence_approved(): void
    {
        $profile = $this->profile();
        CareerPosition::create([
            'profile_id' => $profile->id, 'org_name' => 'Private Employer',
            'role_en' => 'Secret Role', 'role_ar' => 'وظيفة خاصة',
            'started_on' => '2024-01-01', 'verified' => true, 'publishable' => true,
        ]);
        Certification::create([
            'profile_id' => $profile->id, 'title' => 'Unverified Credential',
            'issuer' => 'Unknown Issuer', 'verified' => false,
        ]);
        $skill = Skill::create([
            'slug' => 'safe-test-skill', 'name_en' => 'Unverified Skill',
            'name_ar' => 'مهارة قيد المراجعة', 'category' => 'Database & Reliability',
            'evidence_status' => 'unverified',
        ]);
        DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);

        $this->get('/about')->assertOk()->assertDontSee('Private Employer')->assertDontSee('Secret Role')
            ->assertDontSee('Unverified Credential')->assertDontSee('Private CV data');
        $this->get('/expertise')->assertDontSee('Unverified Skill');

        $profile->update(['publish_status' => 'published']);
        $this->get('/about')->assertSee('Private Employer')->assertSee('Secret Role');
        $this->get('/about')->assertDontSee('Unverified Credential')->assertDontSee('Private CV data');
        $this->get('/expertise')->assertDontSee('Unverified Skill');
        $skill->update(['evidence_status' => 'verified']);
        $this->get('/expertise')->assertSee('Unverified Skill');
    }

    public function test_recruiter_track_filter_operates_on_published_verified_skills_only(): void
    {
        $profile = $this->profile('published');
        foreach ([
            ['slug' => 'db-test', 'name' => 'Evidence Database Skill', 'category' => 'Database & Reliability'],
            ['slug' => 'delivery-test', 'name' => 'Evidence Delivery Skill', 'category' => 'Project Delivery'],
        ] as $item) {
            $skill = Skill::create([
                'slug' => $item['slug'], 'name_en' => $item['name'],
                'name_ar' => $item['name'], 'category' => $item['category'],
                'evidence_status' => 'verified',
            ]);
            DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);
        }
        $this->get('/hire?track=database')->assertOk()->assertSee('Evidence Database Skill')->assertDontSee('Evidence Delivery Skill');
        $this->get('/hire?track=delivery')->assertOk()->assertSee('Evidence Delivery Skill')->assertDontSee('Evidence Database Skill');
        $this->get('/hire?track=not-valid')->assertOk()->assertSee('Evidence Database Skill');
    }
    public function test_published_non_owner_and_inactive_owner_are_never_publicly_visible(): void
    {
        $reader = User::create(['email' => 'reader@example.test', 'status' => 'active']);
        $profile = Profile::create([
            'user_id' => $reader->id,
            'public_name' => 'Do Not Show Non Owner',
            'publish_status' => 'published',
        ]);
        CareerPosition::create([
            'profile_id' => $profile->id,
            'org_name' => 'Confidential Non Owner Organization',
            'role_en' => 'Sensitive Example',
            'role_ar' => 'مثال خاص',
            'started_on' => '2024-01-01',
            'verified' => true,
            'publishable' => true,
        ]);

        $this->get('/about')->assertOk()
            ->assertDontSee('Do Not Show Non Owner')
            ->assertDontSee('Confidential Non Owner Organization');
        $this->get('/expertise')->assertOk()->assertDontSee('Do Not Show Non Owner');
        $this->get('/hire')->assertOk()->assertDontSee('Do Not Show Non Owner');

        $reader->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $reader->update(['status' => 'pending_activation']);
        $this->get('/about')->assertOk()->assertDontSee('Confidential Non Owner Organization');

        $reader->update(['status' => 'active']);
        $this->get('/about')->assertOk()->assertSee('Confidential Non Owner Organization');
    }

}

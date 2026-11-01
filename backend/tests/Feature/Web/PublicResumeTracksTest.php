<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicResumeTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_tracks_show_only_owner_linked_verified_skills(): void
    {
        $owner = User::create(['email' => 'cv-tracks@example.test', 'status' => 'active']);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $profile = Profile::create([
            'user_id' => $owner->id, 'public_name' => 'Published CV Owner',
            'publish_status' => 'published',
        ]);

        foreach ([
            ['database-evidence', 'Database Evidence Skill', 'Database & Reliability', 'verified'],
            ['software-evidence', 'Software Evidence Skill', 'Software & Integration', 'verified'],
            ['infrastructure-evidence', 'Infrastructure Evidence Skill', 'Infrastructure & Security', 'verified'],
            ['delivery-evidence', 'Delivery Evidence Skill', 'Project Delivery', 'verified'],
            ['custom-evidence', 'Specialized Evidence Skill', 'Specialized Domain', 'verified'],
            ['private-evidence', 'Private Evidence Skill', 'Database & Reliability', 'unverified'],
        ] as [$slug, $name, $category, $status]) {
            $skill = Skill::create([
                'slug' => $slug, 'name_en' => $name, 'name_ar' => $name,
                'category' => $category, 'evidence_status' => $status,
            ]);
            DB::table('profile_skill')->insert(['profile_id' => $profile->id, 'skill_id' => $skill->id]);
        }
        $other = Skill::create([
            'slug' => 'unrelated-owner', 'name_en' => 'Other Profile Evidence',
            'name_ar' => 'Other Profile Evidence', 'category' => 'Database & Reliability',
            'evidence_status' => 'verified',
        ]);

        $this->get('/resume?track=database')->assertOk()
            ->assertSee('Database Evidence Skill')
            ->assertDontSee('Delivery Evidence Skill')
            ->assertDontSee('Software Evidence Skill')
            ->assertDontSee('Private Evidence Skill')
            ->assertDontSee('Other Profile Evidence')
            ->assertSee('aria-current="page"', false);

        $this->get('/resume?track=software')->assertOk()
            ->assertSee('Software Evidence Skill')
            ->assertSee('Infrastructure Evidence Skill')
            ->assertDontSee('Database Evidence Skill');
        $this->get('/resume?track=delivery')->assertOk()
            ->assertSee('Delivery Evidence Skill')
            ->assertDontSee('Database Evidence Skill');
        $this->get('/resume?track=unknown')->assertOk()
            ->assertSee('Database Evidence Skill')
            ->assertSee('Delivery Evidence Skill')
            ->assertDontSee('Private Evidence Skill');
        $this->get('/resume?track[]=database')->assertOk()
            ->assertSee('Database Evidence Skill')
            ->assertSee('Delivery Evidence Skill');

        // The same four persisted categories have translated *display*
        // labels in Arabic, while English and unknown verified categories
        // remain honest and the underlying track filter is unchanged.
        $arabic = $this->get('/resume?lang=ar&track=all')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('قواعد البيانات والموثوقية')
            ->assertSee('البرمجيات والتكامل')
            ->assertSee('البنية التحتية والأمن')
            ->assertSee('إدارة وتسليم المشاريع')
            ->assertSee('Specialized Domain')
            ->assertDontSee('Private Evidence Skill')
            ->assertDontSee('Other Profile Evidence');
        foreach (['Database & Reliability', 'Software & Integration',
                  'Infrastructure & Security', 'Project Delivery'] as $category) {
            $arabic->assertDontSee('<p>'.e($category).'</p>', false);
        }

        $english = $this->get('/resume?lang=en&track=all')->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Specialized Domain');
        foreach (['Database & Reliability', 'Software & Integration',
                  'Infrastructure & Security', 'Project Delivery'] as $category) {
            $english->assertSee('<p>'.e($category).'</p>', false);
        }

        $this->get('/resume?lang=ar&track=software')->assertOk()
            ->assertSee('Software Evidence Skill')
            ->assertSee('Infrastructure Evidence Skill')
            ->assertSee('البرمجيات والتكامل')
            ->assertSee('البنية التحتية والأمن')
            ->assertDontSee('Database Evidence Skill')
            ->assertDontSee('Delivery Evidence Skill')
            ->assertDontSee('Specialized Evidence Skill');
        $this->get('/resume?lang=ar&track=database')->assertOk()
            ->assertSee('قواعد البيانات والموثوقية')
            ->assertDontSee('Software Evidence Skill');
    }

    public function test_language_switch_retains_selected_track_without_losing_rtl(): void
    {
        $this->get('/resume?lang=ar&track=database')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('قواعد البيانات')
            ->assertSee(e(route('public.resume', ['lang' => 'en', 'track' => 'database'])), false);
        $this->get('/resume?lang=en&track=software')->assertOk()
            ->assertSee(e(route('public.resume', ['lang' => 'ar', 'track' => 'software'])), false);
    }

    public function test_unpublished_resume_stays_private_for_every_track(): void
    {
        $this->get('/resume?track=database')->assertOk()->assertSee('Public CV review is pending');
        $this->get('/resume?lang=ar&track=delivery')->assertOk()->assertSee('السيرة العامة قيد المراجعة');
    }
}

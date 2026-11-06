<?php

namespace Tests\Feature\Web;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WEB-03: the visible category action must filter the verified skills on
 * /expertise, never silently switch to an unrelated recruiter-track filter.
 * This is functional acceptance, not synthetic zero-pixel visual evidence.
 */
final class Web03ReferenceJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function publishedOwner(): Profile
    {
        $user = User::create([
            'email' => 'web03-owner@example.test',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        return Profile::create([
            'user_id' => $user->id,
            'public_name' => 'Reviewed WEB-03 owner',
            'publish_status' => 'published',
        ]);
    }

    private function skill(Profile $profile, string $slug, string $category, bool $verified = true): void
    {
        $skill = Skill::create([
            'slug' => $slug,
            'name_en' => 'Reviewed '.$slug,
            'name_ar' => 'موثق '.$slug,
            'category' => $category,
            'evidence_status' => $verified ? 'verified' : 'unverified',
        ]);

        DB::table('profile_skill')->insert([
            'profile_id' => $profile->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_reference_stage_has_one_primary_heading_and_real_keyboard_reachable_expertise_content(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $response = $this->get(route('public.expertise', ['lang' => $locale]))
                ->assertOk()
                ->assertDontSee('class="t007-reference-stage"', false)
                ->assertSee('class="expertise-hero"', false)
                ->assertSee('class="expertise-tab-nav"', false)
                ->assertSee('id="expertise-title"', false)
                ->assertSee('id="expertise-catalog"', false)
                ->assertSee('aria-labelledby="expertise-title"', false)
                ->assertSee('tabindex="-1"', false)
                ->assertSee('href="#expertise-catalog"', false)
                ->assertSee('href="'.e(route('public.projects', ['lang' => $locale])).'"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);

            preg_match_all('/<h1(?:\\s|>)/i', $response->getContent(), $headings);
            $this->assertCount(1, $headings[0], 'WEB-03 must have one primary heading ('.$locale.')');
            $this->assertStringContainsString(
                '<h1 id="expertise-title">',
                $response->getContent(),
                'The approved four-state hero must supply one true page h1.'
            );
        }
    }

    public function test_each_approved_skill_card_filters_its_real_category_not_a_recruiter_track(): void
    {
        $owner = $this->publishedOwner();
        $categories = [
            'Database & Reliability' => 'database',
            'Software & Integration' => 'integration',
            'Infrastructure & Security' => 'security',
            'Project Delivery' => 'delivery',
        ];
        foreach ($categories as $key => $filter) {
            $this->skill($owner, $filter.'-public', $key);
            $this->skill($owner, $filter.'-private', $key, false);
        }

        foreach (['en', 'ar'] as $locale) {
            $page = $this->get(route('public.expertise', ['lang' => $locale]))
                ->assertOk()->assertSee('role="search"', false);

            foreach ($categories as $category => $filter) {
                $target = route('public.expertise', [
                    'lang' => $locale, 'category' => $filter,
                ]);
                $page->assertSee('class="expertise-card-filter" href="'.e($target).'"', false)
                    ->assertSee($locale === 'ar' ? 'تصفية المهارات حسب ' : 'Filter skills by ')
                    ->assertDontSee($filter.'-private');

                $filtered = $this->get($target)->assertOk()
                    ->assertSee('value="'.$filter.'" selected', false)
                    ->assertSee(($locale === 'ar' ? 'موثق ' : 'Reviewed ').$filter.'-public')
                    ->assertDontSee($filter.'-private');
                foreach ($categories as $otherFilter) {
                    if ($otherFilter !== $filter) {
                        $filtered->assertDontSee(($locale === 'ar' ? 'موثق ' : 'Reviewed ').$otherFilter.'-public');
                    }
                }
            }
        }
    }

    public function test_unapproved_accounts_or_hostile_filter_params_cannot_create_claims_or_fake_navigation(): void
    {
        $owner = $this->publishedOwner();
        $this->skill($owner, 'published-database', 'Database & Reliability');
        $this->skill($owner, 'private-database', 'Database & Reliability', false);

        $outsider = User::create([
            'email' => 'web03-reader@example.test',
            'status' => 'active',
        ]);
        $other = Profile::create([
            'user_id' => $outsider->id,
            'public_name' => 'Private other account',
            'publish_status' => 'published',
        ]);
        $this->skill($other, 'other-account-secret', 'Infrastructure & Security');

        foreach (['category[]=security', 'category=not-allowed'] as $query) {
            $this->get('/expertise?lang=en&'.$query)
                ->assertOk()
                ->assertSee('Reviewed published-database')
                ->assertDontSee('private-database')
                ->assertDontSee('other-account-secret')
                ->assertDontSee('Clear filter')
                ->assertDontSee('not-allowed');
        }
        $this->get('/projects?lang=en')->assertOk();
    }
    public function test_selected_specialty_reports_the_true_verified_count_and_has_keyboard_recovery(): void
    {
        $owner = $this->publishedOwner();
        $this->skill($owner, 'public-database', 'Database & Reliability');
        $this->skill($owner, 'unverified-security', 'Infrastructure & Security', false);

        $reader = User::create(['email' => 'web03-unrelated@example.test', 'status' => 'active']);
        $foreignProfile = Profile::create([
            'user_id' => $reader->id,
            'public_name' => 'Unrelated account',
            'publish_status' => 'published',
        ]);
        $this->skill($foreignProfile, 'foreign-security', 'Infrastructure & Security');

        foreach (['en', 'ar'] as $locale) {
            $security = $this->get(route('public.expertise', [
                'lang' => $locale, 'category' => 'security',
            ]))->assertOk()
                ->assertSee('id="expertise-filter-status"', false)
                ->assertSee('aria-live="polite"', false)
                ->assertSee('aria-describedby="expertise-filter-hint"', false)
                ->assertSee('value="security" selected', false)
                ->assertDontSee('unverified-security')
                ->assertDontSee('foreign-security');

            $security->assertSee($locale === 'ar'
                ? 'لا توجد مهارات منشورة وموثقة في هذا التخصص.'
                : 'No evidence-verified published skills match this specialty.')
                ->assertSee($locale === 'ar'
                    ? 'المهارات الموثقة المطابقة: 0'
                    : 'Matching verified skills: 0')
                ->assertSee(
                    'href="'.e(route('public.expertise', ['lang' => $locale])).'"',
                    false
                )
                ->assertDontSee($locale === 'ar'
                    ? 'لم تُعتمد مهارات للنشر بعد.'
                    : 'No skills have been approved with verified evidence yet.');

            $database = $this->get(route('public.expertise', [
                'lang' => $locale, 'category' => 'database',
            ]))->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'المهارات الموثقة المطابقة: 1'
                    : 'Matching verified skills: 1')
                ->assertSee($locale === 'ar' ? 'موثق public-database' : 'Reviewed public-database')
                ->assertSee('class="expertise-card-filter" href="', false)
                ->assertSee('aria-current="page" aria-describedby="expertise-filter-status"', false)
                ->assertDontSee('foreign-security');

            $this->assertStringContainsString(
                'href="'.e(route('public.hire', ['lang' => $locale, 'track' => 'database'])).'"',
                $database->getContent(),
                'The separate recruiter action from #660 must remain reachable.'
            );
        }
    }

    public function test_unfiltered_and_invalid_specialties_are_never_misreported_as_a_selected_filter(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['', '&category%5B%5D=security', '&category=UNTRUSTED_TOKEN'] as $extra) {
                $html = $this->get('/expertise?lang='.$locale.$extra)
                    ->assertOk()
                    ->assertSee($locale === 'ar'
                        ? 'لم تُعتمد مهارات للنشر بعد.'
                        : 'No skills have been approved with verified evidence yet.')
                    ->assertDontSee('id="expertise-filter-status"', false)
                    ->assertDontSee('UNTRUSTED_TOKEN')
                    ->getContent();
                $this->assertStringNotContainsString('href="http://UNTRUSTED_TOKEN', $html);
            }
            // A syntactically valid, source-empty filter has a different
            // truthful recovery path instead of claiming the whole site lacks skills.
            $this->get('/expertise?lang='.$locale.'&category=delivery')
                ->assertOk()
                ->assertSee('id="expertise-filter-status"', false)
                ->assertSee($locale === 'ar'
                    ? 'عرض جميع التخصصات'
                    : 'View all specialties');
        }
    }

    public function test_mobile_hero_art_is_contained_without_replacing_the_approved_illustration(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->get(route('public.expertise', ['lang' => $locale]))
                ->assertOk()
                ->assertSee('class="expertise-art"', false)
                ->assertSee('viewBox="0 0 700 340"', false)
                ->getContent();

            // Mobile-only containment prevents the illustration from widening
            // the document. Keep the approved desktop geometry and SVG intact.
            $this->assertStringContainsString(
                '.expertise-art{height:296px;min-width:0;display:flex;',
                $html
            );
            $this->assertStringContainsString('@media (max-width: 700px)', $html);
            $this->assertStringContainsString(
                'body:has(.expertise-screen) .expertise-art {',
                $html
            );
            $this->assertStringContainsString('overflow: clip;', $html);
            $this->assertStringContainsString('max-width: 100%;', $html);
        }
    }

}

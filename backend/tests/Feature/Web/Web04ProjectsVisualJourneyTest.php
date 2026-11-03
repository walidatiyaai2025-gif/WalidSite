<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Web04ProjectsVisualJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function reviewedProject(string $slug, int $daysAgo, bool $public = true): Project
    {
        $project = Project::create([
            'slug' => $slug,
            'title' => 'Reviewed '.$slug,
            'summary_en' => 'Published technical evidence for '.$slug,
            'summary_ar' => 'أدلة تقنية منشورة للمشروع '.$slug,
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => $public ? 'public' : 'private',
            'published_at' => now()->subDays($daysAgo),
        ]);
        ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/public-reviewed-proof',
            'visibility' => 'public',
            'verified_at' => now()->subDays($daysAgo),
        ]);
        return $project;
    }

    public function test_bilingual_reference_viewport_has_one_real_heading_and_safe_empty_state(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->get(route('public.projects', ['lang' => $locale]))
                ->assertOk()
                ->assertSee('class="web04-projects"', false)
                ->assertSee('class="web04-hero"', false)
                ->assertSee('class="web04-preview-grid"', false)
                ->assertSee('id="web04-project-catalog"', false)
                ->assertSee('href="#web04-project-catalog"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee($locale === 'ar'
                    ? 'لا توجد حاليًا مشاريع منشورة تستوفي أدلة النشر العامة.'
                    : 'No published projects currently meet the public evidence checks.')
                ->assertDontSee('Multi-application commerce platform')
                ->assertDontSee('FOODEX')
                ->getContent();

            $this->assertSame(1, substr_count($html, '<h1'));
            $this->assertStringContainsString(
                'href="'.e(route('public.github', ['lang' => $locale])).'"',
                $html
            );
            $this->assertStringContainsString(
                'href="'.e(route('public.contact', ['lang' => $locale])).'"',
                $html
            );
        }
    }

    public function test_no_published_projects_use_truthful_three_card_navigation_in_both_locales(): void
    {
        // A real empty database must never render the reference mockup's fictitious case-study titles.
        $this->reviewedProject('private-claim', 1, false);

        foreach (['en', 'ar'] as $locale) {
            $html = $this->get('/projects?lang='.$locale)
                ->assertOk()
                ->assertSee($locale === 'ar' ? 'التحقق قيد الانتظار' : 'Verification pending')
                ->assertSee($locale === 'ar' ? 'المستودعات العامة' : 'Public repositories')
                ->assertSee($locale === 'ar' ? 'تواصل حول مشروع' : 'Discuss a project')
                ->assertDontSee('private-claim')
                ->assertDontSee('FOODEX')
                ->assertDontSee('AIMWWeb')
                ->getContent();

            $preview = explode('</section>', explode('<section class="web04-preview"', $html, 2)[1], 2)[0];
            $this->assertSame(3, substr_count($preview, '<article class="web04-preview-card'));
            $this->assertStringNotContainsString('public.project', $preview);
            $this->assertStringContainsString(
                'href="'.e(route('public.github', ['lang' => $locale])).'"', $preview
            );
            $this->assertStringContainsString(
                'href="'.e(route('public.contact', ['lang' => $locale])).'"', $preview
            );
            $this->assertStringContainsString('href="#web04-project-catalog"', $preview);
            $this->assertStringContainsString(
                $locale === 'ar' ? 'حالة المشاريع والروابط المفيدة' : 'Project availability and resources',
                $preview
            );
        }
    }

    public function test_empty_out_of_range_page_keeps_global_publication_status_truthful(): void
    {
        $this->reviewedProject('actually-published', 1);

        foreach (['en', 'ar'] as $locale) {
            $html = $this->get('/projects?lang='.$locale.'&page=7')
                ->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'لا تظهر دراسات حالة عامة موثقة في هذه الصفحة. عد إلى الصفحة الأولى.'
                    : 'No evidence-verified public case studies on this page. Return to the first page.')
                ->assertSee($locale === 'ar' ? 'الصفحة الأولى' : 'First page')
                ->assertDontSee($locale === 'ar'
                    ? 'لا توجد حاليًا مشاريع منشورة تستوفي أدلة النشر العامة.'
                    : 'No published projects currently meet the public evidence checks.')
                ->getContent();
            $preview = explode('</section>', explode('<section class="web04-preview"', $html, 2)[1], 2)[0];
            $this->assertSame(3, substr_count($preview, '<article class="web04-preview-card'));
            $this->assertStringContainsString(
                'href="'.e(route('public.projects', ['lang' => $locale])).'"', $preview
            );
        }
    }

    public function test_accessible_locale_switch_preserves_only_validated_listing_filters(): void
    {
        $this->reviewedProject('approved-public', 1);

        foreach (['en', 'ar'] as $locale) {
            $opposite = $locale === 'en' ? 'ar' : 'en';
            $html = $this->get('/projects?lang='.$locale.'&q=approved&status=completed&unsafe=PRIVATE_TOKEN')
                ->assertOk()
                ->assertSee($locale === 'ar' ? 'تغيير اللغة' : 'Change language')
                ->assertDontSee('PRIVATE_TOKEN')
                ->getContent();
            $this->assertStringContainsString(
                'class="case-locale-switch"',
                $html
            );
            $this->assertStringContainsString(
                'href="'.e(route('public.projects', [
                    'lang' => $opposite,
                    'q' => 'approved',
                    'status' => 'completed',
                ])).'"',
                $html
            );
            $this->get(route('public.projects', [
                'lang' => $opposite,
                'q' => 'approved',
                'status' => 'completed',
            ]))->assertOk()->assertSee('Reviewed approved-public');
        }
    }

    public function test_first_three_canonical_cards_link_to_real_published_case_studies(): void
    {
        for ($day = 1; $day <= 4; $day++) {
            $this->reviewedProject('approved-'.$day, $day);
        }
        $this->reviewedProject('secret-internal', 1, false);

        foreach (['en', 'ar'] as $locale) {
            $html = $this->get('/projects?lang='.$locale)->assertOk()
                ->assertDontSee('secret-internal')
                ->getContent();

            $this->assertSame(3, substr_count($html, 'class="web04-preview-card"'));
            $preview = explode('</section>', explode('<section class="web04-preview"', $html, 2)[1], 2)[0];
            for ($day = 1; $day <= 3; $day++) {
                $slug = 'approved-'.$day;
                $url = route('public.project', ['slug' => $slug, 'lang' => $locale]);
                $this->assertStringContainsString('Reviewed '.$slug, $preview);
                $this->assertStringContainsString('href="'.e($url).'"', $preview);
                $this->get($url)->assertOk()->assertSee('Reviewed '.$slug);
            }
            $this->assertStringNotContainsString('Reviewed approved-4', $preview);
        }
    }

    public function test_search_and_status_filters_preserve_verified_safety_in_both_viewports(): void
    {
        $this->reviewedProject('approved-public', 2);
        $this->reviewedProject('private-case', 1, false);

        foreach (['en', 'ar'] as $locale) {
            $this->get('/projects?lang='.$locale.'&q=approved-public&status=completed')
                ->assertOk()
                ->assertSee('Reviewed approved-public')
                ->assertSee('name="q"', false)
                ->assertSee('name="status"', false)
                ->assertDontSee('private-case');

            $this->get('/projects?lang='.$locale.'&q=missing&status=completed')
                ->assertOk()
                ->assertSee($locale === 'ar'
                    ? 'لا توجد دراسات حالة عامة موثقة تطابق البحث أو المرشحات.'
                    : 'No evidence-verified public case studies match your search or filters.')
                ->assertSee($locale === 'ar' ? 'مسح المرشحات' : 'Clear filters')
                ->assertDontSee($locale === 'ar'
                    ? 'لا توجد حاليًا مشاريع منشورة تستوفي أدلة النشر العامة.'
                    : 'No published projects currently meet the public evidence checks.')
                ->assertDontSee('Reviewed approved-public')
                ->assertDontSee('private-case');

            $this->get('/projects?lang='.$locale.'&q%5B0%5D=PRIVATE_TOKEN')
                ->assertOk()->assertDontSee('PRIVATE_TOKEN');
        }
    }
    public function test_one_or_two_verified_projects_fill_unused_preview_slots_with_real_resources(): void
    {
        $this->reviewedProject('never-public', 1, false);

        for ($count = 1; $count <= 2; $count++) {
            $this->reviewedProject('visible-'.$count, $count);

            foreach (['en', 'ar'] as $locale) {
                $html = $this->get('/projects?lang='.$locale)
                    ->assertOk()
                    ->assertDontSee('never-public')
                    ->getContent();
                $preview = explode('</section>', explode('<section class="web04-preview"', $html, 2)[1], 2)[0];

                $this->assertSame(3, substr_count($preview, '<article class="web04-preview-card'));
                $this->assertSame($count, substr_count($preview, 'class="web04-preview-card"'));
                $this->assertSame(3 - $count, substr_count($preview, 'web04-preview-card--resource'));
                $this->assertStringContainsString(
                    'href="'.e(route('public.github', ['lang' => $locale])).'"', $preview
                );
                if ($count === 1) {
                    $this->assertStringContainsString(
                        'href="'.e(route('public.contact', ['lang' => $locale])).'"', $preview
                    );
                } else {
                    $this->assertStringNotContainsString('web04-card-icon" aria-hidden="true">@', $preview);
                }
                for ($index = 1; $index <= $count; $index++) {
                    $this->assertStringContainsString('Reviewed visible-'.$index, $preview);
                    $this->assertStringContainsString(
                        'href="'.e(route('public.project', [
                            'slug' => 'visible-'.$index, 'lang' => $locale,
                        ])).'"', $preview
                    );
                }
            }
        }
    }

    public function test_preview_case_study_preserves_validated_filters_and_page_on_return(): void
    {
        for ($index = 1; $index <= 10; $index++) {
            $this->reviewedProject('approved-'.$index, $index);
        }
        $this->reviewedProject('private-matching-claim', 1, false);

        foreach (['en', 'ar'] as $locale) {
            $params = ['lang' => $locale, 'q' => 'approved', 'status' => 'completed', 'page' => 2];
            $html = $this->get('/projects?'.http_build_query(array_merge(
                $params, ['unsafe' => 'PRIVATE_TOKEN']
            )))->assertOk()->assertDontSee('PRIVATE_TOKEN')->getContent();
            $preview = explode('</section>', explode('<section class="web04-preview"', $html, 2)[1], 2)[0];

            $detail = route('public.project', array_merge(['slug' => 'approved-10'], $params));
            $this->assertStringContainsString('href="'.e($detail).'"', $preview);
            $this->assertSame(3, substr_count($preview, '<article class="web04-preview-card'));
            $this->assertStringNotContainsString('private-matching-claim', $preview);

            $study = $this->get($detail)->assertOk()->getContent();
            $this->assertStringContainsString(
                'href="'.e(route('public.projects', $params)).'"',
                $study
            );
        }
    }


    public function test_mobile_cards_can_grow_and_unsafe_long_verified_project_copy_remains_text(): void
    {
        // Real approved content can exceed the short labels in the immutable
        // visual reference. Narrow cards must not let fixed CTAs cover text,
        // and translated/untrusted fields must never become executable HTML.
        $project = $this->reviewedProject('long-public-project', 1);
        $project->update([
            'title' => 'PUBLIC <img src=x onerror=alert(1)> '.str_repeat('verified ', 8),
            'summary_en' => 'UNSAFE <script>alert(1)</script> '.str_repeat('documented ', 8),
            'summary_ar' => 'UNSAFE <svg onload=alert(1)> '.str_repeat('دليل ', 12),
        ]);

        foreach (['en', 'ar'] as $locale) {
            $url = route('public.project', [
                'slug' => 'long-public-project', 'lang' => $locale,
            ]);
            $html = $this->get('/projects?lang='.$locale)
                ->assertOk()
                ->assertSee('PUBLIC &lt;img src=x onerror=alert(1)&gt;', false)
                ->assertDontSee('<img src=x onerror=alert(1)>', false)
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<svg onload=alert(1)>', false)
                ->getContent();

            $preview = explode('</section>', explode('<section class="web04-preview"', $html, 2)[1], 2)[0];
            $this->assertStringContainsString('href="'.e($url).'"', $preview);
            $this->assertStringContainsString('class="web04-preview-card"', $preview);
            $this->get($url)->assertOk();
        }

        // These are static CSS regression guards, NOT a claim of runtime pixel
        // matching. Browser/DPR screenshots remain required under Issue #481.
        $html = $this->get('/projects?lang=en')->assertOk()->getContent();
        $mobileCss = explode('@media (max-width:900px)', $html, 2)[1];
        $this->assertStringContainsString('width:min(100%,372px)', $mobileCss);
        $this->assertStringContainsString('aspect-ratio:372 / 226', $mobileCss);
        $this->assertStringContainsString('transform:none', $mobileCss);
        $this->assertStringContainsString('min-height:315px; display:flex; flex-direction:column', $mobileCss);
        $this->assertStringContainsString('position:static; margin-top:10px', $mobileCss);
    }


}

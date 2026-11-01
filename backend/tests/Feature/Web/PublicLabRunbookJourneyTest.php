<?php

namespace Tests\Feature\Web;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicLabRunbookJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function guide(string $slug, bool $approved = true, ?string $markdown = null): string
    {
        $id = (string) Str::uuid();
        $body = $markdown ?? '# Reviewed runbook '.$slug;
        $title = 'Reviewed '.$slug.' runbook';
        DB::table('runbooks')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body_md' => $body,
            'version' => 1,
            'validated_at' => $approved ? now()->subMinutes(2) : null,
            'offline_ok' => true,
        ]);
        DB::table('runbook_versions')->insert([
            'id' => (string) Str::uuid(),
            'runbook_id' => $id,
            'version' => 1,
            'body_md' => $body,
            'reviewed_title' => $title,
            'checksum' => hash('sha256', $body),
            'created_at' => now()->subMinutes(3),
        ]);

        return $id;
    }

    public function test_lab_empty_states_remain_honest_and_non_cacheable_in_both_locales(): void
    {
        Http::fake();
        $en = $this->get('/lab?lang=en')->assertOk()
            ->assertSee('No reviewed guides or live tool demos')
            ->assertDontSee('Approved runbooks')
            ->assertSee('lang="en"', false);
        $ar = $this->get('/lab?lang=ar')->assertOk()
            ->assertSee('لا توجد أدلة منشورة')
            ->assertSee('dir="rtl"', false);
        $this->assertStringContainsString('no-store', $en->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $ar->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_approved_runbook_has_navigable_bilingual_html_detail_with_safe_markdown(): void
    {
        Http::fake();
        $id = $this->guide('recovery', true,
            "# Approved instructions\n\n**Checked** safe step.\n\n<script>alert('leak')</script>\n\n[unsafe link](javascript:alert(1))");
        $urlEn = route('public.lab.runbook', ['id' => $id, 'lang' => 'en']);
        $urlAr = route('public.lab.runbook', ['id' => $id, 'lang' => 'ar']);

        $this->get('/lab?lang=en')->assertOk()
            ->assertSee('Reviewed recovery runbook')
            ->assertSee('Approved runbooks')
            ->assertSee('href="'.$urlEn.'"', false)
            ->assertDontSee('No reviewed guides or live tool demos');
        $this->get('/lab?lang=ar')->assertOk()
            ->assertSee('أدلة التشغيل المعتمدة')
            ->assertSee('href="'.$urlAr.'"', false)
            ->assertSee('dir="rtl"', false);
        $detail = $this->get('/lab/runbooks/'.$id.'?lang=en')->assertOk()
            ->assertSee('Version-reviewed operational guide')
            ->assertSee('Reviewed recovery runbook')
            ->assertSee('<strong>Checked</strong>', false)
            ->assertDontSee("<script>alert('leak')</script>", false)
            ->assertSee('&lt;script&gt;alert', false)
            ->assertDontSee('href="javascript:', false)
            ->assertSee('href="'.$urlAr.'"', false)
            ->assertSee('href="'.route('public.lab', ['lang' => 'en']).'"', false);
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));

        $this->get('/lab/runbooks/'.$id.'?lang=ar')->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('العودة إلى المختبر التقني')
            ->assertSee('href="'.$urlEn.'"', false);
        Http::assertNothingSent();
    }

    public function test_only_version_reviewed_runbooks_offer_accessible_bilingual_code_copy_actions(): void
    {
        Http::fake();
        $id = $this->guide('copy-example', true,
            "# Reviewed recovery steps\n\nRun this after validating the environment:\n\n"
            ."~~~bash\nprintf 'verified source only'\n~~~\n\n"
            ."<script>alert('not executable')</script>");
        $draft = $this->guide('private-copy-example', false,
            "~~~bash\nprintf 'unapproved'\n~~~");

        foreach (['en', 'ar'] as $locale) {
            $response = $this->get(route('public.lab.runbook', [
                'id' => $id, 'lang' => $locale,
            ]))->assertOk()
                ->assertSee('id="runbook-copy-feedback"', false)
                ->assertSee('role="status" aria-live="polite" aria-atomic="true"', false)
                ->assertSee("button.className = 'runbook-copy-action'", false)
                ->assertSee("document.documentElement.lang === 'ar'", false)
                ->assertSee('min-height:44px', false)
                ->assertSee('navigator.clipboard.writeText(code.textContent ||', false)
                ->assertSee('verified source only')
                ->assertDontSee('<script>alert(', false)
                ->assertDontSee('unapproved');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertSee($locale === 'ar' ? 'نسخ الكود' : 'Copy code')
                ->assertSee($locale === 'ar' ? 'تعذر نسخ الكود' : 'Could not copy code')
                ->assertSee($locale === 'ar' ? 'تم نسخ الكود' : 'Code copied');
        }

        $this->get('/lab/runbooks/'.$draft)->assertNotFound();
        DB::table('runbook_versions')->where('runbook_id', $id)
            ->update(['checksum' => str_repeat('f', 64)]);
        $this->get('/lab/runbooks/'.$id.'?lang=en')->assertNotFound();
        $this->get('/lab/runbooks/'.$id.'?lang=ar')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_revoked_or_modified_guide_disappears_and_detail_fails_closed(): void
    {
        Http::fake();
        $draft = $this->guide('private-draft', false);
        $tampered = $this->guide('tampered');
        DB::table('runbook_versions')->where('runbook_id', $tampered)
            ->update(['checksum' => str_repeat('0', 64)]);
        $valid = $this->guide('currently-approved');

        $this->get('/lab')->assertOk()
            ->assertSee('Reviewed currently-approved runbook')
            ->assertDontSee('private-draft')
            ->assertDontSee('tampered');
        foreach ([$draft, $tampered] as $id) {
            $this->get('/lab/runbooks/'.$id)->assertNotFound();
        }

        // Approval is never cached after a page was rendered to a visitor.
        $this->get('/lab/runbooks/'.$valid)->assertOk();
        DB::table('runbooks')->where('id', $valid)->update(['title' => 'Edited without review']);
        $this->get('/lab/runbooks/'.$valid)->assertNotFound();
        $this->get('/lab')->assertOk()
            ->assertSee('No reviewed guides or live tool demos')
            ->assertDontSee('Edited without review');
        Http::assertNothingSent();
    }

    public function test_lab_finds_fifteen_approved_runbooks_beyond_invalid_prefix(): void
    {
        Http::fake();
        for ($i = 1; $i <= 17; $i++) {
            $id = $this->guide(sprintf('a-invalid-%02d', $i));
            DB::table('runbook_versions')->where('runbook_id', $id)
                ->update(['checksum' => str_repeat('f', 64)]);
        }
        $ids = [];
        for ($i = 1; $i <= 16; $i++) {
            $ids[] = $this->guide(sprintf('z-valid-%02d', $i));
        }

        $page = $this->get('/lab?lang=en')->assertOk()
            ->assertSee('Reviewed z-valid-01 runbook')
            ->assertSee('Reviewed z-valid-15 runbook')
            ->assertDontSee('Reviewed z-valid-16 runbook')
            ->assertDontSee('a-invalid-');
        foreach (array_slice($ids, 0, 15) as $id) {
            $page->assertSee('href="'.route('public.lab.runbook', ['id' => $id, 'lang' => 'en']).'"', false);
        }
        $this->get('/lab?lang=ar')->assertOk()
            ->assertSee('Reviewed z-valid-15 runbook')
            ->assertDontSee('Reviewed z-valid-16 runbook');
        Http::assertNothingSent();
    }

    public function test_full_library_browses_verified_runbooks_across_pages_in_arabic_and_english(): void
    {
        Http::fake();
        // Both the public preview limit (15) and the authoritative API page
        // limit (20) must be crossed without disclosing invalid earlier rows.
        for ($i = 1; $i <= 22; $i++) {
            $id = $this->guide(sprintf('a-revoked-%02d', $i));
            DB::table('runbook_versions')->where('runbook_id', $id)
                ->update(['checksum' => str_repeat('f', 64)]);
        }
        $draftId = $this->guide('private-unapproved', false,
            '# PRIVATE-RUNBOOK-CONTENT-MUST-NOT-LEAK');
        $ids = [];
        for ($i = 1; $i <= 22; $i++) {
            $ids[] = $this->guide(sprintf('z-approved-%02d', $i),
                true, '# Reviewed content '.$i);
        }

        foreach (['en', 'ar'] as $lang) {
            $lab = $this->get(route('public.lab', ['lang' => $lang]))->assertOk()
                ->assertSee('href="'.e(route('public.lab.runbooks', [
                    'lang' => $lang,
                ])).'"', false)
                ->assertSee('Reviewed z-approved-15 runbook')
                ->assertDontSee('Reviewed z-approved-16 runbook')
                ->assertDontSee('a-revoked-')
                ->assertDontSee('private-unapproved');

            $first = $this->get(route('public.lab.runbooks', [
                'lang' => $lang,
            ]))->assertOk()
                ->assertSee('lang="'.$lang.'"', false)
                ->assertSee('Reviewed z-approved-01 runbook')
                ->assertSee('Reviewed z-approved-20 runbook')
                ->assertDontSee('Reviewed z-approved-21 runbook')
                ->assertDontSee('Reviewed z-approved-22 runbook')
                ->assertDontSee('a-revoked-')
                ->assertDontSee('private-unapproved')
                ->assertDontSee('PRIVATE-RUNBOOK-CONTENT-MUST-NOT-LEAK')
                ->assertSee('href="'.e(route('public.lab.runbooks', [
                    'lang' => $lang, 'page' => 2,
                ])).'"', false);
            $this->assertStringContainsString(
                'no-store', $first->headers->get('Cache-Control')
            );
            for ($i = 0; $i < 20; $i++) {
                $first->assertSee('href="'.e(route('public.lab.runbook', [
                    'id' => $ids[$i], 'lang' => $lang, 'page' => 1,
                ])).'"', false);
            }

            $second = $this->get(route('public.lab.runbooks', [
                'lang' => $lang, 'page' => 2,
            ]))->assertOk()
                ->assertSee('Reviewed z-approved-21 runbook')
                ->assertSee('Reviewed z-approved-22 runbook')
                ->assertDontSee('Reviewed z-approved-20 runbook')
                ->assertDontSee('a-revoked-')
                ->assertDontSee('private-unapproved')
                ->assertDontSee('PRIVATE-RUNBOOK-CONTENT-MUST-NOT-LEAK')
                ->assertSee('href="'.e(route('public.lab.runbooks', [
                    'lang' => $lang, 'page' => 1,
                ])).'"', false)
                ->assertDontSee('rel="next"', false);
            $this->assertStringContainsString(
                'no-store', $second->headers->get('Cache-Control')
            );
            if ($lang === 'ar') {
                $second->assertSee('dir="rtl"', false)
                    ->assertSee('مكتبة أدلة التشغيل المعتمدة')
                    ->assertSee('الصفحة السابقة');
            } else {
                $second->assertSee('dir="ltr"', false)
                    ->assertSee('Approved runbook library')
                    ->assertSee('Previous page');
            }
            $detail = $this->get(route('public.lab.runbook', [
                'id' => $ids[21], 'lang' => $lang, 'page' => 2,
            ]))->assertOk()
                ->assertSee('Reviewed z-approved-22 runbook')
                ->assertSee('href="'.e(route('public.lab.runbooks', [
                    'lang' => $lang, 'page' => 2,
                ])).'"', false);
            $this->assertStringContainsString(
                'no-store', $detail->headers->get('Cache-Control')
            );
            $lab->assertSee($lang === 'ar'
                ? 'تصفح مكتبة أدلة التشغيل كاملة'
                : 'Browse the full approved runbook library');
        }
        $this->get('/lab/runbooks/'.$draftId)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_library_paging_rechecks_revocations_and_rejects_malformed_inputs(): void
    {
        Http::fake();
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            $ids[] = $this->guide(sprintf('z-still-approved-%02d', $i));
        }

        $this->get('/lab/runbooks?lang=en&page=2')->assertOk()
            ->assertSee('Reviewed z-still-approved-21 runbook');
        // De-authorize two distinct snapshots AFTER a successful read. The
        // next request must repack verified page 1 and drop page 2 entirely.
        DB::table('runbook_versions')->where('runbook_id', $ids[0])
            ->update(['checksum' => str_repeat('0', 64)]);
        DB::table('runbooks')->where('id', $ids[1])
            ->update(['title' => 'PRIVATE-EDITED-WITHOUT-APPROVAL']);

        $list = $this->get('/lab/runbooks?lang=en')->assertOk()
            ->assertSee('Reviewed z-still-approved-21 runbook')
            ->assertDontSee('Reviewed z-still-approved-01 runbook')
            ->assertDontSee('PRIVATE-EDITED-WITHOUT-APPROVAL')
            ->assertDontSee('rel="next"', false);
        $empty = $this->get('/lab/runbooks?lang=ar&page=2')->assertOk()
            ->assertSee('لا توجد أدلة تشغيل معتمدة في هذه الصفحة حاليًا.')
            ->assertSee('href="'.e(route('public.lab.runbooks', [
                'lang' => 'ar', 'page' => 1,
            ])).'"', false)
            ->assertDontSee('PRIVATE-EDITED-WITHOUT-APPROVAL');
        $this->assertStringContainsString(
            'no-store', $empty->headers->get('Cache-Control')
        );

        foreach ([$ids[0], $ids[1]] as $revoked) {
            $this->get('/lab/runbooks/'.$revoked)->assertNotFound();
        }
        foreach ([
            'page%5B%5D=2', 'page=0', 'page=-1', 'page=10001',
            'page=not-an-integer', 'lang%5B%5D=ar', 'lang=fr',
        ] as $invalid) {
            $this->getJson('/lab/runbooks?'.$invalid)->assertUnprocessable();
        }
        $this->getJson('/lab/runbooks/'.$ids[2].'?page[]=2')
            ->assertUnprocessable();
        $this->get('/lab/runbooks?page=10000')->assertOk()
            ->assertDontSee('PRIVATE-EDITED-WITHOUT-APPROVAL')
            ->assertSee('No currently approved runbooks on this page.');
        Http::assertNothingSent();
    }

    public function test_lab_delegation_keeps_verified_article_links_and_rejects_invalid_inputs(): void
    {
        $article = Article::create([
            'slug' => 'verified-lab-article',
            'title_en' => 'Reviewed lab article',
            'title_ar' => 'مقال مختبر موثق',
            'canonical_lang' => 'en',
        ]);
        $article->revisions()->create([
            'revision_no' => 1,
            'body_md' => '# Reviewed guidance',
            'status' => 'validated',
            'citations_json' => [['source_url' => 'https://docs.example.org/guidance', 'verified' => true]],
            'validation_json' => ['citations_passed' => true, 'technical_passed' => true],
        ]);
        $article->publishVerified();

        $this->get('/lab?lang=en')->assertOk()
            ->assertSee('Reviewed lab article')
            ->assertSee('href="'.route('public.article', ['slug' => $article->slug, 'lang' => 'en']).'"', false);
        $this->get('/lab?lang=ar')->assertOk()
            ->assertSee('href="'.route('public.blog', ['lang' => 'ar']).'"', false)
            ->assertSee('مقال مختبر موثق')
            ->assertSee('href="'.route('public.article', ['slug' => $article->slug, 'lang' => 'ar']).'"', false);

        $id = $this->guide('locale-guard');
        $this->getJson('/lab?lang=invalid')->assertUnprocessable();
        $this->getJson('/lab?page=2')->assertUnprocessable();
        $this->getJson('/lab/runbooks/'.$id.'?lang[]=ar')->assertUnprocessable();
        $this->get('/lab/runbooks/not-a-uuid')->assertNotFound();
    }
}

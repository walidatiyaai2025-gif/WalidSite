<?php

namespace Tests\Feature\Web;

use App\Domain\Content\PageContentDefaults;
use App\Models\Role;
use App\Models\SitePageText;
use App\Models\User;
use Database\Seeders\WebsitePageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FirstInstallManagedPageContentTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => Str::lower(Str::random(16)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        return $user;
    }

    private function entry(
        string $page = 'home', string $section = 'hero',
        string $field = 'intro', string $locale = 'en'
    ): SitePageText {
        return SitePageText::query()
            ->where('page_key', $page)->where('section_key', $section)
            ->where('field_key', $field)->where('locale', $locale)
            ->firstOrFail();
    }

    public function test_first_install_default_copy_seeds_all_four_pages_and_four_expertise_states_without_fake_projects(): void
    {
        (new WebsitePageContentSeeder())->run();
        $defaults = PageContentDefaults::all();
        foreach (['home','about','projects','expertise'] as $page) {
            $this->assertArrayHasKey($page, $defaults);
            $this->assertGreaterThan(0, SitePageText::query()->where('page_key', $page)->count());
        }
        foreach (['overview','delivery','technology','ai'] as $section) {
            $this->assertSame(6, SitePageText::query()
                ->where('page_key','expertise')->where('section_key',$section)
                ->where('locale','en')->where('field_key','like','card_%_title')
                ->count());
            $this->assertNotNull($this->entry('expertise', $section, 'hero_description')->draft_text);
            $this->assertNull($this->entry('expertise', $section, 'hero_description')->published_text);
        }
        $this->assertNotNull($this->entry('about','intro','email','en')->published_text);
        $this->assertSame(27, \App\Models\AboutMediaAsset::query()->count());
        $this->assertNotNull($this->entry()->published_text);
        $this->assertNull($this->entry()->draft_text);
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame(0, DB::table('articles')->count());
        $this->assertSame(0, DB::table('site_page_text_events')->count());
    }

    public function test_repeat_seeding_never_changes_owner_edits_or_published_text(): void
    {
        (new WebsitePageContentSeeder())->run();
        $before = SitePageText::count();
        $entry = $this->entry();
        $entry->forceFill([
            'draft_text'=>'Owner review in progress',
            'published_text'=>'Previously approved private-review-safe wording',
            'revision'=>7,
        ])->save();
        (new WebsitePageContentSeeder())->run();
        $this->assertSame($before, SitePageText::count());
        $this->assertSame('Owner review in progress', $entry->fresh()->draft_text);
        $this->assertSame('Previously approved private-review-safe wording', $entry->fresh()->published_text);
        $this->assertSame(7, $entry->fresh()->revision);
    }

    public function test_only_owner_can_read_or_mutate_page_copy(): void
    {
        (new WebsitePageContentSeeder())->run();
        $row = $this->entry();
        $this->get('/admin/page-content')->assertRedirect('/admin/login');
        $this->post(route('admin.page-content.draft', ['entry'=>$row->id]), [
            'draft_text'=>'Guest content','revision'=>0,
        ])->assertRedirect('/admin/login');
        $this->actingAs($this->account('reader'),'web')->get('/admin/page-content')->assertForbidden();
        $this->actingAs($this->account('admin'),'web')->get('/admin/page-content')->assertForbidden();
        $this->post(route('admin.page-content.draft',['entry'=>$row->id]), [
            'draft_text'=>'Staff cannot edit','revision'=>0,
        ])->assertForbidden();
        $this->assertNull($row->fresh()->draft_text);
        $this->assertSame(0, DB::table('site_page_text_events')->count());
    }

    public function test_owner_draft_publish_requires_attestation_updates_public_view_and_writes_hash_only_audit(): void
    {
        (new WebsitePageContentSeeder())->run();
        $owner = $this->account('owner');
        $this->actingAs($owner,'web');
        $row = $this->entry();
        $this->get('/admin/page-content?page_key=home&section_key=hero&lang=en')
            ->assertOk()
            ->assertHeader('Cache-Control','no-store, private')
            ->assertSee('Manage site page content')
            ->assertSee('Save draft')
            ->assertDontSee('Review &amp; publish text', false);
        $this->get('/admin/page-content?page_key=expertise&section_key=ai&lang=ar')
            ->assertOk()->assertSee('lang="ar" dir="rtl"',false)
            ->assertSee('حفظ المسودة');

        $baseline = $this->get('/?lang=en')->assertOk();
        $baseline->assertDontSee('Unique owner approved CMS review line');

        $this->post(route('admin.page-content.draft',['entry'=>$row->id]),[
            'draft_text'=>'Unique owner approved CMS review line',
            'revision'=>0,
        ])->assertRedirect();
        $this->assertSame('Unique owner approved CMS review line',$row->fresh()->draft_text);
        $this->get('/admin/page-content?page_key=home&section_key=hero&lang=en')
            ->assertOk()->assertSee('Review &amp; publish text', false);
        $this->get('/?lang=en')->assertOk()->assertDontSee('Unique owner approved CMS review line');

        $this->post(route('admin.page-content.publish',['entry'=>$row->id]),[
            'revision'=>1,'source_note'=>'Owner verified screenshot copy',
        ])->assertSessionHasErrors();
        $this->assertNotSame('Unique owner approved CMS review line',$row->fresh()->published_text);

        $this->post(route('admin.page-content.publish',['entry'=>$row->id]),[
            'revision'=>1,'source_note'=>'Owner verified screenshot copy',
            'source_reviewed'=>'1',
            'publication_rights_confirmed'=>'1',
        ])->assertRedirect();
        $this->assertSame('Unique owner approved CMS review line',$row->fresh()->published_text);
        $this->assertNull($row->fresh()->draft_text);
        $this->get('/?lang=en')->assertOk()
            ->assertSee('Unique owner approved CMS review line');
        $events = DB::table('site_page_text_events')
            ->where('site_page_text_id',$row->id)->orderBy('revision')->get();
        $this->assertCount(2,$events);
        $this->assertSame('draft_saved',$events[0]->action);
        $this->assertSame('published',$events[1]->action);
        $this->assertSame(hash('sha256','Unique owner approved CMS review line'),$events[1]->new_hash);
        $this->assertNotSame('Unique owner approved CMS review line',$events[1]->new_hash);
        $this->assertSame($owner->id,$events[1]->actor_id);
    }

    public function test_owner_can_withdraw_bilingual_live_copy_without_restoring_static_fallback_or_losing_audit(): void
    {
        (new WebsitePageContentSeeder())->run();
        $owner = $this->account('owner');
        $this->actingAs($owner, 'web');

        foreach (['en', 'ar'] as $locale) {
            $row = $this->entry('home', 'hero', 'intro', $locale);
            $original = $row->published_text;
            $this->assertNotNull($row->published_at);
            $this->assertNotEmpty($original);
            $this->get('/?lang='.$locale)->assertOk()->assertSee($original);

            $panel = $this->get('/admin/page-content?page_key=home&section_key=hero&lang='.$locale)
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $panel->assertSee($locale === 'ar' ? 'سحب النص من الموقع' : 'Withdraw published text');

            $this->post(route('admin.page-content.retract', ['entry'=>$row->id, 'lang'=>$locale]), [
                'revision'=>0,
                'source_note'=>'Owner reviewed publication rights and withdrew this copy',
            ])->assertRedirect();

            $row->refresh();
            // Empty string is a deliberate withdrawal tombstone. A NULL would
            // resurrect hardcoded first-install copy in the frozen public UI.
            $this->assertSame('', $row->published_text);
            $this->assertNull($row->published_at);
            $this->assertNull($row->published_by);
            $this->assertSame(1, $row->revision);
            $this->assertNull($row->draft_text);
            $this->assertSame('', app(\\App\\Domain\\Content\\PageContentRepository::class)
                ->publishedMap('home', $locale)['hero']['intro']);
            $this->get('/?lang='.$locale)->assertOk()->assertDontSee($original);

            $events = DB::table('site_page_text_events')
                ->where('site_page_text_id', $row->id)->get();
            $this->assertCount(1, $events);
            $this->assertSame('retracted', $events[0]->action);
            $this->assertSame(hash('sha256', $original), $events[0]->old_hash);
            $this->assertNull($events[0]->new_hash);
            $this->assertSame($owner->id, $events[0]->actor_id);

            // First-install/reseed may not silently republish withdrawn copy.
            (new WebsitePageContentSeeder())->run();
            $this->assertSame('', $row->fresh()->published_text);
            $this->get('/admin/page-content?page_key=home&section_key=hero&lang='.$locale)
                ->assertOk()->assertSee($locale === 'ar' ? 'مسحوب' : 'Withdrawn');
        }
    }

    public function test_retraction_is_owner_only_revision_guarded_and_can_only_republish_from_reviewed_draft(): void
    {
        (new WebsitePageContentSeeder())->run();
        $row = $this->entry();

        $url = route('admin.page-content.retract', ['entry'=>$row->id]);
        $payload = ['revision'=>0, 'source_note'=>'Owner reviewed the source and revoked consent'];

        $this->post($url, $payload)->assertRedirect('/admin/login');
        $this->actingAs($this->account('admin'), 'web')
            ->post($url, $payload)->assertForbidden();
        $this->actingAs($this->account('reader'), 'web')
            ->post($url, $payload)->assertForbidden();
        $this->assertSame(0, DB::table('site_page_text_events')->count());

        $this->actingAs($this->account('owner'), 'web');
        $this->post($url, ['revision'=>0])->assertSessionHasErrors('source_note');
        $this->post($url, ['revision'=>3, 'source_note'=>$payload['source_note']])
            ->assertStatus(409);
        $this->assertNotNull($row->fresh()->published_at);

        $this->post($url, $payload)->assertRedirect();
        $this->post($url, ['revision'=>1, 'source_note'=>$payload['source_note']])
            ->assertSessionHasErrors('published_text');
        $this->post(route('admin.page-content.publish', ['entry'=>$row->id]), [
            'revision'=>1,
            'source_reviewed'=>'1',
            'publication_rights_confirmed'=>'1',
            'source_note'=>'Rights reviewed and approved by owner',
        ])->assertSessionHasErrors('draft_text');
        $this->assertSame('', $row->fresh()->published_text);

        $this->post(route('admin.page-content.draft', ['entry'=>$row->id]), [
            'revision'=>1, 'draft_text'=>'New text after verified rights review',
        ])->assertRedirect();
        $this->get('/?lang=en')->assertOk()
            ->assertDontSee('New text after verified rights review');
        $this->post(route('admin.page-content.publish', ['entry'=>$row->id]), [
            'revision'=>2,
            'source_reviewed'=>'1',
            'publication_rights_confirmed'=>'1',
            'source_note'=>'Rights reviewed and approved by owner',
        ])->assertRedirect();
        $this->assertSame('New text after verified rights review', $row->fresh()->published_text);
        $this->get('/?lang=en')->assertOk()
            ->assertSee('New text after verified rights review');
        $this->assertSame(
            ['retracted', 'draft_saved', 'published'],
            DB::table('site_page_text_events')->where('site_page_text_id', $row->id)
                ->orderBy('revision')->pluck('action')->all(),
        );
    }

    public function test_stale_revision_html_and_out_of_catalog_fields_fail_closed(): void
    {
        (new WebsitePageContentSeeder())->run();
        $this->actingAs($this->account('owner'),'web');
        $row = $this->entry();
        $this->post(route('admin.page-content.draft',['entry'=>$row->id]),[
            'draft_text'=>'<script>alert(1)</script>','revision'=>0,
        ])->assertSessionHasErrors('draft_text');
        $this->post(route('admin.page-content.draft',['entry'=>$row->id]),[
            'draft_text'=>'A verified editorial change','revision'=>0,
        ])->assertRedirect();
        $this->post(route('admin.page-content.draft',['entry'=>$row->id]),[
            'draft_text'=>'Stale editor must fail','revision'=>0,
        ])->assertStatus(409);
        $this->post(route('admin.page-content.add'),[
            'page_key'=>'fake','section_key'=>'hero','field_key'=>'intro','locale'=>'en',
        ])->assertUnprocessable();
        $this->assertSame('A verified editorial change',$row->fresh()->draft_text);
        $this->assertSame(1,$row->fresh()->revision);
    }

    public function test_owner_can_restore_missing_approved_slot_without_publishing_or_overwriting_live_copy(): void
    {
        (new WebsitePageContentSeeder())->run();
        $this->actingAs($this->account('owner'),'web');
        $row = $this->entry('home','skills','ai','ar');
        $row->delete();
        $this->post(route('admin.page-content.add'),[
            'page_key'=>'home','section_key'=>'skills','field_key'=>'ai','locale'=>'ar',
        ])->assertRedirect();
        $new = $this->entry('home','skills','ai','ar');
        $this->assertNull($new->published_text);
        $this->assertSame('هندسة الذكاء الاصطناعي',$new->draft_text);
        $this->assertSame('admin_restored_slot',$new->source);
    }
}

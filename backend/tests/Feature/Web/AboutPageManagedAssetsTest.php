<?php

namespace Tests\Feature\Web;

use App\Models\AboutMediaAsset;
use App\Models\Role;
use App\Models\SitePageText;
use App\Models\User;
use Database\Seeders\WebsitePageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AboutPageManagedAssetsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::create([
            'email' => strtolower($role).'-about-assets@example.test',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name'=>$role])->id);
        return $user;
    }

    public function test_about_copy_media_slots_and_real_owner_details_are_seeded_and_idempotent(): void
    {
        (new WebsitePageContentSeeder())->run();

        $this->assertSame(27, AboutMediaAsset::query()->count());
        $row = SitePageText::query()->where('page_key','about')
            ->where('section_key','intro')->where('field_key','phone_egypt')
            ->where('locale','en')->firstOrFail();
        $this->assertSame('+201099459704', $row->published_text);
        $this->assertNull($row->draft_text);
        $this->assertSame('owner_approved_2026', $row->source);
        $this->assertSame('lido825@gmail.com',
            SitePageText::query()->where('page_key','about')->where('section_key','intro')
                ->where('field_key','email')->where('locale','en')->value('published_text'));

        $row->forceFill(['published_text'=>'+201000000000','revision'=>3])->save();
        (new WebsitePageContentSeeder())->run();
        $this->assertSame(27, AboutMediaAsset::query()->count());
        $this->assertSame('+201000000000', $row->fresh()->published_text);
        $this->assertSame(3, $row->fresh()->revision);
        $this->assertSame(0, DB::table('about_media_events')->count());
    }

    public function test_about_design_reads_actual_published_copy_in_both_languages(): void
    {
        (new WebsitePageContentSeeder())->run();
        $this->get('/about?lang=en')->assertOk()
            ->assertSee('Career Story', false) // The English UI remains English-first.
            ->assertSee('lido825@gmail.com')
            ->assertSee('+96566010746')
            ->assertSee('+201099459704')
            ->assertSee('Technologies &amp; Tools', false)
            ->assertSee('Cairo University')
            ->assertSee('src="'.route('public.about-media',['slot'=>'portrait']).'"',false)
            ->assertDontSee('t007-visual-stack')
            ->assertDontSee('20+ Years');

        $this->get('/about?lang=ar')->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee('المسيرة المهنية')
            ->assertSee('+201099459704')
            ->assertSee('جامعة القاهرة');
    }

    public function test_owner_can_upload_review_publish_and_retract_real_logo_without_leaking_draft(): void
    {
        Storage::fake('local');
        (new WebsitePageContentSeeder())->run();
        $slot = 'tech_laravel';
        $this->get(route('public.about-media',['slot'=>$slot]))
            ->assertOk()->assertHeader('Content-Type','image/svg+xml');

        $this->post(route('admin.about-media.draft',['slot'=>$slot]),[
            'revision'=>0,
        ])->assertRedirect('/admin/login');
        $this->actingAs($this->user('admin'),'web')
            ->post(route('admin.about-media.draft',['slot'=>$slot]),['revision'=>0])
            ->assertForbidden();
        $this->actingAs($this->user('owner'),'web');
        // Real 1x1 PNG signature and image payload, independent of GD.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZ1cAAAAASUVORK5CYII=',
            true
        );
        $this->post(route('admin.about-media.draft',['slot'=>$slot]),[
            'revision'=>0,
            'image'=>UploadedFile::fake()->createWithContent('approved-logo.png',$png),
        ])->assertRedirect();

        $asset = AboutMediaAsset::query()->findOrFail($slot);
        $this->assertSame(1, $asset->revision);
        $this->assertNull($asset->published_path);
        $this->assertNotNull($asset->draft_path);
        $this->get(route('public.about-media',['slot'=>$slot]))
            ->assertOk()->assertHeader('Content-Type','image/svg+xml');
        $this->get(route('admin.about-media.preview',['slot'=>$slot]))
            ->assertOk()->assertHeader('Cache-Control','no-store, private');

        $this->post(route('admin.about-media.publish',['slot'=>$slot]),[
            'revision'=>1,'source_note'=>'Owner supplied genuine logo',
        ])->assertSessionHasErrors();
        $this->post(route('admin.about-media.publish',['slot'=>$slot]),[
            'revision'=>1,'source_note'=>'Owner supplied genuine logo',
            'source_reviewed'=>'1','publication_rights_confirmed'=>'1',
        ])->assertRedirect();
        $this->get(route('public.about-media',['slot'=>$slot]))
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->post(route('admin.about-media.publish',['slot'=>$slot]),[
            'revision'=>1,'source_note'=>'Owner supplied genuine logo',
            'source_reviewed'=>'1','publication_rights_confirmed'=>'1',
        ])->assertStatus(409);
        $this->post(route('admin.about-media.retract',['slot'=>$slot]),[
            'revision'=>2,
        ])->assertRedirect();
        $this->get(route('public.about-media',['slot'=>$slot]))
            ->assertOk()->assertHeader('Content-Type','image/svg+xml');
        $this->assertSame(3, DB::table('about_media_events')->where('slot',$slot)->count());
    }

    public function test_media_upload_rejects_arbitrary_slots_non_images_and_unapproved_users(): void
    {
        (new WebsitePageContentSeeder())->run();
        $this->actingAs($this->user('owner'),'web');
        $this->post(route('admin.about-media.draft',['slot'=>'unknown_technology']),[
            'revision'=>0, 'image'=>UploadedFile::fake()->create('malware.php',3),
        ])->assertNotFound();
        $this->post(route('admin.about-media.draft',['slot'=>'tech_sql']),[
            'revision'=>0, 'image'=>UploadedFile::fake()->create('unsafe.svg',1,'image/svg+xml'),
        ])->assertSessionHasErrors('image');
        $this->assertSame(0, DB::table('about_media_events')->count());
    }
}

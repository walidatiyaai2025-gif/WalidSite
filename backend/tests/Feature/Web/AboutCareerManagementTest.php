<?php

namespace Tests\Feature\Web;

use App\Models\CareerPosition;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AboutCareerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $role = 'owner', string $email = 'about-career-owner@example.test'): User
    {
        $user = User::create(['email'=>$email,'status'=>'active']);
        $user->roles()->attach(Role::firstOrCreate(['name'=>$role])->id);
        Profile::create([
            'user_id'=>$user->id, 'public_name'=>'Real owner test',
            'bio_en'=>'Owner-reviewed CV summary', 'publish_status'=>'published',
        ]);
        return $user;
    }

    private function career(User $user, bool $public = true): CareerPosition
    {
        return CareerPosition::create([
            'profile_id'=>$user->profile->id,
            'org_name'=>'Reviewed Organization',
            'role_en'=>'Database Administrator',
            'role_ar'=>'مسؤول قواعد البيانات',
            'started_on'=>'2022-01-01',
            'ended_on'=>'2024-12-31',
            'verified'=>$public,'publishable'=>$public,
        ]);
    }

    private function input(int $revision = 0): array
    {
        return [
            'revision'=>$revision,
            'org_name'=>'Reviewed Organization',
            'role_en'=>'Technical Project Manager',
            'role_ar'=>'مدير مشاريع تقنية',
            'start_year'=>2022,
            'end_year'=>2024,
        ];
    }

    public function test_only_active_owner_can_manage_own_career_rows(): void
    {
        $owner = $this->owner();
        $career = $this->career($owner);
        $other = $this->owner('admin','about-career-admin@example.test');

        $this->post(route('admin.about-career.draft',['position'=>$career->id]),$this->input())
            ->assertRedirect('/admin/login');
        $this->actingAs($other,'web')->post(
            route('admin.about-career.draft',['position'=>$career->id]),$this->input()
        )->assertForbidden();
        $anotherOwner = $this->owner('owner','about-career-second@example.test');
        $this->actingAs($anotherOwner,'web')->post(
            route('admin.about-career.draft',['position'=>$career->id]),$this->input()
        )->assertNotFound();
        $this->assertSame('Database Administrator',$career->fresh()->role_en);
        $this->assertSame(0,DB::table('career_position_edit_events')->count());

        $this->actingAs($owner,'web')->get('/admin/page-content?page_key=about')
            ->assertOk()->assertSee('Career timeline (draft');
    }

    public function test_draft_does_not_change_live_timeline_until_owner_reviews_and_publishes(): void
    {
        $owner = $this->owner();
        $career = $this->career($owner);
        $this->actingAs($owner,'web');
        $this->get('/about?lang=en')->assertOk()
            ->assertSee('Database Administrator')->assertDontSee('Technical Project Manager — Reviewed Organization');

        $this->post(route('admin.about-career.draft',['position'=>$career->id]),$this->input())
            ->assertRedirect();
        $this->assertSame('Database Administrator',$career->fresh()->role_en);
        $this->assertSame('Technical Project Manager',$career->fresh()->draft_json['role_en']);
        $this->get('/about?lang=en')->assertOk()->assertSee('Database Administrator')
            ->assertDontSee('Technical Project Manager — Reviewed Organization');
        $this->post(route('admin.about-career.draft',['position'=>$career->id]),$this->input())
            ->assertStatus(409);

        $publish = [
            'revision'=>1,'source_note'=>'Owner-supplied employment record',
            'source_reviewed'=>'1','publication_rights_confirmed'=>'1',
        ];
        $this->post(route('admin.about-career.publish',['position'=>$career->id]),[
            'revision'=>1,'source_note'=>'Owner-supplied employment record',
        ])->assertSessionHasErrors();
        $this->post(route('admin.about-career.publish',['position'=>$career->id]),$publish)
            ->assertRedirect();
        $this->assertNull($career->fresh()->draft_json);
        $this->assertSame('Technical Project Manager',$career->fresh()->role_en);
        $this->get('/about?lang=en')->assertOk()
            ->assertSee('Technical Project Manager — Reviewed Organization');
        $this->post(route('admin.about-career.publish',['position'=>$career->id]),$publish)
            ->assertStatus(409);
        $this->post(route('admin.about-career.retract',['position'=>$career->id]),[
            'revision'=>2,
        ])->assertRedirect();
        $this->get('/about?lang=en')->assertOk()
            ->assertDontSee('Technical Project Manager — Reviewed Organization');
        $this->assertSame(3,DB::table('career_position_edit_events')->count());
    }

    public function test_new_period_stays_private_invalid_dates_fail_and_review_publishes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner,'web');
        $fields = [
            'org_name'=>'New Reviewed Organization',
            'role_en'=>'Software Engineer',
            'role_ar'=>'مهندس برمجيات',
            'start_year'=>2008,'end_year'=>2009,
        ];
        $this->post(route('admin.about-career.create'),[
            ...$fields,'end_year'=>2000,
        ])->assertSessionHasErrors('end_year');
        $this->post(route('admin.about-career.create'),$fields)->assertRedirect();
        $record = CareerPosition::query()->where('org_name','New Reviewed Organization')->firstOrFail();
        $this->assertFalse($record->verified);
        $this->assertFalse($record->publishable);
        $this->get('/about?lang=en')->assertOk()->assertDontSee('New Reviewed Organization');
        $this->post(route('admin.about-career.publish',['position'=>$record->id]),[
            'revision'=>1,'source_note'=>'Owner verified original CV entry',
            'source_reviewed'=>'1','publication_rights_confirmed'=>'1',
        ])->assertRedirect();
        $this->get('/about?lang=en')->assertOk()->assertSee('New Reviewed Organization');
        $this->post(route('admin.about-career.create'),$fields)->assertSessionHasErrors('org_name');
        $this->assertSame(2,DB::table('career_position_edit_events')->where('career_position_id',$record->id)->count());
    }
}

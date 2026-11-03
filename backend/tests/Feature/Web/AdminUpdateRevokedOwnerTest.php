<?php

namespace Tests\Feature\Web;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminUpdateRevokedOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'owner'): User
    {
        $user = User::create([
            'email' => 'updates-'.$role.'-'.bin2hex(random_bytes(5)).'@example.test',
            'password_hash' => 'not-a-reusable-login-password',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);

        return $user;
    }

    public function test_active_owner_can_read_current_migrations_and_request_existing_trusted_apply(): void
    {
        config(['site.updates_enabled' => true]);
        $owner = $this->account();
        $this->actingAs($owner, 'web');

        $this->get('/admin/updates?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الترحيلات المنتظرة');
        $this->post('/admin/updates/apply?lang=en')->assertRedirect(
            route('admin.updates.index', ['lang' => 'en'])
        );
    }

    public function test_suspended_deleted_and_pending_owner_sessions_cannot_inspect_or_apply(): void
    {
        config(['site.updates_enabled' => true]);
        // This test isolates the privilege boundary from the independent
        // production throttle (2/min) without changing real route middleware.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['suspended', 'deleted', 'pending_activation'] as $status) {
            $owner = $this->account();
            $this->actingAs($owner, 'web');
            $this->get('/admin/updates?lang=en')->assertOk();

            // Update storage directly so the already-authenticated session
            // retains a stale in-memory ACTIVE owner object.
            DB::table('users')->where('id', $owner->id)->update(['status' => $status]);
            $this->assertSame('active', $owner->status);
            $this->assertSame($status, $owner->fresh()?->status);
            $this->assertTrue($owner->hasRole('owner'));

            $this->get('/admin/updates?lang=ar')->assertForbidden();
            $this->post('/admin/updates/apply?lang=ar')->assertForbidden();
            $this->assertSame($status, $owner->fresh()?->status);
        }
    }

    public function test_revoked_owner_role_is_checked_again_against_database(): void
    {
        config(['site.updates_enabled' => true]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = $this->account();
        $this->actingAs($owner, 'web');
        $this->get('/admin/updates')->assertOk();

        DB::table('role_user')->where('user_id', $owner->id)->delete();
        $this->assertFalse($owner->fresh()?->hasRole('owner'));

        $this->get('/admin/updates')->assertForbidden();
        $this->post('/admin/updates/apply')->assertForbidden();
    }

    public function test_guest_and_non_owner_staff_cannot_use_update_endpoints(): void
    {
        config(['site.updates_enabled' => true]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->get('/admin/updates')->assertRedirect('/admin/login');
        $this->post('/admin/updates/apply')->assertRedirect('/admin/login');

        foreach (['admin', 'reader'] as $role) {
            $this->actingAs($this->account($role), 'web');
            $this->get('/admin/updates?lang=en')->assertForbidden();
            $this->post('/admin/updates/apply?lang=en')->assertForbidden();
        }
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sanctum's first-party web guard attaches a TransientToken with all abilities.
 * Mobile-only APIs must reject it and require a persisted scoped bearer.
 */
final class MobileBearerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email = 'mobile-boundary@example.test'): User
    {
        return User::create(['email' => $email, 'status' => 'active']);
    }

    public function test_authenticated_owner_web_session_cannot_use_any_private_mobile_apis(): void
    {
        $owner = $this->account();
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);
        $this->actingAs($owner, 'web');

        $resourceId = (string) Str::uuid();
        $this->getJson('/api/v1/mobile/me')->assertForbidden();
        $this->getJson('/api/v1/mobile/saved')->assertForbidden();
        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article',
            'resource_id' => $resourceId,
        ])->assertForbidden();
        $this->deleteJson('/api/v1/mobile/saved/article/'.$resourceId)
            ->assertForbidden();

        // Both the create and answer operations must reject the browser
        // identity before validation or any private table read/write.
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start',
            'path_id' => (string) Str::uuid(),
            'lesson_index' => 0,
        ])->assertForbidden();
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'answer',
            'session_id' => (string) Str::uuid(),
            'answer' => 'Private answer for a mobile-only session.',
        ])->assertForbidden();

        $this->assertDatabaseCount('saved_resources', 0);
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_valid_mobile_bearer_still_reads_account_bookmarks_and_starts_practice(): void
    {
        $user = $this->account('bearer-reader@example.test');
        $pathId = (string) Str::uuid();
        DB::table('learning_paths')->insert([
            'id' => $pathId,
            'slug' => 'mobile-bearer-sample',
            'title' => 'Reviewed learning track',
            'published' => true,
            'outline_json' => json_encode([[
                'title' => 'Technical rehearsal',
                'description' => 'Verify a read-only restoration procedure.',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $token = $user->createToken('private-phone', ['mobile'], now()->addHour());
        $this->withToken($token->plainTextToken);

        $this->getJson('/api/v1/mobile/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
        $this->getJson('/api/v1/mobile/saved')->assertOk()
            ->assertJsonCount(0, 'data');
        $started = $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start', 'path_id' => $pathId, 'lesson_index' => 0,
            'lang' => 'en',
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_answer');
        $sessionId = $started->json('data.session_id');

        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'answer', 'session_id' => $sessionId,
            'answer' => 'I would verify the restore and checksum before rollout.',
        ])->assertOk()->assertJsonPath('data.status', 'answered');

        $this->assertDatabaseHas('mobile_interview_sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
            'answer_text' => 'I would verify the restore and checksum before rollout.',
        ]);
        $this->assertSame(['mobile'], $token->accessToken->abilities);
    }

    public function test_admin_only_bearer_cannot_read_profile_or_modify_mobile_private_records(): void
    {
        $user = $this->account('admin-only@example.test');
        $this->withToken($user->createToken('admin-only', ['admin'])->plainTextToken);

        $this->getJson('/api/v1/mobile/me')->assertForbidden();
        $this->getJson('/api/v1/mobile/saved')->assertForbidden();
        $this->postJson('/api/v1/mobile/saved', [
            'resource_type' => 'article',
            'resource_id' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start',
            'path_id' => (string) Str::uuid(),
            'lesson_index' => 0,
        ])->assertForbidden();
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
        $this->assertDatabaseCount('saved_resources', 0);
    }

    public function test_unauthenticated_and_inactive_users_still_fail_closed(): void
    {
        $this->getJson('/api/v1/mobile/me')->assertUnauthorized();
        $this->getJson('/api/v1/mobile/saved')->assertUnauthorized();
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start',
            'path_id' => (string) Str::uuid(),
            'lesson_index' => 0,
        ])->assertUnauthorized();

        $inactive = $this->account('inactive-mobile@example.test');
        $bearer = $inactive->createToken('disabled-phone', ['mobile'], now()->addHour());
        $inactive->update(['status' => 'inactive']);
        $this->withToken($bearer->plainTextToken);
        $this->getJson('/api/v1/mobile/me')->assertForbidden();
        $this->getJson('/api/v1/mobile/saved')->assertForbidden();
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start',
            'path_id' => (string) Str::uuid(),
            'lesson_index' => 0,
        ])->assertForbidden();
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountPrivateInterviewExportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $status = 'active'): User
    {
        return User::create([
            'email' => $name.'@example.test',
            'status' => $status,
        ]);
    }

    private function token(User $user, array $abilities = ['mobile']): string
    {
        return $user->createToken('private-archive-test', $abilities, now()->addHour())
            ->plainTextToken;
    }

    private function path(): string
    {
        $id = (string) Str::uuid();
        DB::table('learning_paths')->insert([
            'id' => $id,
            'slug' => 'private-practice-export',
            'title' => 'Previously approved course',
            'published' => false,
            'outline_json' => json_encode([[
                'title' => 'Previously published lesson',
                'description' => 'Originally reviewed source',
            ]], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    private function insertInterviewSession(User $user, string $path, ?string $answer, int $offset): string
    {
        $id = (string) Str::uuid();
        DB::table('mobile_interview_sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'path_id' => $path,
            'lesson_index' => 0,
            'locale' => $offset === 0 ? 'ar' : 'en',
            'lesson_title' => 'REVOKED_LESSON_TITLE_NEVER_EXPORT',
            'lesson_context' => 'REVOKED_SOURCE_CONTEXT_NEVER_EXPORT',
            'answer_text' => $answer,
            'answered_at' => $answer === null ? null : now()->subMinutes(5),
            'created_at' => now()->subDays($offset + 1),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_authenticated_archive_includes_only_own_answer_and_pending_session_without_revoked_source(): void
    {
        $owner = $this->user('self-export');
        $other = $this->user('foreign-export');
        $path = $this->path();
        $first = $this->insertInterviewSession($owner, $path, 'PRIVATE_USER_AUTHORED_ANSWER', 0);
        $pending = $this->insertInterviewSession($owner, $path, null, 1);
        $this->insertInterviewSession($other, $path, 'FOREIGN_PRIVATE_ANSWER', 0);

        DB::table('saved_resources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'resource_type' => 'runbook',
            'resource_id' => (string) Str::uuid(),
            'saved_at' => now(),
        ]);

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/v1/mobile/account/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="walidsite-account-export.json"')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonCount(2, 'data.private_interviews')
            ->assertJsonCount(1, 'data.saved_resources')
            ->assertJsonPath('data.profile.email', $owner->email)
            ->assertJsonPath('meta.private_interview_answers_included', true)
            ->assertJsonPath('meta.private_ai_prompts_excluded', true)
            ->assertJsonPath('meta.secrets_excluded', true);

        $entries = collect($response->json('data.private_interviews'))->keyBy('session_id');
        $this->assertSame([$pending, $first], $entries->keys()->all());
        $this->assertSame('PRIVATE_USER_AUTHORED_ANSWER', $entries[$first]['answer_text']);
        $this->assertSame('ar', $entries[$first]['locale']);
        $this->assertSame(0, $entries[$first]['lesson_index']);
        $this->assertSame($path, $entries[$first]['path_id']);
        $this->assertNotNull($entries[$first]['answered_at']);
        $this->assertNull($entries[$pending]['answer_text']);
        $this->assertNull($entries[$pending]['answered_at']);
        $this->assertNotEmpty($entries[$first]['created_at']);

        $body = $response->getContent();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach ([
            'FOREIGN_PRIVATE_ANSWER', $other->email,
            'REVOKED_LESSON_TITLE_NEVER_EXPORT', 'REVOKED_SOURCE_CONTEXT_NEVER_EXPORT',
            'password_hash', 'secret_ciphertext', 'tokenable_id',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_export_rejects_foreign_selector_admin_tokens_inactive_users_and_guests(): void
    {
        $this->getJson('/api/v1/mobile/account/export')->assertUnauthorized();

        $other = $this->user('another-account');
        $owner = $this->user('only-owner');
        $path = $this->path();
        $this->insertInterviewSession($other, $path, 'NEVER_EXPORTED_OTHER_ACCOUNT', 0);

        $this->withToken($this->token($owner, ['admin']))
            ->getJson('/api/v1/mobile/account/export')->assertForbidden();
        Auth::forgetGuards();

        $this->withToken($this->token($owner))
            ->getJson('/api/v1/mobile/account/export?user_id='.$other->id)
            ->assertUnprocessable();
        $this->getJson('/api/v1/mobile/account/export')
            ->assertOk()
            ->assertJsonCount(0, 'data.private_interviews')
            ->assertDontSee('NEVER_EXPORTED_OTHER_ACCOUNT');

        $owner->forceFill(['status' => 'deleted'])->save();
        Auth::forgetGuards();
        $this->withToken($this->token($owner))
            ->getJson('/api/v1/mobile/account/export')->assertForbidden();
    }

    public function test_over_limit_private_interview_archive_fails_instead_of_truncating(): void
    {
        $owner = $this->user('bounded');
        $path = $this->path();
        // Exactly one beyond EXPORT_INTERVIEW_LIMIT. Insert in small batches
        // to use real MariaDB transactions and avoid query parameter limits.
        for ($batch = 0; $batch < 11; $batch++) {
            $rows = [];
            for ($i = 0; $i < ($batch === 10 ? 1 : 100); $i++) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $owner->id,
                    'path_id' => $path,
                    'lesson_index' => 0,
                    'locale' => 'en',
                    'lesson_title' => 'Lesson',
                    'lesson_context' => 'Source context',
                    'answer_text' => 'Private answer',
                    'answered_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('mobile_interview_sessions')->insert($rows);
        }
        $this->assertSame(
            1001,
            DB::table('mobile_interview_sessions')->where('user_id', $owner->id)->count()
        );

        $response = $this->withToken($this->token($owner))
            ->getJson('/api/v1/mobile/account/export')
            ->assertStatus(413)
            ->assertJsonMissingPath('data')
            ->assertDontSee('Private answer')
            ->assertDontSee($owner->email);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('mobile_interview_sessions', 1001);
    }

    public function test_account_deletion_revokes_export_and_erases_private_answers(): void
    {
        $owner = $this->user('archive-delete');
        $owner->forceFill([
            'password_hash' => \Illuminate\Support\Facades\Hash::make('PrivacyArchive.123!'),
        ])->save();
        $token = $this->token($owner);
        $path = $this->path();
        $this->insertInterviewSession($owner, $path, 'PRIVATE_ERASE_WITH_ACCOUNT', 0);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export')
            ->assertOk()
            ->assertSee('PRIVATE_ERASE_WITH_ACCOUNT');

        $this->deleteJson('/api/v1/mobile/account', [
            'password' => 'PrivacyArchive.123!',
            'confirmation' => 'DELETE',
        ])->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('mobile_interview_sessions', ['user_id' => $owner->id]);
        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/mobile/account/export')->assertUnauthorized();
    }
}

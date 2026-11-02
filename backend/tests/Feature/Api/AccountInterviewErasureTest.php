<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountInterviewErasureTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'PrivatePracticeErase.123!';
    private const SECRET = 'PRIVATE_TECHNICAL_ANSWER_DELETE_ME';

    private function account(string $label): User
    {
        return User::create([
            'email' => $label.'-'.Str::lower(Str::random(8)).'@example.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('interview-privacy', ['mobile'], now()->addHour())
            ->plainTextToken;
    }

    private function learningPath(): string
    {
        $id = (string) Str::uuid();
        DB::table('learning_paths')->insert([
            'id' => $id,
            'slug' => 'privacy-source',
            'title' => 'Verified restore practice',
            'published' => true,
            'outline_json' => json_encode([[
                'title' => 'Rehearse recovery',
                'description' => 'Validate a database restore.',
            ]], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    private function interviewRow(User $user, string $path, ?string $answer): string
    {
        $id = (string) Str::uuid();
        DB::table('mobile_interview_sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'path_id' => $path,
            'lesson_index' => 0,
            'locale' => 'ar',
            'lesson_title' => 'Rehearse recovery',
            'lesson_context' => 'Validate a database restore.',
            'answer_text' => $answer,
            'answered_at' => $answer === null ? null : now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        return $id;
    }

    public function test_account_delete_erases_private_answered_and_pending_interviews_but_not_foreign_sessions(): void
    {
        $user = $this->account('owner');
        $other = $this->account('foreign');
        $originalEmail = $user->email;
        $token = $this->token($user);
        $path = $this->learningPath();
        $answered = $this->interviewRow($user, $path, self::SECRET);
        $pending = $this->interviewRow($user, $path, null);
        $foreign = $this->interviewRow($other, $path, 'FOREIGN_ACCOUNT_ANSWER');

        // Failed password verification must roll back all deletions.
        $this->withToken($token)->deleteJson('/api/v1/mobile/account', [
            'password' => 'wrong-password',
            'confirmation' => 'DELETE',
        ])->assertUnprocessable();
        foreach ([$answered, $pending, $foreign] as $id) {
            $this->assertDatabaseHas('mobile_interview_sessions', ['id' => $id]);
        }
        $this->assertDatabaseCount('account_deletion_events', 0);

        $response = $this->withToken($token)
            ->deleteJson('/api/v1/mobile/account', [
                'password' => self::PASSWORD,
                'confirmation' => 'DELETE',
            ]);
        $response->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.tokens_revoked', true)
            ->assertJsonPath('meta.pii_erased_or_anonymized', true)
            ->assertDontSee(self::SECRET)
            ->assertDontSee($originalEmail);

        $this->assertDatabaseMissing('mobile_interview_sessions', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('mobile_interview_sessions', [
            'id' => $foreign,
            'user_id' => $other->id,
            'answer_text' => 'FOREIGN_ACCOUNT_ANSWER',
        ]);
        $this->assertSame('deleted', $user->fresh()->status);
        $this->assertDatabaseCount('account_deletion_events', 1);
        $audit = json_encode(DB::table('account_deletion_events')->first(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::SECRET, $audit);
        $this->assertStringNotContainsString($originalEmail, $audit);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/mobile/interview', [
            'action' => 'answer',
            'session_id' => $answered,
            'answer' => 'Attempt to resurrect the deleted answer.',
        ])->assertUnauthorized();
        $this->assertDatabaseMissing('mobile_interview_sessions', [
            'user_id' => $user->id,
        ]);
    }

    public function test_interview_start_rechecks_locked_account_after_authentication_before_persisting(): void
    {
        $user = $this->account('race');
        $token = $this->token($user);
        $path = $this->learningPath();

        // Simulate deletion/suspension completing *after* the request has
        // authenticated but *before* its previously unprotected INSERT.
        // The verified-learning-path read is the gap between those phases.
        $flipped = false;
        DB::listen(function (QueryExecuted $query) use ($user, &$flipped): void {
            if ($flipped
                || ! str_contains(strtolower($query->sql), 'learning_paths')
                || ! str_contains(strtolower($query->sql), 'select')) {
                return;
            }
            $flipped = true;
            DB::table('users')->where('id', $user->id)->update(['status' => 'deleted']);
        });

        $this->withToken($token)->postJson('/api/v1/mobile/interview', [
            'action' => 'start',
            'path_id' => $path,
            'lesson_index' => 0,
            'lang' => 'en',
        ])->assertForbidden();

        $this->assertTrue($flipped, 'Test must exercise an actual after-auth status change.');
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
        $this->assertSame('deleted', $user->fresh()->status);
    }

    public function test_interview_answer_rejects_deleted_account_without_changing_existing_answer(): void
    {
        $user = $this->account('revoked');
        $token = $this->token($user);
        $path = $this->learningPath();
        $id = $this->interviewRow($user, $path, null);

        DB::table('users')->where('id', $user->id)->update(['status' => 'deleted']);
        Auth::forgetGuards();

        $this->withToken($token)->postJson('/api/v1/mobile/interview', [
            'action' => 'answer',
            'session_id' => $id,
            'answer' => self::SECRET,
        ])->assertForbidden();
        $this->assertDatabaseHas('mobile_interview_sessions', [
            'id' => $id,
            'answer_text' => null,
            'answered_at' => null,
        ]);
    }
}

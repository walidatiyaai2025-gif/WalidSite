<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MobileInterviewTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name, string $status = 'active'): User
    {
        return User::create(['email' => $name.'@example.test', 'status' => $status]);
    }

    private function token(User $user, array $abilities = ['mobile']): string
    {
        return $user->createToken('interview-unit', $abilities, now()->addHour())
            ->plainTextToken;
    }

    private function path(string $slug, bool $published = true, ?array $outline = null): string
    {
        $id = (string) Str::uuid();
        DB::table('learning_paths')->insert([
            'id' => $id,
            'slug' => $slug,
            'title' => 'Source based '.$slug,
            'published' => $published,
            'outline_json' => json_encode($outline ?? [[
                'title' => 'Backup recovery',
                'description' => 'Read-only restore rehearsal and verification',
            ]], JSON_THROW_ON_ERROR),
        ]);
        return $id;
    }

    private function start(string $path, string $lang = 'en'): array
    {
        return $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start', 'path_id' => $path,
            'lesson_index' => 0, 'lang' => $lang,
        ])->assertOk()->assertJsonPath('data.progress.answered', 0)
            ->assertJsonPath('data.progress.total', 1)
            ->assertJsonPath('data.status', 'awaiting_answer')
            ->assertJsonPath('data.feedback', null)
            ->json('data');
    }

    public function test_real_bilingual_practice_persists_private_answer_without_fake_ai_feedback(): void
    {
        Http::fake();
        $owner = $this->account('learner');
        $path = $this->path('backup-practice');
        $this->withToken($this->token($owner));

        foreach (['en', 'ar'] as $locale) {
            $started = $this->start($path, $locale);
            $this->assertStringContainsString('Backup recovery', $started['question']);
            $this->assertSame('Read-only restore rehearsal and verification', $started['context']);
            $response = $this->postJson('/api/v1/mobile/interview', [
                'action' => 'answer',
                'session_id' => $started['session_id'],
                'answer' => 'I verify the database restore and its checksums.',
            ])->assertOk()->assertJsonPath('data.status', 'answered')
                ->assertJsonPath('data.progress.answered', 1)
                ->assertJsonPath('data.progress.total', 1)
                ->assertJsonPath('data.feedback.type', 'guided_self_review')
                ->assertJsonPath('data.feedback.review_source',
                    'Read-only restore rehearsal and verification')
                ->assertJsonMissingPath('data.score')
                ->assertJsonMissingPath('data.ai_result')
                ->assertDontSee('I verify the database restore');

            $this->assertStringContainsString(
                $locale === 'ar' ? 'لم تُقيّم' : 'No AI evaluation',
                $response->json('data.feedback.message')
            );
            $this->assertDatabaseHas('mobile_interview_sessions', [
                'id' => $started['session_id'],
                'user_id' => $owner->id,
                'path_id' => $path,
                'answer_text' => 'I verify the database restore and its checksums.',
            ]);
            $this->postJson('/api/v1/mobile/interview', [
                'action' => 'answer', 'session_id' => $started['session_id'],
                'answer' => 'A repeat must not replace the first answer.',
            ])->assertStatus(409);
        }
        $this->assertDatabaseCount('mobile_interview_sessions', 2);
        Http::assertNothingSent();
    }

    public function test_guest_admin_only_inactive_and_other_users_cannot_interview_or_read_answers(): void
    {
        $path = $this->path('permission-checked');
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start', 'path_id' => $path, 'lesson_index' => 0,
        ])->assertUnauthorized();

        $admin = $this->account('admin-token');
        $this->withToken($this->token($admin, ['admin']))->postJson(
            '/api/v1/mobile/interview',
            ['action' => 'start', 'path_id' => $path, 'lesson_index' => 0]
        )->assertForbidden();
        Auth::forgetGuards();

        $inactive = $this->account('inactive-learner', 'inactive');
        $this->withToken($this->token($inactive))->postJson('/api/v1/mobile/interview', [
            'action' => 'start', 'path_id' => $path, 'lesson_index' => 0,
        ])->assertForbidden();
        Auth::forgetGuards();

        $owner = $this->account('owner-learner');
        $this->withToken($this->token($owner));
        $session = $this->start($path);
        Auth::forgetGuards();

        $other = $this->account('another-learner');
        $this->withToken($this->token($other))
            ->postJson('/api/v1/mobile/interview', [
                'action' => 'answer',
                'session_id' => $session['session_id'],
                'answer' => 'Cannot answer another learner session.',
            ])->assertNotFound()->assertDontSee($owner->id);
        $this->assertDatabaseHas('mobile_interview_sessions', [
            'id' => $session['session_id'], 'answer_text' => null,
        ]);
    }

    public function test_private_unpublished_or_malformed_source_never_starts_an_interview(): void
    {
        $this->withToken($this->token($this->account('verified-source')));
        $private = $this->path('unpublished', false);
        $bad = $this->path('invalid-metadata', true, [[
            'title' => 'Unsafe', 'private_token' => 'DO_NOT_EXPOSE',
        ]]);
        foreach ([$private, $bad, (string) Str::uuid()] as $path) {
            $this->postJson('/api/v1/mobile/interview', [
                'action' => 'start', 'path_id' => $path, 'lesson_index' => 0,
            ])->assertNotFound()->assertDontSee('DO_NOT_EXPOSE');
        }
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'start', 'path_id' => $private, 'lesson_index' => 40,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
    }

    public function test_withdrawn_changed_or_expired_source_blocks_feedback_and_retains_privacy(): void
    {
        $this->withToken($this->token($this->account('source-revoke')));
        $path = $this->path('withdraw-before-answer');
        $session = $this->start($path);
        DB::table('learning_paths')->where('id', $path)->update(['published' => false]);
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'answer', 'session_id' => $session['session_id'],
            'answer' => 'No longer approved.',
        ])->assertStatus(409)->assertDontSee('Read-only restore rehearsal');

        $newPath = $this->path('source-changes');
        $changed = $this->start($newPath);
        DB::table('learning_paths')->where('id', $newPath)->update([
            'outline_json' => json_encode([
                ['title' => 'Changed topic', 'description' => 'Revised draft'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'answer', 'session_id' => $changed['session_id'],
            'answer' => 'Former lesson content.',
        ])->assertStatus(409);

        $expiredPath = $this->path('expired-session');
        $expired = $this->start($expiredPath);
        DB::table('mobile_interview_sessions')->where('id', $expired['session_id'])
            ->update(['created_at' => now()->subDays(2)]);
        $this->postJson('/api/v1/mobile/interview', [
            'action' => 'answer', 'session_id' => $expired['session_id'],
            'answer' => 'Too late.',
        ])->assertStatus(409);
        $this->assertDatabaseCount('mobile_interview_sessions', 3);
        $this->assertSame(0, DB::table('mobile_interview_sessions')
            ->whereNotNull('answer_text')->count());
    }

    public function test_validation_and_plaintext_protection_do_not_accept_untrusted_payloads(): void
    {
        $path = $this->path('safe-path');
        $this->withToken($this->token($this->account('validation')));
        foreach ([
            ['action' => 'start', 'path_id' => ['id'], 'lesson_index' => 0],
            ['action' => 'start', 'path_id' => $path, 'lesson_index' => -1],
            ['action' => 'start', 'path_id' => $path, 'lesson_index' => 0, 'lang' => 'fr'],
            ['action' => 'answer', 'session_id' => 'not-uuid', 'answer' => 'hello'],
            ['action' => 'answer', 'session_id' => (string) Str::uuid(), 'answer' => ['array']],
            ['action' => 'wrong', 'path_id' => $path, 'lesson_index' => 0],
        ] as $bad) {
            $this->postJson('/api/v1/mobile/interview', $bad)->assertUnprocessable();
        }
        $this->assertDatabaseCount('mobile_interview_sessions', 0);

        $previous = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->postJson('http://localhost/api/v1/mobile/interview', [
                'action' => 'start', 'path_id' => $path, 'lesson_index' => 0,
            ])->assertForbidden();
        } finally {
            $this->app->instance('env', $previous);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * MOBILE-09: a private, self-guided technical practice session anchored in
 * real published learning material. It does not evaluate accuracy using AI.
 */
final class MobileInterviewController extends Controller
{
    public function __invoke(Request $request, PublicLearningPathController $learning): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->status === 'active'
            && $user->tokenCan('mobile'), 403);

        $input = $request->validate([
            'action' => ['required', Rule::in(['start', 'answer'])],
            'path_id' => ['required_if:action,start', 'prohibited_unless:action,start', 'uuid'],
            'lesson_index' => ['required_if:action,start', 'prohibited_unless:action,start',
                'integer', 'min:0', 'max:39'],
            'lang' => ['sometimes', 'in:ar,en'],
            'session_id' => ['required_if:action,answer', 'prohibited_unless:action,answer', 'uuid'],
            'answer' => ['required_if:action,answer', 'prohibited_unless:action,answer',
                'string', 'min:3', 'max:4000'],
        ]);

        $ownerId = (string) $user->getKey();

        return $input['action'] === 'start'
            ? $this->start($ownerId, $input, $learning)
            : $this->answer($ownerId, $input, $learning);
    }

    private function start(string $ownerId, array $input, PublicLearningPathController $learning): JsonResponse
    {
        $source = $learning->verifiedTrack($input['path_id']);
        abort_if($source === null || ! isset($source['outline'][$input['lesson_index']]), 404);
        $lesson = $source['outline'][$input['lesson_index']];
        $locale = $input['lang'] ?? 'en';
        $id = (string) Str::uuid();

        $allowed = DB::transaction(function () use ($ownerId, $input, $lesson, $locale, $id): bool {
            // Serialize admission for this account, including concurrent devices.
            DB::table('users')->where('id', $ownerId)->lockForUpdate()->first();
            if (DB::table('mobile_interview_sessions')->where('user_id', $ownerId)
                ->where('created_at', '>=', now()->subDay())->count() >= 50) {
                return false;
            }
            DB::table('mobile_interview_sessions')->insert([
                'id' => $id,
                'user_id' => $ownerId,
                'path_id' => $input['path_id'],
                'lesson_index' => $input['lesson_index'],
                'locale' => $locale,
                'lesson_title' => $lesson['title'],
                'lesson_context' => $lesson['description'],
                'answer_text' => null,
                'answered_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return true;
        });

        if (! $allowed) {
            return response()->json([
                'message' => 'Practice session limit reached. Try again later.',
            ], 429)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => [
            'session_id' => $id,
            'path_id' => $input['path_id'],
            'role' => $locale === 'ar' ? 'تدريب تقني ذاتي' : 'Technical self-practice',
            'question' => $locale === 'ar'
                ? 'اشرح كيف تطبّق الدرس المنشور: '.$lesson['title']
                : 'Explain how you would apply the published lesson: '.$lesson['title'],
            'context' => $lesson['description'],
            'status' => 'awaiting_answer',
            'progress' => ['answered' => 0, 'total' => 1],
            'feedback' => null,
        ]])->header('Cache-Control', 'no-store');
    }

    private function answer(string $ownerId, array $input, PublicLearningPathController $learning): JsonResponse
    {
        $result = DB::transaction(function () use ($ownerId, $input, $learning): ?array {
            $session = DB::table('mobile_interview_sessions')
                ->where('id', $input['session_id'])->where('user_id', $ownerId)
                ->lockForUpdate()->first();
            if ($session === null) {
                return null; // Do not reveal another user's session existence.
            }
            if ($session->answered_at !== null ||
                $session->created_at < now()->subDay()->toDateTimeString()) {
                return ['conflict' => true];
            }

            // Re-evaluate publication and exact approved lesson contents before
            // showing any source material, even to the session owner.
            $source = $learning->verifiedTrack($session->path_id);
            $lesson = $source['outline'][$session->lesson_index] ?? null;
            if ($source === null || $lesson === null ||
                $lesson['title'] !== $session->lesson_title ||
                $lesson['description'] !== $session->lesson_context) {
                return ['conflict' => true];
            }

            DB::table('mobile_interview_sessions')->where('id', $session->id)
                ->where('user_id', $ownerId)->whereNull('answered_at')->update([
                    'answer_text' => trim($input['answer']),
                    'answered_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'session_id' => $session->id,
                'path_id' => $session->path_id,
                'status' => 'answered',
                'progress' => ['answered' => 1, 'total' => 1],
                'feedback' => [
                    'type' => 'guided_self_review',
                    'message' => $session->locale === 'ar'
                        ? 'راجع إجابتك بنفسك مقابل محتوى الدرس المنشور. لم تُقيّم بواسطة الذكاء الاصطناعي.'
                        : 'Review your answer against the published lesson. No AI evaluation was performed.',
                    'review_source' => $lesson['description'],
                ],
            ];
        });

        if ($result === null) {
            return response()->json(['message' => 'Session not found.'], 404)
                ->header('Cache-Control', 'no-store');
        }
        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'Practice session expired, already answered or source withdrawn.',
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result])
            ->header('Cache-Control', 'no-store');
    }
}

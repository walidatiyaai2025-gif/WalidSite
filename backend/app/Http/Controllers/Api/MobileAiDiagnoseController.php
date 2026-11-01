<?php

namespace App\Http\Controllers\Api;

use App\AI\BoundedAiWorker;
use App\AI\DurableAiQueue;
use App\AI\MobileDiagnosisAdmission;
use App\AI\MobileDiagnosisDispatcher;
use App\Http\Controllers\Controller;
use App\Models\AiDiagnosisRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileAiDiagnoseController extends Controller
{
    public function __invoke(
        Request $request,
        MobileDiagnosisAdmission $admission,
        DurableAiQueue $queue,
        BoundedAiWorker $worker,
        MobileDiagnosisDispatcher $dispatcher,
        PublicRunbookController $runbooks,
    ): JsonResponse {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless(
            $user?->status === 'active'
                && $token instanceof PersonalAccessToken
                && $token->can('mobile'),
            403
        );

        $rules = [
            'question' => ['required', 'string', 'min:4', 'max:2000'],
            'technology' => ['required', 'string', 'min:1', 'max:120'],
            'code_snippet' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'consent_to_process' => ['required', 'boolean', 'accepted'],
        ];
        if (array_diff(array_keys($request->all()), array_keys($rules)) !== []) {
            throw ValidationException::withMessages([
                'request' => 'Only the documented diagnosis fields are accepted.',
            ]);
        }
        $input = $request->validate($rules);

        $selected = $admission->select();
        if ($selected === null) {
            return response()->json([
                'message' => 'AI diagnosis is not currently available.',
            ], 503)->header('Cache-Control', 'no-store');
        }

        $catalog = $runbooks->index(Request::create(
            '/api/v1/mobile/runbooks', 'GET', ['page' => 1]
        ))->getData(true);
        if (! is_array($catalog['data'] ?? null) || $catalog['data'] === []) {
            return response()->json([
                'message' => 'No reviewed solutions are currently available for diagnosis.',
            ], 503)->header('Cache-Control', 'no-store');
        }

        $diagnosis = DB::transaction(function () use ($user, $input, $selected, $queue): AiDiagnosisRequest {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $minuteCount = AiDiagnosisRequest::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinute())
                ->count();
            $dayCount = AiDiagnosisRequest::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();
            if ($minuteCount >= $selected['per_minute']
                || $dayCount >= $selected['per_day']) {
                abort(429, 'AI diagnosis quota reached.');
            }

            $privateInput = [
                'question' => trim($input['question']),
                'technology' => trim($input['technology']),
                'code_snippet' => (string) ($input['code_snippet'] ?? ''),
            ];
            $row = AiDiagnosisRequest::create([
                'user_id' => $user->id,
                'model_id' => $selected['model']->id,
                'request_blob' => Crypt::encryptString(
                    json_encode($privateInput, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                ),
                'expires_at' => now()->addDay(),
            ]);

            $jobId = $queue->enqueue(
                $selected['provider'],
                'mobile-diagnosis',
                'mobile-diagnosis:'.$row->id,
                ['classify'],
                $selected['reserve_minor'],
                1
            );
            $row->forceFill(['job_id' => $jobId])->save();

            return $row;
        }, 3);

        // Opportunistically execute one bounded diagnosis checkpoint now.
        // The normal registered ai:tick path remains the crash/recovery path.
        $worker->tick('mobile-http:'.Str::random(24), [
            'mobile-diagnosis' => static fn ($job, string $step, string $owner) =>
                $dispatcher->execute($job, $step, $owner),
        ]);

        $job = DB::table('ai_jobs')->where('id', $diagnosis->job_id)->firstOrFail();
        $diagnosis->refresh();
        $status = match ($job->status) {
            'completed' => 'completed',
            'failed' => 'failed',
            'running' => 'running',
            default => 'queued',
        };
        $progress = match ($status) {
            'completed', 'failed' => 100,
            'running' => 50,
            default => 0,
        };
        $resultUrl = $status === 'completed' && $diagnosis->selected_runbook_id !== null
            ? route('api.mobile.solution', ['id' => $diagnosis->selected_runbook_id], false)
            : null;

        return response()->json([
            'data' => [
                'id' => $job->id,
                'status' => $status,
                'progress_percent' => $progress,
                'result_url' => $resultUrl,
                'retry_after_seconds' => $status === 'queued' ? 30 : null,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}

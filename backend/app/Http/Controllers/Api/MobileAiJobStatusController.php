<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiDiagnosisRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Read-only, owner-scoped AI job status. Never repeats a paid diagnosis or
 * exposes the encrypted prompt, chosen provider or raw model response.
 */
final class MobileAiJobStatusController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        abort_unless(
            $user?->status === 'active'
                && $token instanceof PersonalAccessToken
                && $token->can('mobile'),
            403
        );

        $diagnosis = AiDiagnosisRequest::query()
            ->where('user_id', $user->id)
            ->where('job_id', $id)
            ->firstOrFail();

        $job = DB::table('ai_jobs')
            ->where('id', $id)
            ->where('job_type', 'mobile-diagnosis')
            ->firstOrFail();

        $status = match ($job->status) {
            'completed', 'failed', 'running', 'queued', 'blocked', 'cancelled' => $job->status,
            default => 'failed',
        };
        $progress = match ($status) {
            'completed', 'failed', 'cancelled' => 100,
            'running' => 50,
            default => 0,
        };

        return response()->json([
            'data' => [
                'id' => $job->id,
                'status' => $status,
                'progress_percent' => $progress,
                'result_url' => $status === 'completed'
                    && $diagnosis->selected_runbook_id !== null
                        ? route('api.mobile.solution', ['id' => $diagnosis->selected_runbook_id], false)
                        : null,
                'retry_after_seconds' => $status === 'queued' || $status === 'running'
                    ? 30
                    : null,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}

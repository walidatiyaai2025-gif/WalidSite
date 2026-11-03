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
use Illuminate\Database\QueryException;
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

        // The header is opt-in during the W3 client roll-out. Once W3
        // ships stable keys for every intended diagnosis, LEAD can require
        // it at the shared API contract boundary. Legacy unkeyed clients
        // remain unchanged until that coordinated cutover.
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (! is_string($key)
            || ! preg_match('/\\A[A-Za-z0-9_-]{16,128}\\z/D', $key))) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid opaque Idempotency-Key header is required.',
            ]);
        }

        $privateInput = [
            'question' => trim($input['question']),
            'technology' => trim($input['technology']),
            'code_snippet' => (string) ($input['code_snippet'] ?? ''),
        ];
        // Neither secret request content nor the bearer key is persisted in
        // plaintext or logged. Canonicalize what is actually admitted.
        $secret = (string) config('app.key');
        $keyHash = $key === null ? null : hash_hmac('sha256', $key, $secret);
        $fingerprint = $keyHash === null ? null : hash_hmac('sha256',
            json_encode($privateInput + ['consent_to_process' => true],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $secret);

        // Replay must precede provider, price, budget and runbook discovery:
        // those can change after the original paid admission. A timed-out
        // client should still recover its accepted job without new charges.
        if ($keyHash !== null) {
            $prior = DB::transaction(function () use (
                $user, $token, $keyHash, $fingerprint
            ): ?AiDiagnosisRequest {
                $this->assertLiveMobileActor($user, $token);
                return $this->existingForKey($keyHash, $user->id, $fingerprint);
            });
            if ($prior !== null) {
                return $this->jobResponse($prior, $runbooks);
            }
        }

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

        try {
            [$diagnosis, $created] = DB::transaction(function () use (
                $user, $token, $privateInput, $selected, $queue, $keyHash, $fingerprint
            ): array {
                // The account row is the admission mutex on MariaDB: two
                // same-user retries cannot pass the dedupe/quotas concurrently.
                $this->assertLiveMobileActor($user, $token);

                if ($keyHash !== null) {
                    $prior = $this->existingForKey($keyHash, $user->id, $fingerprint);
                    if ($prior !== null) {
                        return [$prior, false];
                    }
                }

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

                $row = AiDiagnosisRequest::create([
                    'user_id' => $user->id,
                    'model_id' => $selected['model']->id,
                    'idempotency_key_hash' => $keyHash,
                    'request_fingerprint' => $fingerprint,
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

                return [$row, true];
            }, 3);
        } catch (QueryException $exception) {
            // A simultaneous different-account submission can race the
            // preflight lookup; the global database UNIQUE constraint is the
            // final gate. Never echo the key, SQL or prompt in the response.
            if ($keyHash !== null && str_starts_with((string) $exception->getCode(), '23')
                && AiDiagnosisRequest::query()
                    ->where('idempotency_key_hash', $keyHash)->exists()) {
                abort(409, 'Idempotency key is already in use.');
            }
            throw $exception;
        }

        // Opportunistically execute one bounded diagnosis checkpoint now.
        // The normal registered ai:tick path remains the crash/recovery path.
        if ($created) {
            // A replay is strictly read-only, including queued/failed jobs.
            $worker->tick('mobile-http:'.Str::random(24), [
                'mobile-diagnosis' => static fn ($job, string $step, string $owner) =>
                    $dispatcher->execute($job, $step, $owner),
            ]);
        }

        return $this->jobResponse($diagnosis, $runbooks);
    }

    /**
     * Caller must hold a transaction while validating the persisted account.
     * Rechecking the real bearer excludes mid-request revoked/expired tokens.
     */
    private function assertLiveMobileActor(User $user, PersonalAccessToken $token): void
    {
        $account = User::query()->whereKey($user->id)->lockForUpdate()->first();
        abort_unless($account !== null && $account->status === 'active'
            && $account->tokens()->whereKey($token->getKey())
                ->where(function ($query): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })->exists(), 403);
    }

    private function existingForKey(
        string $keyHash,
        string $userId,
        string $fingerprint,
    ): ?AiDiagnosisRequest {
        $prior = AiDiagnosisRequest::query()
            ->where('idempotency_key_hash', $keyHash)->first();
        if ($prior === null) {
            return null;
        }

        abort_unless($prior->user_id === $userId
            && is_string($prior->request_fingerprint)
            && hash_equals($prior->request_fingerprint, $fingerprint), 409,
            'Idempotency key conflicts with another diagnosis.');
        abort_unless($prior->job_id !== null, 503,
            'Previous AI admission has not completed.');

        return $prior;
    }

    private function jobResponse(
        AiDiagnosisRequest $diagnosis,
        PublicRunbookController $runbooks,
    ): JsonResponse {
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
        // A historical AI selection is not a publication grant. Re-run the
        // exact current reviewed-runbook gate before advertising a destination:
        // the source may have been withdrawn after worker selection.
        $resultUrl = $status === 'completed'
            && $diagnosis->selected_runbook_id !== null
            && $runbooks->verifiedData((string) $diagnosis->selected_runbook_id) !== null
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

<?php

namespace App\Http\Controllers\Api;

use App\Domain\Privacy\NotificationConsentGate;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletionEvent;
use App\Models\AiDiagnosisRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AccountPrivacyController extends Controller
{
    private const EXPORT_SAVED_LIMIT = 10000;

    private const EXPORT_INTERVIEW_LIMIT = 1000;

    public function export(Request $request): JsonResponse
    {
        $user = $this->mobileUser($request);
        $this->onlyFields($request, []);

        // Export and deletion share a user-row lock. A stale authenticated
        // request must never export private answers after account erasure, or
        // observe a partly cleared account in the middle of that transaction.
        $archive = DB::transaction(function () use ($user): array {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_unless($locked !== null && $locked->status === 'active', 403);

            $saved = DB::table('saved_resources')
                ->where('user_id', $locked->id)
                ->orderBy('saved_at')
                ->orderBy('id')
                ->limit(self::EXPORT_SAVED_LIMIT + 1)
                ->get(['resource_type', 'resource_id', 'saved_at']);

            if ($saved->count() > self::EXPORT_SAVED_LIMIT) {
                return ['error' => 'Account export exceeds the self-service safety limit.'];
            }

            // These are private, user-authored answers. Their prior lesson
            // context may now be withdrawn, so export only the account's own
            // writing and minimal session provenance (not source content).
            $interviews = DB::table('mobile_interview_sessions')
                ->where('user_id', $locked->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(self::EXPORT_INTERVIEW_LIMIT + 1)
                ->get([
                    'id', 'path_id', 'lesson_index', 'locale',
                    'answer_text', 'answered_at', 'created_at',
                ]);

            if ($interviews->count() > self::EXPORT_INTERVIEW_LIMIT) {
                return ['error' => 'Private practice archive exceeds the self-service safety limit.'];
            }

            $preferences = NotificationPreference::query()
                ->where('user_id', $locked->id)
                ->orderBy('purpose')
                ->orderBy('channel')
                ->get()
                ->map(static fn (NotificationPreference $preference): array => [
                    'purpose' => $preference->purpose,
                    'channel' => $preference->channel,
                    'enabled' => $preference->enabled,
                    'consented_at' => $preference->consented_at?->toIso8601String(),
                    'revoked_at' => $preference->revoked_at?->toIso8601String(),
                ])->values()->all();

            return [
                'data' => [
                    'profile' => [
                        'id' => $locked->id,
                        'email' => $locked->email,
                        'status' => $locked->status,
                        'created_at' => $locked->created_at?->toIso8601String(),
                    ],
                    'saved_resources' => $saved->map(static fn ($row): array => [
                        'resource_type' => $row->resource_type,
                        'resource_id' => $row->resource_id,
                        'saved_at' => (string) $row->saved_at,
                    ])->values()->all(),
                    'private_interviews' => $interviews->map(static fn ($row): array => [
                        'session_id' => $row->id,
                        'path_id' => $row->path_id,
                        'lesson_index' => (int) $row->lesson_index,
                        'locale' => $row->locale,
                        'answer_text' => $row->answer_text,
                        'answered_at' => $row->answered_at,
                        'created_at' => $row->created_at,
                    ])->values()->all(),
                    'notification_preferences' => $preferences,
                ],
                'meta' => [
                    'exported_at' => now('UTC')->toIso8601String(),
                    'secrets_excluded' => true,
                    'private_ai_prompts_excluded' => true,
                    'private_interview_answers_included' => true,
                    'foreign_user_data_excluded' => true,
                ],
            ];
        }, 3);

        if (isset($archive['error'])) {
            // No partially truncated archive may be mistaken for a complete
            // right-of-access export.
            return response()->json(['message' => $archive['error']], 413)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($archive)
            ->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="walidsite-account-export.json"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $this->mobileUser($request);
        $this->onlyFields($request, []);

        return response()->json([
            'data' => $this->preferenceMatrix($user),
            'meta' => [
                'default_deny' => true,
                'purposes' => NotificationConsentGate::PURPOSES,
                'channels' => NotificationConsentGate::CHANNELS,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $this->mobileUser($request);
        $this->onlyFields($request, ['preferences']);

        $input = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:6'],
            'preferences.*' => ['required', 'array:purpose,channel,enabled'],
            'preferences.*.purpose' => [
                'required',
                'string',
                Rule::in(NotificationConsentGate::PURPOSES),
            ],
            'preferences.*.channel' => [
                'required',
                'string',
                Rule::in(NotificationConsentGate::CHANNELS),
            ],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $seen = [];
        foreach ($input['preferences'] as $preference) {
            $key = $preference['purpose'].':'.$preference['channel'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'preferences' => 'Duplicate purpose/channel preference.',
                ]);
            }
            $seen[$key] = true;
        }

        DB::transaction(function () use ($user, $input): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 403);

            foreach ($input['preferences'] as $inputPreference) {
                $preference = NotificationPreference::query()->firstOrNew([
                    'user_id' => $locked->id,
                    'purpose' => $inputPreference['purpose'],
                    'channel' => $inputPreference['channel'],
                ]);

                $enable = (bool) $inputPreference['enabled'];
                if ($enable) {
                    // The user is actively opting in here. Imported or
                    // corrupted future timestamps are not valid prior consent;
                    // replace them with the verified current opt-in time.
                    if (! $preference->exists
                        || $preference->enabled !== true
                        || $preference->consented_at === null
                        || $preference->consented_at->isFuture()
                        || $preference->revoked_at !== null) {
                        $preference->consented_at = now('UTC');
                    }
                    $preference->enabled = true;
                    $preference->revoked_at = null;
                } else {
                    $preference->enabled = false;
                    $preference->revoked_at = now('UTC');
                }

                $preference->save();
            }
        }, 3);

        $user->refresh();

        return response()->json([
            'data' => $this->preferenceMatrix($user),
            'meta' => ['default_deny' => true],
        ])->header('Cache-Control', 'no-store');
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $this->mobileUser($request);
        $this->onlyFields($request, ['password', 'confirmation']);

        $input = $request->validate([
            'password' => ['required', 'string', 'max:4096'],
            'confirmation' => ['required', 'string', Rule::in(['DELETE'])],
        ]);

        $cancelledJobs = DB::transaction(function () use ($user, $input): int {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'active', 409);

            $passwordHash = $locked->getAuthPassword();
            if (! is_string($passwordHash)
                || $passwordHash === ''
                || ! Hash::check($input['password'], $passwordHash)) {
                throw ValidationException::withMessages([
                    'password' => 'Password reauthentication failed.',
                ]);
            }

            $jobIds = AiDiagnosisRequest::query()
                ->where('user_id', $locked->id)
                ->whereNotNull('job_id')
                ->lockForUpdate()
                ->pluck('job_id')
                ->filter()
                ->values();

            $cancelled = 0;
            if ($jobIds->isNotEmpty()) {
                DB::table('ai_jobs')
                    ->whereIn('id', $jobIds)
                    ->lockForUpdate()
                    ->get(['id', 'status']);

                $cancelled = DB::table('ai_jobs')
                    ->whereIn('id', $jobIds)
                    ->whereIn('status', ['queued', 'running', 'retry'])
                    ->update([
                        'status' => 'cancelled',
                        'next_attempt_at' => null,
                        'result_ref' => null,
                        'last_error_code' => 'account_deleted',
                        'updated_at' => now('UTC'),
                    ]);

                // Status is an accounting/lifecycle fact, not consent to retain
                // output pointers. Terminal completed/failed/cancelled jobs can
                // still contain a result_ref and completed checkpoint refs.
                // Erase all account-bound output references while preserving
                // immutable spend receipts and terminal job statuses.
                DB::table('ai_jobs')
                    ->whereIn('id', $jobIds)
                    ->whereNotNull('result_ref')
                    ->update([
                        'result_ref' => null,
                        'updated_at' => now('UTC'),
                    ]);
                DB::table('ai_job_steps')
                    ->whereIn('job_id', $jobIds)
                    ->whereNotNull('output_ref')
                    ->update([
                        'output_ref' => null,
                        'updated_at' => now('UTC'),
                    ]);

                DB::table('job_leases')->whereIn('job_id', $jobIds)->delete();
            }

            AiDiagnosisRequest::query()->where('user_id', $locked->id)->delete();
            // Private interview answers remain in a separate table. The user
            // record is anonymized, not physically deleted, so its FK
            // cascade cannot purge these answers. Delete under the same
            // account lock and transaction as token revocation.
            DB::table('mobile_interview_sessions')->where('user_id', $locked->id)->delete();
            DB::table('saved_resources')->where('user_id', $locked->id)->delete();
            NotificationPreference::query()->where('user_id', $locked->id)->delete();
            DB::table('role_user')->where('user_id', $locked->id)->delete();
            $locked->tokens()->delete();

            if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
                DB::table('sessions')->where('user_id', $locked->id)->delete();
            }

            $subjectHash = hash('sha256', 'walidsite-account-deletion:'.$locked->id);
            $anonymousEmail = 'deleted+'.substr(hash('sha256', $locked->id), 0, 32).'@invalid.local';

            $locked->forceFill([
                'email' => $anonymousEmail,
                'password_hash' => null,
                'status' => 'deleted',
            ])->save();

            AccountDeletionEvent::query()->firstOrCreate(
                ['subject_hash' => $subjectHash],
                [
                    'reason_code' => 'user_requested',
                    'cancelled_ai_jobs' => $cancelled,
                    'deleted_at' => now('UTC'),
                    'created_at' => now('UTC'),
                ]
            );

            return $cancelled;
        }, 3);

        return response()->json([
            'data' => [
                'deleted' => true,
                'tokens_revoked' => true,
                'notification_consent_revoked' => true,
                'cancelled_ai_jobs' => $cancelledJobs,
            ],
            'meta' => [
                'pii_erased_or_anonymized' => true,
                'audit_contains_no_email_or_password' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    private function mobileUser(Request $request): User
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        abort_unless(
            $user instanceof User
                && $user->status === 'active'
                && $token instanceof PersonalAccessToken
                && $token->can('mobile'),
            403
        );

        return $user;
    }

    private function onlyFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages([
                'request' => 'Unsupported request fields.',
            ]);
        }
    }

    private function preferenceMatrix(User $user): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(static fn (NotificationPreference $preference): string =>
                $preference->purpose.':'.$preference->channel
            );

        $result = [];
        foreach (NotificationConsentGate::PURPOSES as $purpose) {
            foreach (NotificationConsentGate::CHANNELS as $channel) {
                $preference = $stored->get($purpose.':'.$channel);
                $result[] = [
                    'purpose' => $purpose,
                    'channel' => $channel,
                    'enabled' => $preference?->enabled === true
                        && $preference->consented_at !== null
                        && ! $preference->consented_at->isFuture()
                        && $preference->revoked_at === null,
                    'consented_at' => $preference?->consented_at?->toIso8601String(),
                    'revoked_at' => $preference?->revoked_at?->toIso8601String(),
                ];
            }
        }

        return $result;
    }
}

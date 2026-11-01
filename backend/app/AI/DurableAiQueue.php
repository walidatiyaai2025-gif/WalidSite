<?php

namespace App\AI;

use App\Models\AiProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/**
 * Storage-only AI work scheduler. It never invokes a model itself.
 * T014 binds step executors; no cPanel daemon or unbounded queue worker.
 */
final class DurableAiQueue
{
    public function enqueue(
        AiProvider $provider, string $kind, string $key, array $steps,
        int $reserveMinor = 0, int $maxAttempts = 3
    ): string {
        if (! preg_match('/^[A-Za-z0-9_.:\-]{1,160}$/D', $key) ||
            ! preg_match('/^[A-Za-z0-9_.:\-]{1,80}$/D', $kind) ||
            $steps === [] || count($steps) > 20 ||
            $reserveMinor < 0 || $maxAttempts < 1 || $maxAttempts > 5) {
            throw new InvalidArgumentException('Invalid durable job definition.');
        }
        foreach ($steps as $step) {
            if (! is_string($step) || ! preg_match('/^[A-Za-z0-9_.:\-]{1,100}$/D', $step)) {
                throw new InvalidArgumentException('Invalid checkpoint step key.');
            }
        }
        if (count($steps) !== count(array_unique($steps))) {
            throw new InvalidArgumentException('Duplicate checkpoint step.');
        }

        $hash = hash('sha256', json_encode([
            $provider->id, $kind, array_values($steps), $reserveMinor, $maxAttempts,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($provider, $kind, $key, $steps, $hash, $reserveMinor, $maxAttempts) {
            $id = (string) Str::uuid();
            $inserted = DB::table('ai_jobs')->insertOrIgnore([
                'id' => $id, 'provider_id' => $provider->id,
                'job_type' => $kind, 'idempotency_key' => $key,
                'payload_hash' => $hash, 'status' => 'queued',
                'attempt_count' => 0, 'max_attempts' => $maxAttempts,
                'reserved_budget_minor' => $reserveMinor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $job = DB::table('ai_jobs')->where('idempotency_key', $key)->first();
            if (! $job || ! hash_equals($job->payload_hash, $hash)) {
                throw new DomainException('Idempotency key already belongs to different work.');
            }
            if ($inserted) {
                foreach ($steps as $step) {
                    DB::table('ai_job_steps')->insert([
                        'id' => (string) Str::uuid(), 'job_id' => $id,
                        'step_key' => $step, 'status' => 'pending',
                        'attempt_no' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            return $job->id;
        }, 3);
    }

    /** Returns a single leased job, never more than one per call. */
    public function claimNext(string $owner, int $ttlSeconds = 60): ?stdClass
    {
        $this->validateOwner($owner, $ttlSeconds);
        $this->recoverExpired(5);

        $ids = DB::table('ai_jobs')->whereIn('status', ['queued', 'retry'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')->limit(20)->pluck('id');

        foreach ($ids as $id) {
            $claimed = DB::transaction(function () use ($id, $owner, $ttlSeconds) {
                $candidate = DB::table('ai_jobs')->where('id', $id)->first();
                if (! $candidate) {
                    return null;
                }
                // Provider row is our cross-worker mutex on MariaDB. Recheck
                // job status after acquiring it; do not trust a stale candidate.
                $provider = DB::table('ai_providers')->where('id', $candidate->provider_id)
                    ->lockForUpdate()->first();
                $job = DB::table('ai_jobs')->where('id', $id)->lockForUpdate()->first();
                if (! $provider || ! $job || ! in_array($job->status, ['queued', 'retry'], true) ||
                    ($job->next_attempt_at && \Illuminate\Support\Carbon::parse($job->next_attempt_at)->isFuture()) ||
                    // Raw query builder bypasses AiProvider's SoftDeletes scope.
                    // Archived providers must never start new AI executions.
                    $provider->deleted_at !== null ||
                    ! $provider->enabled || $provider->health_status !== 'healthy') {
                    return null;
                }
                $circuit = DB::table('ai_provider_circuits')
                    ->where('provider_id', $provider->id)->first();
                if ($circuit && $circuit->opened_until && \Illuminate\Support\Carbon::parse($circuit->opened_until)->isFuture()) {
                    return null;
                }

                $running = DB::table('ai_jobs')->where('provider_id', $provider->id)
                    ->where('status', 'running')->count();
                if ($running >= max(1, (int) $provider->concurrent_limit)) {
                    return null;
                }
                $spent = (int) DB::table('ai_usage')->where('provider_id', $provider->id)
                    ->where('created_at', '>=', now()->startOfDay())->sum('cost_minor');
                // A running job's reservation includes amounts it has already
                // reported. Daily spend accounts for those receipts, so adding
                // the full reservation again would double-count paid usage and
                // unnecessarily starve the provider when concurrency > 1.
                // Keep lifetime receipts when calculating remaining liability:
                // retries and jobs crossing UTC days must not earn a new cap.
                $runningJobs = DB::table('ai_jobs')->where('provider_id', $provider->id)
                    ->where('status', 'running')->get(['id', 'reserved_budget_minor']);
                $outstanding = 0;
                foreach ($runningJobs as $runningJob) {
                    $recorded = (int) DB::table('ai_usage')
                        ->where('job_id', $runningJob->id)->sum('cost_minor');
                    $outstanding += max(0, (int) $runningJob->reserved_budget_minor - $recorded);
                }
                $remainingToday = (int) $provider->daily_budget_minor - $spent;
                if ($outstanding > $remainingToday ||
                    (int) $job->reserved_budget_minor > $remainingToday - $outstanding) {
                    return null;
                }
                // Paid compatible providers require a positive cost reservation.
                if ($provider->provider_type === 'openai-compatible' &&
                    (int) $job->reserved_budget_minor === 0) {
                    return null;
                }

                DB::table('ai_jobs')->where('id', $id)->update([
                    'status' => 'running', 'attempt_count' => $job->attempt_count + 1,
                    'next_attempt_at' => null, 'updated_at' => now(),
                ]);
                DB::table('job_leases')->insert([
                    'id' => (string) Str::uuid(), 'job_id' => $id,
                    'owner_key' => $owner, 'expires_at' => now()->addSeconds($ttlSeconds),
                    'lease_version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                return DB::table('ai_jobs')->where('id', $id)->first();
            }, 3);
            if ($claimed) {
                return $claimed;
            }
        }
        return null;
    }

    public function completeStep(string $jobId, string $owner, string $step, ?string $outputRef): void
    {
        $this->validateOutputRef($outputRef);
        DB::transaction(function () use ($jobId, $owner, $step, $outputRef): void {
            $job = $this->requireLease($jobId, $owner);
            $row = DB::table('ai_job_steps')->where('job_id', $jobId)
                ->where('step_key', $step)->lockForUpdate()->first();
            if (! $row) {
                throw new DomainException('Unknown checkpoint.');
            }
            if ($row->status === 'completed') {
                if ($row->output_ref !== $outputRef) {
                    throw new DomainException('Completed checkpoint is immutable.');
                }
                return;
            }
            DB::table('ai_job_steps')->where('id', $row->id)->update([
                'status' => 'completed', 'output_ref' => $outputRef,
                'attempt_no' => $job->attempt_count,
                'completed_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function completeJob(string $jobId, string $owner, ?string $resultRef = null): void
    {
        $this->validateOutputRef($resultRef);
        DB::transaction(function () use ($jobId, $owner, $resultRef): void {
            $job = $this->requireLease($jobId, $owner);
            $provider = DB::table('ai_providers')->where('id', $job->provider_id)->first();
            if ($provider && $provider->provider_type === 'openai-compatible' &&
                ! DB::table('ai_usage')->where('job_id', $jobId)->exists()) {
                throw new DomainException('Paid work requires a usage receipt before completion.');
            }
            if (DB::table('ai_job_steps')->where('job_id', $jobId)
                ->where('status', '!=', 'completed')->exists()) {
                throw new DomainException('Incomplete checkpoints cannot be published.');
            }
            DB::table('ai_jobs')->where('id', $jobId)->update([
                'status' => 'completed', 'result_ref' => $resultRef, 'updated_at' => now(),
            ]);
            DB::table('job_leases')->where('job_id', $jobId)->delete();
            DB::table('ai_provider_circuits')->where('provider_id', $job->provider_id)->update([
                'consecutive_failures' => 0, 'opened_until' => null,
                'last_failure_code' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Identical usage key is counted only once; no prompts, keys or raw outputs. */
    public function recordUsage(
        string $jobId, string $owner, string $usageKey, int $costMinor,
        string $currency, ?string $modelKey = null, array $tokens = []
    ): bool {
        if ($costMinor < 0 || ($modelKey !== null && ! preg_match('/^[A-Za-z0-9_.:\-]{1,160}$/D', $modelKey)) ||
            ! preg_match('/^[A-Za-z0-9_.:\-]{1,180}$/D', $usageKey) ||
            array_diff(array_keys($tokens), ['input_tokens', 'output_tokens']) ||
            array_filter($tokens, fn ($v) => ! is_int($v) || $v < 0)) {
            throw new InvalidArgumentException('Invalid usage receipt.');
        }
        return DB::transaction(function () use ($jobId, $owner, $usageKey, $costMinor, $currency, $modelKey, $tokens) {
            $job = $this->requireLease($jobId, $owner);
            $provider = DB::table('ai_providers')->where('id', $job->provider_id)
                ->lockForUpdate()->first();
            if (! $provider || $currency !== $provider->currency) {
                throw new DomainException('Provider currency mismatch.');
            }
            $old = DB::table('ai_usage')->where('usage_key', $usageKey)->first();
            if ($old) {
                if ($old->job_id !== $jobId || (int) $old->cost_minor !== $costMinor ||
                    $old->model_key !== $modelKey || $old->cost_currency !== $currency ||
                    $old->usage_json !== json_encode($tokens, JSON_THROW_ON_ERROR)) {
                    throw new DomainException('Usage receipt conflict.');
                }
                return false;
            }
            $spent = (int) DB::table('ai_usage')->where('provider_id', $provider->id)
                ->where('created_at', '>=', now()->startOfDay())->sum('cost_minor');
            if ($spent + $costMinor > (int) $provider->daily_budget_minor) {
                throw new DomainException('Daily provider spending cap reached.');
            }
            // An admission reservation is the maximum aggregate charge for
            // this job, not an allowance per receipt or per retry. Without
            // this check, multiple smaller receipts can overshoot the job
            // estimate while remaining under the provider's daily cap.
            // Check the job's entire receipt history (including past days)
            // under its locked lease; identical receipts were handled above.
            $reservedForJob = (int) $job->reserved_budget_minor;
            $jobRecordedCost = (int) DB::table('ai_usage')
                ->where('job_id', $jobId)->sum('cost_minor');
            if ($costMinor > $reservedForJob ||
                $jobRecordedCost > $reservedForJob - $costMinor) {
                throw new DomainException('AI job usage exceeds reserved cost.');
            }

            DB::table('ai_usage')->insert([
                'id' => (string) Str::uuid(), 'usage_key' => $usageKey,
                'job_id' => $jobId, 'provider_id' => $provider->id,
                'model_key' => $modelKey, 'cost_minor' => $costMinor,
                'cost_currency' => $currency,
                'usage_json' => json_encode($tokens, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            return true;
        }, 3);
    }

    public function failJob(string $jobId, string $owner, string $errorCode = 'execution_failed'): void
    {
        if (! preg_match('/^[a-z0-9_]{1,50}$/D', $errorCode)) {
            throw new InvalidArgumentException('Only redacted error codes may be stored.');
        }
        DB::transaction(function () use ($jobId, $owner, $errorCode): void {
            $job = $this->requireLease($jobId, $owner);
            $retry = $job->attempt_count < $job->max_attempts;
            DB::table('ai_jobs')->where('id', $jobId)->update([
                'status' => $retry ? 'retry' : 'failed',
                'next_attempt_at' => $retry ? now()->addSeconds(min(900, 10 * 2 ** $job->attempt_count)) : null,
                'last_error_code' => $errorCode, 'updated_at' => now(),
            ]);
            DB::table('job_leases')->where('job_id', $jobId)->delete();

            $state = DB::table('ai_provider_circuits')->where('provider_id', $job->provider_id)
                ->lockForUpdate()->first();
            $failures = $state ? (int) $state->consecutive_failures + 1 : 1;
            DB::table('ai_provider_circuits')->updateOrInsert(
                ['provider_id' => $job->provider_id],
                ['consecutive_failures' => $failures, 'last_failure_code' => $errorCode,
                 'opened_until' => $failures >= 3 ? now()->addMinutes(5) : null,
                 'updated_at' => now()]
            );
        }, 3);
    }

    /** Repair only a small bounded number of interrupted leases per invocation. */
    public function recoverExpired(int $limit = 5): int
    {
        $expired = DB::table('job_leases')->where('expires_at', '<=', now())
            ->orderBy('expires_at')->limit(max(1, min(5, $limit)))->pluck('job_id');
        $count = 0;
        foreach ($expired as $id) {
            $changed = DB::transaction(function () use ($id) {
                $lease = DB::table('job_leases')->where('job_id', $id)
                    ->lockForUpdate()->first();
                if (! $lease || \Illuminate\Support\Carbon::parse($lease->expires_at)->isFuture()) {
                    return false;
                }
                $job = DB::table('ai_jobs')->where('id', $id)->lockForUpdate()->first();
                if ($job && $job->status === 'running') {
                    $retry = $job->attempt_count < $job->max_attempts;
                    DB::table('ai_jobs')->where('id', $id)->update([
                        'status' => $retry ? 'retry' : 'failed',
                        'next_attempt_at' => $retry ? now()->addSeconds(30) : null,
                        'last_error_code' => 'lease_expired', 'updated_at' => now(),
                    ]);
                }
                DB::table('job_leases')->where('job_id', $id)->delete();
                return true;
            }, 3);
            if ($changed) {
                ++$count;
            }
        }
        return $count;
    }

    private function requireLease(string $jobId, string $owner): stdClass
    {
        $this->validateOwner($owner, 60);
        $job = DB::table('ai_jobs')->where('id', $jobId)->lockForUpdate()->first();
        $lease = DB::table('job_leases')->where('job_id', $jobId)->lockForUpdate()->first();
        if (! $job || $job->status !== 'running' || ! $lease ||
            $lease->owner_key !== $owner || \Illuminate\Support\Carbon::parse($lease->expires_at)->isPast()) {
            throw new DomainException('An active matching lease is required.');
        }
        return $job;
    }

    private function validateOwner(string $owner, int $ttl): void
    {
        if (! preg_match('/^[A-Za-z0-9_.:\-]{1,180}$/D', $owner) ||
            $ttl < 15 || $ttl > 300) {
            throw new InvalidArgumentException('Invalid bounded lease.');
        }
    }

    private function validateOutputRef(?string $ref): void
    {
        if ($ref !== null && (strlen($ref) > 255 ||
            str_contains($ref, '..') || str_contains($ref, '://') ||
            ! preg_match('/^[A-Za-z0-9_\/.:\-]+$/D', $ref))) {
            throw new InvalidArgumentException('Output references must be opaque storage keys.');
        }
    }
}

<?php

namespace App\AI;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Executes at most ONE checkpoint per invocation; safe for a short cPanel
 * cron tick. T014 supplies explicit real content/provider step handlers.
 *
 * No registered handler means no job is claimed and no AI call occurs.
 * Callback signature: fn (stdClass $job, string $step, string $owner,
 * DurableAiQueue $queue): ?string; returned reference must be opaque.
 */
final class BoundedAiWorker
{
    public function __construct(private readonly DurableAiQueue $queue)
    {
    }

    /**
     * @param array<string, Closure> $handlers keyed by registered job_type
     * @return array{state:string, job_id:?string}
     */
    public function tick(string $owner, array $handlers): array
    {
        if ($handlers === []) {
            return ['state' => 'idle', 'job_id' => null];
        }
        foreach ($handlers as $kind => $handler) {
            if (! is_string($kind) || ! $handler instanceof Closure) {
                throw new InvalidArgumentException('Worker requires explicit closure handlers by kind.');
            }
        }

        // Only claim kinds with an actual registered executor. Unknown work is
        // never consumed, failed, or charged due to missing application code.
        $job = $this->queue->claimNext($owner, 60, array_keys($handlers));
        if ($job === null) {
            return ['state' => 'idle', 'job_id' => null];
        }

        try {
            $step = DB::table('ai_job_steps')
                ->where('job_id', $job->id)->where('status', 'pending')
                ->orderBy('position')->first();

            if ($step === null) {
                // Recover a crash between the final checkpoint and finalization.
                $this->queue->completeJob($job->id, $owner);
                return ['state' => 'completed', 'job_id' => $job->id];
            }

            $ref = $handlers[$job->job_type]($job, $step->step_key, $owner, $this->queue);
            if ($ref !== null && ! is_string($ref)) {
                throw new DomainException('Invalid handler output reference.');
            }
            $this->queue->completeStep($job->id, $owner, $step->step_key, $ref);

            $hasMore = DB::table('ai_job_steps')->where('job_id', $job->id)
                ->where('status', 'pending')->exists();
            if ($hasMore) {
                $this->queue->yieldCheckpointedJob($job->id, $owner);
                return ['state' => 'checkpointed', 'job_id' => $job->id];
            }

            $this->queue->completeJob($job->id, $owner, $ref);
            return ['state' => 'completed', 'job_id' => $job->id];
        } catch (Throwable) {
            // Do not serialize the exception, endpoint, prompt or credentials.
            // If the lease expired mid-step, another worker owns recovery.
            try {
                $this->queue->failJob($job->id, $owner, 'step_failed');
            } catch (DomainException) {
                return ['state' => 'lease_lost', 'job_id' => $job->id];
            }
            return ['state' => 'retry', 'job_id' => $job->id];
        }
    }
}

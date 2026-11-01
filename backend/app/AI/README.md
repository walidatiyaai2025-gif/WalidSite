# T011 durable AI job foundation

This implementation is intentionally **storage-only**. It does not call Ollama/OpenAI, launch a process, run a scheduler or pretend a provider credential exists. T014 owns the authenticated model execution and image/research pipeline, and must supply a bounded handler.

## Runtime contract
- Queue through `App\\AI\\DurableAiQueue::enqueue()`: immutable idempotency key and ordered step keys, no raw prompts or secrets stored.
- `claimNext(owner)` recovers at most five expired leases and claims **one** job per invocation, using a row lock on the provider as the MariaDB concurrency mutex. Default provider limit is one.
- Only `enabled`, explicitly healthy providers may be claimed; managed providers require a positive cost reservation, and daily usage + active reservations must fit their admin-configured budget.
- A worker that actually runs a step must call `completeStep` with an opaque storage reference; repeated equal checkpoints are safe. `completeJob` requires all checkpoints; paid jobs require at least one usage receipt.
- A legitimate in-flight worker may call `heartbeat(jobId, owner, ttlSeconds)` to renew only its active unexpired lease. Renewal is bounded by **five minutes from the original claim**, never shortens an existing lease, and cannot revive completed or expired work. Use it before a lengthy checkpoint, not to run a continuous daemon; the caller must stop at the lease deadline.
- `recordUsage` is idempotent by unique usage key, validates currency and nonnegative token-count metadata, checks daily limits and never stores prompts, provider secrets or responses.
- `failJob` records only a redacted machine error code, schedules bounded exponential retries (maximum five attempts), and opens a five-minute circuit after three consecutive failures. Expired leases can be recovered in bounded batches.
- Do not schedule a continuous worker process on cPanel. Future T014 must implement a one-action-per-request/cron-tick integration with a finite execution budget, explicit handler registration and safe errors.

## Verified scope / limits
PHPUnit coverage tests duplicate suppression, owner leases, checkpoint immutability, spend caps, retry circuit, expired recovery, no outbound calls and opaque references. CI also runs MariaDB migration smoke. Concurrent multi-process load testing, global per-site caps, actual token pricing, model catalog and image generation are **not delivered** here; those are separate T014/T015/T024 acceptance gates.

The admin panel persists daily budgets, concurrency, credentials and health; never assume stored budget settings alone mean all future providers are billed or executed. All web/API access remains protected by T002/T015.

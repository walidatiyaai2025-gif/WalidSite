# T011 durable AI jobs and bounded cron ticks

The job queue and execution dispatcher are implemented. **No provider inference, paid request, image generation, or live cPanel scheduler is activated by this file.** Provider-specific handlers and editorial/image workflows belong to T014; host activation and production smoke belong to T025–T027.

## Storage and safety

- `DurableAiQueue::enqueue()` takes an idempotency key, stable job kind, and **ordered** checkpoint names, with a maximum of 20 steps and five failure attempts. A conflicting idempotency key is rejected. No raw prompts, response text, credentials, or customer secrets are stored.
- Queue admission is limited to enabled, explicitly healthy, non-archived providers with closed circuit breakers, configured concurrency and reservation/budget capacity. Default concurrency is one per provider. Paid-compatible jobs require positive reservation.
- `claimNext(owner, ttl, allowedKinds)` claims at most one job through the provider row mutex. A worker registering specific kinds never consumes unrecognized jobs. Expired lease recovery is bounded to five per invocation.
- Completed step checkpoints are immutable, with opaque storage-only output references. `yieldCheckpointedJob` releases the owned lease **only after a new successful checkpoint** and preserves the failure retry allowance for later cron ticks.
- On failure only redacted machine error codes persist; bounded exponential retry and circuit breaker protect providers. On success, paid jobs require an idempotent usage receipt and all checkpoints; cost receipts cannot exceed the job reservation or provider daily budget.
- `heartbeat` allows active lease owners to extend up to five minutes from claim, never resurrecting expired leases. Lease ownership is rechecked for every checkpoint, receipt, completion and failure.

## One checkpoint per cPanel invocation

- `BoundedAiWorker::tick(owner, handlers)` performs **at most one** registered checkpoint per call, then yields or completes. Missing handlers return `idle` with **no job claim**; exceptions become redacted failure/retry states; a lost lease is not overwritten.
- `AiStepHandlerRegistry` is application-singleton and contains only trusted, explicit PHP closures supplied by server code. Nothing in a DB row or incoming request can select a handler class. The handler signature is `fn (stdClass $job, string $stepKey, string $owner, DurableAiQueue $queue): ?string`. It may record actual usage and return an opaque durable result reference; it must bound any HTTP work and not log prompts or credentials.
- Laravel command `php artisan ai:tick` calls the bounded worker once and exits. The existing `schedule:run` registration runs each minute **only when handlers have been explicitly registered**, with overlap suppression. A fresh installation has no handlers and therefore makes no inference calls. Production hosting cron must separately invoke `php artisan schedule:run` as provisioned by the operator; the application does not install a cron daemon or pretend to have a running worker.
- Step `position` is persisted to maintain enqueue order. Migration deterministically orders legacy checkpoints by creation timestamp / UUID when the old schema lacked an ordinal. Original legacy insertion order cannot be recovered if those values tie; no false claim of perfect reconstruction.

## Acceptance boundary

Automated tests cover idempotency, locks, checkpoints, resume across ticks, ordered execution, no-handler fail-closed behavior, retries, budget receipts, circuit breaker, leases, redacted errors and the CLI guard. This is a tested **generic orchestration runtime**, not proof that T014 content handlers exist, cPanel cron is installed, a provider credential is usable, staging was deployed, or real inference completed.

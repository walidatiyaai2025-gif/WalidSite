# Live project completion percentage (owner-required reporting contract)

Whenever the owner asks "المشروع وصل كام؟", "نسبة اكتمال المشروع", "خلص كام %؟", "حالة وليد سايت", or equivalents, **lead with a numeric percentage followed by the percent sign**. The metric must be recalculated against **live GitHub**, not a chat snapshot, README claim, PR green badge, or old task register.

## Canonical denominator and numerator

- Scope is exactly the 27 baseline delivery tasks **T001–T027** defined in the v6 program at `tasks/master-task-register.json` and mapped to issues in `tasks/github-issue-map.json`. Do **not** count umbrella Issue #1, PRs, extra maintenance tasks, branches, CI jobs, or subtasks as separate baseline tasks.
- A task counts as complete only when its canonical Issue is **closed as completed**, its relevant implementation PR(s) are **merged into main**, and merge/CI evidence for the code is verifiable. For T001–T024, full engineering acceptance may close independently of staged deployment or owner acceptance, as specified in `docs/SETUP_AND_DELIVERY.md`. For T025–T027, require the actual external/release/owner-handoff evidence specified in those Issues; never mark them complete just because engineering is green. If evidence is inconsistent or unavailable, do not silently include it; explain the uncertainty.
- Formula: `completion_pct = round(100 * verified_completed_baseline_tasks / 27, 1)`. Re-scope the denominator only when the owner explicitly approves a changed baseline and it is recorded in GitHub.
- A green CI check, unmerged PR, completed code without Issue closure, or a deleted branch alone never makes the task complete.
- The percentage is a **verified baseline-task completion metric**, not an estimate of effort, production readiness, staging availability, or store release. Do not conflate these signals.
- When useful, report a **separate engineering progress indicator**: `verified completed tasks among T001–T024 / 24` plus T025–T027 external activation gate states; do not substitute this numerator or denominator for the canonical 27-task percentage. Owner UX testing is deferred until both products finish all programming. A T001–T024 Issue held open solely for external staging/owner testing should be assessed under the new engineering close-out policy and accurately closed **only** once full software scope, tests and merge are verified.

## Required Arabic response shape

**نسبة اكتمال وليد سايت: X.X%** — اكتملت N من 27 مهمة، ومتبقي M، حسب آخر تحقق من GitHub (date / main SHA).

Then include, as useful, finished/current/blocked task IDs, linked PRs, exact-head CI, verified merge SHA, staging/release blockers, and the next dependency-ready task. Make it concise; always put the percentage first. When there is no reliable GitHub access or verification, state clearly that the current percentage is **غير قابل للتحقق الآن** rather than inventing or reusing a stale number.

For other projects, apply the same evidence-based display convention but read that project's **own approved scope and task register**; never apply the WalidSite denominator of 27 to another repository.

# T022 — Bounded editorial publication, refresh and review

**Engineering implementation only.** Activation, hosting cron, staging smoke and
rollback must still be verified under T025. No background worker is running merely
because Laravel scheduler code is committed.

## Administrator workflow

An active owner/admin opens /admin/editorial/automation from the Content Review
studio. All forms require the authenticated web session and Laravel CSRF.
The settings persist in the editorial_policies table and are **disabled by default**.
Allowed settings: enabled (explicit opt-in), maximum due jobs per tick (1–20),
review refresh interval (1–365 days). Changes write redacted actor/event entries.
An article can be scheduled only after its **latest persisted revision** has
passed citation/technical validation, is classified risk_level=low, carries
auto_publish_approved=true, and explicitly has no professional claims.
A human admin confirms the review and a future due date; the article/revision
and reviewer are pinned in a database schedule, never just cache.

At execution these conditions are rechecked under transaction locks. A
superseded/revoked/high-risk article goes to requires_review, never public.
A successful, exactly-pinned revision becomes published with refresh_due_at.
After the configurable interval the scheduler unpublishes it and invalidates
the old citation/technical/auto-approval flags, requiring a fresh review.
The independent evidence/rights revocation audit always remains active on
its own cadence, even when new auto-publication is disabled.

High-risk or professional articles **cannot** use automatic publishing. They
need an explicit owner-only, reasoned manual decision via the protected admin
view, and the ordinary content studio rejects the high-risk shortcut.
Neither manual nor automatic decisions bypass evidence and image-rights gates.
Both leave redacted events (actor, revision, action and approved reason code),
not private article bodies, URLs, credentials or AI prompts.

## cPanel scheduler / operational acceptance

Only one host cron entry is needed, invoking PHP with the absolute artisan path
and schedule:run once each minute (or every ten minutes if matching the exact
audit and publishing cadence). Use a dedicated non-web user.

- editorial:audit-publications --limit=20 — every ten minutes; revokes
  invalid or withdrawn public evidence in rotating UUID pages.
- editorial:publish-due --limit=20 — every ten minutes; processes at most the
  smaller of the provided limit and admin max_per_tick pending publication
  schedules, plus at most the same number of stale refresh deadlines.

These commands are non-daemon, do not make remote AI requests, and use database
checkpoints plus an atomic cache lock and Laravel scheduler overlap guards.
Use a persistent cache store with working atomic locks (file on one cPanel host
or shared Redis/database for multiple hosts). Never use array cache in production.
A crashed tick leaves pending rows for idempotent retry; publication and event
recording occur together in database transactions, with unique event keys.

To verify staging **when actually available**:

1. Confirm migrations, schedule:list, writable cache, database backup and rollback.
2. Verify policy starts disabled. Schedule a reviewed low-risk article, execute
   one tick after its due time, and verify public API, pinned revision and event.
3. Disable policy; confirm pending drafts stay private. Test high-risk refusal,
   revocation, idempotent reruns, owner-only reasoned exception and refresh.
4. Test lock contention, safe logs, and rollback to the previous release.

CI tests Laravel/MariaDB, web and Flutter without real third-party credentials.
CI success is **not** proof of staging cron activation, paid-provider connectivity,
live CDN publication or 40-screen pixel-perfect fidelity.

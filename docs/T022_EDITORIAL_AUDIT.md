# T022 — bounded published-content verification (increment)

The Laravel command \`php artisan editorial:audit-publications --limit=20\` is a
**revocation-only** maintenance pass. It checks up to 20 (maximum 50) published
articles per invocation, reuses the domain's pinned-revision citation,
technical-review and image-rights checks, and unpublishes invalid content.
There is no automatic draft approval, research request, image generation,
outbound AI call, or long-running worker.

The code registers one Laravel scheduler entry **every ten minutes**, with an
overlap guard. It is inert until a hosting operator separately configures
Laravel \`schedule:run\` through a cPanel cron. A cron running every ten
minutes is sufficient for this audit; do not install a queue daemon or multiple
overlapping cron entries. Use the actual deployed PHP/artisan path and verify
with \`php artisan schedule:list\` and operational logs before enabling it.

The scan holds a short-lived cache lock and processes a fixed page sorted by
article UUID. A cache-backed cursor moves between pages, wrapping at the end;
the cursor changes only after the whole page succeeds, so a failed pass
rechecks safely. **Production must use one persistent, shared cache store**
(file on a single cPanel host or an appropriately configured Redis/database
backend), never the non-persistent array cache. With multiple hosts, use a
shared atomic-lock cache. Existing per-article revocation is transactional and
idempotent; no private article bodies, identities, or secret provider details
are printed by the command. Actual external uptime/staging checks, receipts
and rollback remain to be verified before closing T022.

The feature does not yet implement scheduled publication from persisted admin
policy, risk-approval event audit or a complete editorial refresh pipeline.
Do not report it as a deployed or fully completed automation.

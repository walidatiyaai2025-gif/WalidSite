# WalidSite — host-gated automatic code-only releases from private cPanel Cron

Issue [#1120](https://github.com/walidatiyaai2025-gif/WalidSite/issues/1120). This is a **code path, not evidence of a real activated hosting account**. Publishing signed releases, installing an authorized adapter, accepting a production domain and running physical Android/Web acceptance are separate gates. No operator should enable this from CI results alone.

## One-time host activation boundary

1. Use the complete signed Setup ZIP, complete HTTPS browser installer, protected private `HOME/application` and `HOME/public_html` layout. Keep `.env`, `APP_KEY`, uploads, databases and the original permanent `HOME/application/cron/release-poll.php` outside updated code.
2. Configure the **real, approved** publisher HTTPS origin and pinned Ed25519 *public* verifier in private settings. Never install private publisher signing keys on the web server or use a guessed example domain.
3. Only after actual host testing, register a trusted `CpanelOwnerCodeUpdateHost` in the server-side Laravel service container. It must independently prove that all **foreign**/direct SQL/other Cron writers are stopped, return a separate empty disposable MariaDB restore database, and perform a fresh external TLS-verified `/up` probe that binds release SemVer, Git SHA and maintenance token. The source repository deliberately **does not** ship a fake default adapter or accept an environment boolean as external-writer proof.
4. The one permanent private PHP CLI Cron runs every 15 minutes (`*/15 * * * *`). One-time Cron GUI activation or authorized scoped cPanel API may be required. There are no recurring uploads, Composer, npm or artisan commands for routine verified code-only updates.
5. Only after independently testing the complete system on that real host, set `SITE_RELEASE_CRON_CHECK_ENABLED=true`, then independently `SITE_RELEASE_CRON_APPLY_ENABLED=true`. Both default to `false`. The apply switch does **not** register a host adapter and cannot bypass missing publisher identity, authorizations, backup or health checks.

## Missing initial publisher trust: actionable, non-authorizing Cron status

When the already-installed private Cron launcher runs but its initial
`SITE_TRUSTED_INSTALLED_VERSION`, publisher HTTPS origin or verifier public key
is absent, it now records an **owner-only, 0600, locked, observational**
`BLOCKED` receipt in the existing private release-automation directory.
Admin → System Updates can show one fixed, non-secret reason:
`TRUSTED_INSTALLED_VERSION_MISSING`,
`INSTALLED_RUNTIME_IDENTITY_UNVERIFIED`,
`TRUSTED_RELEASE_ORIGIN_MISSING`,
`TRUSTED_RELEASE_PUBLIC_KEY_MISSING` or `RELEASE_MAINTENANCE_ACTIVE`.
An observed local version is **not** a trusted signed-version pin.
The receipt contains no origin, signing key, token, source SHA, downloadable
release or secret. It cannot trigger any code update, and the same lock and
symlink-safe atomic writer prevent a stale signed-state receipt being
misrepresented after preflight failure. Earlier first-bootstrap physical
inaccessibility still fails closed with generic CLI `BLOCKED`.

Never “fix” this by inventing a trusted version from an unsigned cPanel
overlay, copying an ephemeral test signer or enabling apply. Attest the
running installation against the actual trusted source/build and original
installer provenance first, then configure real publisher trust out of band
and separately test the host adapter and recovery.

## Explicit server-disabled states (diagnostics only)

If `SITE_UPDATES_ENABLED=false` or `SITE_RELEASE_CRON_CHECK_ENABLED=false`,
the installed private CLI Cron continues to **refuse network discovery and
code installation**. Once a valid installed runtime can bootstrap, it may
record a locked, 0600, owner-only `SYSTEM_UPDATES_DISABLED` or
`SCHEDULED_POLL_DISABLED` preflight receipt with no release version/SHA and
`code_applied=false`. This receipt is a local observation, **not** evidence
of publisher authenticity, current live-host health or permission to enable
`SITE_RELEASE_CRON_APPLY_ENABLED`. Missing/unsafe first-install roots still
fail closed without a receipt.

## Exact state machine

`Cron private launcher → live version/pointer lock → Ed25519 signed catalog check → no new version = UP_TO_DATE → newer signed version = BLOCKED until verified host apply gate → recheck same version + SHA + signature → private bounded HTTPS ZIP download (no redirects or private DNS) → file-descriptor SHA256 + safe ZIP inspection → strict code-only composer/migration compatibility → private staging and full candidate re-attestation → exclusive writer lease → independent MariaDB+files backup and restore drill → maintenance and immutable pointer → external TLS health → commit → private owner history + verified Cron receipt = DEPLOYED`.

- A changed or unsigned publisher catalog, inconsistent second catalog, missing or unsafe ZIP, incompatible dependencies or migrations, foreign writers, missing disposable restore database, insufficient disk, active update, failed maintenance or failed HTTPS probe all abort. No arbitrary URLs from web/owner input can reach the CLI updater.
- Existing exact signed immutable ZIP is revalidated on retry; existing versioned code is attested byte-for-byte, including absence of extra PHP, before proceeding. No in-place overwrite of `.env`, uploads, sessions or customer data.
- The shared Cron writer lease is upgraded to exclusive by the **existing** cPanel owner code install coordinator. It releases the descriptor after success/failure. No second lock or user browser password is used by the trusted private CLI.
- The only supported automatic path is **code-only**: changed Composer dependencies or migration inventory fail closed. This does not silently run irreversible DB migrations or assume DB rollback.
- The updater first produces a read-only `BLOCKED` observation and only changes it to `DEPLOYED` after the existing installer returns `installed_verified`, local install history records successfully, and the *actual* active private code pointer matches the signed discovered version and SHA. The 0600 JSON is observational and grants no update authority. Owner Admin shows `UP_TO_DATE`, `BLOCKED` or `DEPLOYED` distinctly; an old status becomes `UNVERIFIED`.
- A failure *after* the code cutover may leave the site healthy at the new version but without a fresh `DEPLOYED` status if the private history/status could not be durably recorded. Never pretend that is a rolled-back deployment; inspect the physical pointer and signed backup history.
- The permanent launcher itself is protected from replacement by subsequent versioned ZIP code updates. Existing `CpanelCronReleaseSelector` chooses verified active Laravel code, so the initial one-time Cron entry continues across chained releases.

## CI and real acceptance

Unit tests prove no host capability or writer lease can initiate network/download, external-writer refusal, signed second-catalog version/source binding, forged signatures, replayed status and physical pointer mismatch. Existing disposable real MariaDB/actual Setup+Update ZIP harness proves the underlying code-only restore/cutover/rollback coordinator. Those are **CI simulations**, not a statement that the actual hosting adapter, publisher production Ed25519 signing, scheduled job, backup location, restored live MariaDB, TLS route or production Android signer have been verified.

The remaining release gate requires a production signed GitHub Release and manifest from one green exact main SHA, approved real host adapter and private Cron activation, failover/recovery rehearsal on that host, a production-distribution-signed site-bound Android APK, real binary download and owner physical-device Web↔APK acceptance. Keep #1120, #732 and #26–#28 open until genuine evidence exists.

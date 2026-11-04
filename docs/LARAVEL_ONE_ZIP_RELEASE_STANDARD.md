# Laravel — Universal one-ZIP install and signed admin-update standard

**Owner directive (2026-11-04), binding for WalidSite and reusable as the default delivery contract for future Laravel repositories.** Build all artifacts from the same exact, green, immutable source commit. This does not waive the existing release freeze, owner-approved screens, original source licenses, tenant security, or the real HTTPS/hosting gates in Issue #732.

## End-user contract

- **First install:** `PROJECT-Setup-vX.Y.Z.zip` → cPanel account **home** → one Extract → `https://domain/setup` → Next/Next/Finish → public site + owner admin. Never ask the owner to run Composer, Artisan, npm, Git, SSH, or edit `.env` on the hosting account.
- **Required host prerequisites:** supported PHP and extensions, functioning HTTPS, safe private storage, writable cache/storage, existing least-privilege MySQL/MariaDB database/user supplied from the hosting control panel if the application cannot provision them. The supported Document Root must be explicit; do not unzip private application code into a public directory.
- **Setup wizard:** encrypted session protected by an unguessable **private one-time ownership token**, PHP/extension/permission/HTTPS and DB preflight, canonical HTTPS origin, atomic private `.env` and APP_KEY, trusted migrations/seeders, admin activation, storage/cache configuration, mail/queue/scheduler capability checks, final health and immutable installed-version receipt. Any unsupported automated cron registration must be reported as a **manual hosting-panel** prerequisite, not claimed as completed. All seed content must map to working, access-controlled CMS screens; never fabricate biography, portrait, project records, client metrics or third-party credentials.
- **Installer shutdown:** permanently deny `/setup` once the owner or install lock exists, including interrupted-install and missing-lock recovery cases. Preserve original APP_KEY, seed content, private uploads and DB data on every retry.

## Updates after installation

The target owner-only **Settings → System → System Updates** experience includes version, latest trusted version, upload signed update ZIP, verified publisher identity, SHA-256, compatibility, change log, full backup, progress, health and recovery receipts.

Update artifact: `PROJECT-Update-vX.Y.Z.zip`, with an independently authenticated **Ed25519 publisher signature** over immutable metadata: product identity, installed/target SemVer (including minimum supported version), exact Git SHA, signed ZIP hash/length, required PHP/extensions, migration compatibility and release notes. SHA-256 alone does **not** authenticate the author. The upgrade must reject arbitrary or user-edited PHP and all untrusted URL, symlink, ZIP Slip, path traversal, downgrade, unsupported dependency or schema changes. A browser upload is **not** approval of the source; the upload must be matched against the explicitly pinned publisher trust anchor and release inventory before staging.

Owner-only apply needs CSRF, fresh reauthentication for privileged code operations, rate limits, exclusive update lock, audited state transitions, real write/queue quiescence, private staging, verified recoverable DB+file backup and independent restore drill, atomic code switch where supported, trusted migrations, cache/config rebuild, health test and safe rollback/forward recovery. Preserve .env, encryption keys, uploads, user tables, sessions and secrets. On a host where atomic switching or independent verified DB recovery is unavailable, the UI must show **BLOCKED HOSTING** and refuse Apply; it must not copy code over active files or claim rollback.

The existence of a signed artifact or migrated schema alone never proves a working end-to-end update.

## Build and acceptance

All canonical source releases use `MAJOR.MINOR.PATCH`; immutable source SHA and `VERSION` must match the package Manifest and artifact names. A production-approved release should include:

1. One install ZIP with compiled web assets and complete production Composer vendor, plus its independent checksum.
2. One supported upgrade ZIP, publisher-signed metadata/catalog and release notes.
3. Versioned manifest with product, source SHA, minimum requirements, migrations inventory, package bytes/checksum and signed provenance.
4. CI evidence on exact head: Laravel tests, security, one ZIP fresh-install HTTP/DB, owner login, public+admin flows, signed real update preserving private files and DB, failure/recovery and checksum/integrity checks.
5. Real HTTPS staging receipt, hostname/document root and private storage checks, cron/queue verification, version/SHA, rollback proof and owner acceptance.

**No fake readiness:** if any gate fails or requires unavailable hosting/credentials, report that specific gate as open. A passed unit test, signed catalog, green packager, PR merge or ZIP file cannot by itself close the release.

## WalidSite implementation status (2026-11-04)

- Setup: existing cPanel one-upload layout and token-authorized `/setup` with Laravel+vendor; this branch adds actual PHP HTTP missing-key regression and SemVer-named first-install package.
- Update: publisher-signed discovery, quarantine, ZIP structural checks and isolated backend backup/cutover tests exist; the Admin panel's existing Apply performs **database migrations only**, not safe complete code upgrades. A generic uploaded PHP ZIP remains forbidden.
- **Open blockers:** installer end-to-end proof on the owner's cPanel/MariaDB+HTTPS, fully authorized admin code update and independent restorable DB backup/rollback, hosting-compatible atomic switch (one-extract layout currently physical directories), reproducible signed publication, live scheduler/queue/staging receipt and owner acceptance. Issue #732 remains open.

This standard governs implementation choices now. No incomplete step should be visually represented as a working deploy/update control.

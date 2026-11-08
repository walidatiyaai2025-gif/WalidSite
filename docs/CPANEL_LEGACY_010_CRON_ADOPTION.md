# Existing cPanel WalidSite 0.1.0 → Trusted Release Cron adoption

Issue #1120. **No automatic update or Cron activation is authorized by this document.**

## Physically observed installation, 2026-11-08

- Domain: `https://walidatiya.com`. cPanel main domain Document Root: `/home/walidatiya/public_html`.
- Private Laravel installation: `/home/walidatiya/application`, with `.env`, `vendor`, `bootstrap`, `storage`, `VERSION` and an installed lock at `storage/app/site-installed.lock`.
- Owner reported `0.1.0` and `2026-11-04T09:02:48+00:00` for the installed VERSION identity. **The exact raw bytes need independent read-only confirmation**; these may represent two lines, or the timestamp may be UI metadata. The new immutable version reader trusts **only one SemVer** in the file and will reject a two-line legacy format.
- The live first-install application root does not visibly contain `cron/release-poll.php`. Do not assume old Laravel vendor autoload contains the new deployment selector/lease classes.

## Safe implementation boundary

1. A separate read-only probe `qa/legacy_version_preflight.py` classifies a canonical one-line SemVer or the known legacy `0.1.0` + UTC timestamp. It does **not** change any file, authenticate any release, grant installation permission or weaken `TrustedInstalledVersion`.
2. Obtain verified installed file inventory, currently active build SHA and existing updater capabilities, PHP CLI version/extensions, private DNS/TLS origin, real host adapter and actual separate restore-capable MySQL backup. Do **not** paste `.env` secrets or lock contents into issues/chats.
3. Build and test a backward-compatible, signed **one-time legacy adoption** package against the actual installed 0.1.0 code under an isolated clone of cPanel and MariaDB, including existing site content, DB, uploads and permissions. Never overwrite `.env`, `storage`, `APP_KEY`, the owner lock or site Document Root wholesale.
4. Before deployment, reproduce fail cases: mismatched signing identity, incompatibly formatted VERSION, missing legacy update APIs, insufficient backup/restore, concurrent writers, invalid ZIP, untrusted origin, failed HTTPS health and recovery. A staged normalized VERSION is not a replacement for publisher proof or live installed SHA.
5. Only after successful adoption, verify a permanent private `/home/walidatiya/application/cron/release-poll.php` that boots the same verified code lineage and test **check-only** first (apply OFF), then set `*/15 * * * *` with a confirmed server CLI PHP path using cPanel GUI or authorized scoped API. Require signed publisher catalogue `<approved-origin>/releases/latest.json`; do not fill in `WALIDSITE_TRUSTED_RELEASE_ORIGIN` with `https://github.com` or a guessed hostname.
6. Enable apply only after real host writer shutdown/DB restore/independent HTTPS probe are attested. No unverified claim of automatic production deployment.

Keep the existing two workflows, #1120 umbrella, and #1131 integration lane. The owner is not required to run Composer, npm or artisan for routine updates.

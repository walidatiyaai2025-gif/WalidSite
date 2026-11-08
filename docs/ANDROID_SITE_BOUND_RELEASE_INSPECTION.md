# WalidSite signed Android / site-origin Release inspection — Issue #1120

This engineering lane produces an **unpublished inspection artifact**. It does **not** create a GitHub Release, claim a live deployed website, assert APK runtime network compatibility, or expose a latest-APK link. It must remain blocked until all #1120/#732/#26/#27/#28 acceptance gates pass.

## Required GitHub configuration, presence only

In **GitHub → Settings → Secrets and variables → Actions**, configure:

| Kind | Name | Purpose |
|---|---|---|
| Variable | `WALIDSITE_PRODUCTION_SITE_ORIGIN` | Owner-approved **canonical HTTPS origin**, such as `https://real-approved-domain.tld` (no path, slash, port, query or fragment). No domain is guessed by code. |
| Variable | `WALIDSITE_ANDROID_SIGNER_SHA256` | SHA-256 of the independently approved Android release **signing certificate**, 64 hexadecimal characters, not a keystore password. |
| Secret | `WALIDSITE_ANDROID_KEYSTORE_BASE64` | Base64 bytes of the approved production Android keystore. |
| Secret | `WALIDSITE_ANDROID_KEY_ALIAS` | Alias inside that keystore. |
| Secret | `WALIDSITE_ANDROID_STORE_PASSWORD` | Keystore password. |
| Secret | `WALIDSITE_ANDROID_KEY_PASSWORD` | Signing key password. |

The workflow uses GitHub's scoped `GITHUB_TOKEN` for its own read-only checkout. **Do not add a PAT** for the public repository. The separate manual Web inspection requires real publisher Ed25519 secret/public verifier and trusted release HTTPS origin (see [trusted update publication](TRUSTED_UPDATE_PUBLICATION.md)). Real host promotion remains separate. Private cPanel Cron activation, a host-specific verified code-switch adapter, an independent real backup/restore and device/owner acceptance remain **separate missing capabilities**; this job does not invent or substitute them.

## Execution and strict trust boundary

The **existing** Release workflow (`.github/workflows/release.yml`) automatically builds only unsigned inspection candidates after a green exact-main push CI. The `signed-android-inspection` and separate publisher-signed Web inspection run **only** through manual `workflow_dispatch` on the current main, after green exact-SHA CI. The `signed-android-inspection` job:

1. Rejects absent secrets, an unapproved/noncanonical/non-HTTPS/local origin, a wrong SemVer or differing checked-in `backend/VERSION`, an unexpected Git head, and an unapproved Android package.
2. Decodes signing material to a disposable private file; never writes signing keys into Git, build artifacts, public assets or release ZIPs.
3. Compiles `flutter build apk --release --dart-define=WALIDSITE_API_ORIGIN=<approved-origin>` using the approved keystore. Gradle refuses release tasks without complete signing inputs.
4. Verifies **actual APK bytes** with `qa/native_android_acceptance.py`, `aapt`, `apksigner`, expected package ID/version/code, INTERNET permission policy and expected signing certificate fingerprint. A mismatch is fatal.
5. Emits only `WalidSite-SIGNED-UNPUBLISHED-Android-<version>-<sha>` as a 14-day Actions inspection artifact, including a SHA-256 and a redacted JSON inspection receipt. `production_ready`, `published`, `live_https_verified` and `apk_runtime_origin_verified` are always `false`.

The fingerprint configuration is an **independent trust prerequisite**. Verifying it against itself is not proof of owner approval or live deployment; validate it out of band before production signing.

**Important:** The current `release-gate` still intentionally fails until the full signed Web publication, private cPanel HTTPS Cron poll/activation, installer/updater recovery, live pairing receipt and Android installation/API/device evidence exist. A successful signature check or a green candidate job is NOT production acceptance.

## Acceptance still open

- Signed, authenticated Setup and Update ZIP promotion from the same exact green main SHA, actual safe first install and supported upgrade.
- Private `*/15` cPanel HTTPS Cron pull with publisher Ed25519 pinning, exclusive update lock, backup/restore, host and health receipts.
- Independently observed exact deployed Web SHA and canonical origin, plus Android runtime API calls to that origin, device install/launch and compatibility smoke.
- Stable website/Admin last-**verified** APK download link backed by an immutable GitHub Release asset, no stale/downgraded fallback.
- One consolidated owner acceptance on real HTTPS website and installed production-signed Android app.

No owner Terminal/Composer/Artisan intervention should be requested for normal installs or updates. If first Cron creation cannot be authorized via host API, require one explicitly documented cPanel GUI activation instead of pretending the permissions exist.

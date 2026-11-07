# WalidSite — latest verified Android APK (Issue #1120)

The permanent public endpoints are **GET /downloads/android/latest** (binary) and **GET /downloads/android/latest/metadata** (public verification fields). Admin → System Updates shows a download action only when the current private APK and signed Web↔APK release receipt are valid. Missing/untrusted artifacts yield **503** and never expose an expiring GitHub Actions debug APK as production.

**No production signer, GitHub Release, cPanel host, live TLS, or device acceptance is claimed by this read-only implementation.** Only a separately authorized and independently verified publisher may install signed private evidence and enable the feature.

## Required private host data (provided by authorized publisher, NOT by a browser request)

- Actual currently running code: HOME/.walidsite-releases/vMAJOR.MINOR.PATCH-GITSHA/application, validated against the existing 0600 private HOME/.walidsite-active-release.json and immutable original first-install version. An unversioned first install has no independently verifiable Web Git SHA and therefore cannot enable the download.
- Private directory HOME/application/storage/app/private/android-release/ with mode 0700, outside public_html, never symlinked.
- Private immutable APK WalidSite-Android-vX.Y.Z.apk, mode 0600, exact native bytes previously checked by trusted Android production aapt/apksigner/certificate pin and live website integration.
- Private 0600 latest-pairing.json, JSON envelope with exactly two ordered keys: payload and signature. Both are canonical base64; signature is Ed25519 detached over the exact UTF-8 payload bytes. The Web server contains ONLY the publisher's public verifier key.

The canonical signed payload has exactly these fields in this order (illustrative values ARE NOT production evidence):

{
  "schema": "walidsite.android-live-pairing.v1",
  "product": "walidsite",
  "web_version": "1.2.4",
  "web_source_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "api_origin": "https://approved-production-domain.invalid.example",
  "apk_version": "1.2.4",
  "apk_source_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "apk_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "apk_size": 12345678,
  "apk_signer_sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
  "apk_file": "WalidSite-Android-v1.2.4.apk",
  "verified_at": 1794030000,
  "release_notes": "Example only — not approved for deployment",
  "live_https_verified": true,
  "apk_signature_verified": true,
  "web_apk_compatible": true,
  "production_ready": true
}

**Do not sign or deploy that example**: values and true booleans require independently documented post-deployment proof. Production publisher Ed25519 private key, Android keystore and GitHub credentials must never be shipped in source/ZIP or printed to user logs.

Server-private settings, all unavailable/disabled by default:

- APP_URL: actual approved bare HTTPS website origin (not the request Host).
- SITE_TRUSTED_INSTALLED_VERSION: original first-install SemVer pin.
- SITE_TRUSTED_RELEASE_PUBLIC_KEY: approved Ed25519 public verifier.
- SITE_ANDROID_TRUSTED_SIGNER_SHA256: independently approved lowercase production Android certificate fingerprint.
- SITE_ANDROID_DOWNLOAD_ENABLED=true only after verified private APK and receipt have actually been installed by the authorized host process.

## Fail-closed serving behavior

The resolver matches signed app/Web version, exact currently active immutable Web Git SHA and approved HTTPS API origin, with an independently pinned production APK signer certificate fingerprint. Host header, TLS and port are checked; HTTP, unrelated hosts, stale Web after rollback, first installs lacking a Git SHA, maintenance markers and damaged private pointers fail. Private metadata/archive paths reject symlink, hardlink, group/world writable modes, fake names and APK tampering. It hashes the **held APK inode** and checks APK ZIP structure before streaming from that same descriptor; no user-supplied redirect URLs or tokenized download URLs are supported. Metadata exposes only nonsensitive attested fields, and responses use no-store cache headers.

The signed booleans assert publisher acceptance; the Web endpoint does **not independently run apksigner or probe external HTTPS**. Actual publisher signing, immutable asset promotion, private cPanel transfer, true live Web↔APK acceptance and a real owner Android device test remain external work in #1120. Never close #1120/#732/#26–#28 on the basis of mocked/CI-only pairing tests.

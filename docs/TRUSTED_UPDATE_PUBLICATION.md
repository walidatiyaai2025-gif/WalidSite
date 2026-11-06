# #27 / #732 — Publisher-authenticated complete-code update package

This implements the publisher build boundary, not Admin update activation. It reuses the checked-in cPanel package and its SHA256 manifest rather than an independent unreviewed build.

## Immutable package and signature

The setup package is now named `WalidSite-Setup-vX.Y.Z.zip` (replace X.Y.Z with the exact signed-release version). A code-only update is a separate independently signed artifact, never an arbitrary ZIP accepted from a browser. Admin now exposes an owner-authenticated signed ZIP check/download/stage/install path; installation is **fail-closed unless a trusted host adapter independently proves writer isolation, compatible code-only layout, verified separate MariaDB/file recovery and live HTTPS health**. The generic migrations-only Apply path does not replace application code, and changed Composer dependencies or migrations are unsupported by the code-only switch.

1. Build the real WalidSite-Setup-vX.Y.Z.zip on a trusted, exact-source-SHA CI runner with production Composer vendor/assets using the existing packaging job. Record source SHA, ZIP SHA256 and PACKAGE-MANIFEST.json.
2. On an isolated publisher runner derive the code-only ZIP, replacing VERSION and SHA with the reviewed stable SemVer and exact 40-hex Git commit:

~~~sh
python3 -m qa.build_trusted_update_release \
  --cpanel-zip /trusted/WalidSite-Setup-vX.Y.Z.zip \
  --output-dir /private/releases --version "$VERSION" --sha "$SHA" \
  > /private/unsigned-build-receipt.json
~~~

This output reports observed artifact file/hash/bytes and publisher_signature_verified=false. The resulting ZIP is named WalidSite-update-vVERSION.zip. Verify compatibility with the PHP TrustedReleaseArchive on a disposable host before publication.

3. Derive the seven mandatory publisher payload fields, omitting local-only receipt attributes. An approved publisher may additionally provide a **plain UTF-8 `release_notes` string** (1–2048 bytes, LF line breaks permitted, no control characters or bidi direction overrides) in this *same signed payload*. The publisher and recipient both reject extra unknown fields and malformed notes:

~~~sh
python3 - /private/unsigned-build-receipt.json > /private/unsigned-payload.json <<'PY'
import json, os, sys
from pathlib import Path
with open(sys.argv[1], encoding='utf-8') as f:
    receipt = json.load(f)
if receipt.get('update_artifact_prepared') is not True or receipt.get('publisher_signature_verified') is not False:
    raise SystemExit('No verified release build')
fields = ('product', 'version', 'source_sha', 'artifact_sha256',
          'artifact_size', 'min_php', 'artifact_file')
payload = {name: receipt[name] for name in fields}
notes_file = os.environ.get('WALIDSITE_RELEASE_NOTES_FILE')
if notes_file:
    # Only read an approved private publisher-side notes document.
    # No release notes are created from untrusted HTTP or admin form data.
    payload['release_notes'] = Path(notes_file).read_text(encoding='utf-8')
print(json.dumps(payload, ensure_ascii=False, separators=(',', ':')))
PY
~~~

4. Only in an approved publisher environment, inject the true publisher Ed25519 secret as private environment variable WALIDSITE_PUBLISHER_SECRET_KEY_B64 (64-byte sodium secret encoded in base64). Do not put it in the repository, CI logs, CLI arguments, issue comments or the installed application. Execute:

~~~sh
php qa/sign_trusted_update_release.php /private/releases \
  < /private/unsigned-payload.json
~~~

The PHP script rechecks artifact SHA256, size, identity, version and optional release-note bounds before creating latest.json with exclusive creation and mode 0600. It rejects invalid secrets and refuses to overwrite an existing catalog. The envelope contains the exact base64 payload and detached Ed25519 signature expected by PHP TrustedReleaseMetadata. Admin displays notes **only after signature verification**, escaped as text; if the publisher signed no notes, it shows a truthful absence message. Notes do not authorize installation. Install only the matching public key on the application.

5. Do not publish automatically. A separate authorized publisher must verify the signed catalog using the approved public key and atomically publish the pinned artifact and latest.json to the trusted HTTPS origin. The app derives paths only from /releases/latest.json and /releases/WalidSite-update-vVERSION.zip. An Actions checksum is integrity evidence, NOT publisher authenticity.

## Security and acceptance boundary

- The code-only ZIP omits .env*, storage, user uploads, install token/lock and private runtime state, while retaining PHP, vendor, public assets, contracts and .htaccess. Backups, user data and APP_KEY must be preserved separately by the installer/updater.
- Run Python unittest qa.tests.test_visual_trusted_update_publication and PHP lint, plus the original cPanel builder tests. Fixture ephemeral signing keys are not release identities.
- The versioned private code-pointer implementation provides a guarded code-only path on compatible first-installed physical cPanel layouts and supports chained signed releases. There is intentionally **no default host adapter**: deployments must independently attest all external writers stopped, disposable MariaDB restore isolation, verified backup and real HTTPS health. Changed migrations, Composer runtime dependencies and unsafe web entrypoints are blocked rather than silently applied.
- Still missing for production acceptance: an owner-authorized physical cPanel HTTPS first-install and signed Admin update, real publisher origin/public-key approval, independent host writer shutdown, externally attested backup/restore, and final staged health/device/visual proof. Synthetic CI signing keys and this document are NOT production acceptance.
- Issue #27 / #732 remain open until real end-to-end release acceptance. Do not create a third workflow; promotion requires existing authorized release gates.

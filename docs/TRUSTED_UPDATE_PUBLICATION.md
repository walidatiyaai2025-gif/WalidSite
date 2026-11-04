# #27 / #732 — Publisher-authenticated complete-code update package

This implements the publisher build boundary, not Admin update activation. It reuses the checked-in cPanel package and its SHA256 manifest rather than an independent unreviewed build.

## Immutable package and signature

The setup package is now named `WalidSite-Setup-vX.Y.Z.zip` (replace X.Y.Z with the exact signed-release version). A complete code update is a separate independently signed artifact, never an arbitrary ZIP accepted from a browser. The existing Admin Apply endpoint still handles migrations only; full code Apply remains blocked pending host-compatible atomic layout, verified backup/recovery, and owner-authorized runtime orchestration.

1. Build the real WalidSite-Setup-vX.Y.Z.zip on a trusted, exact-source-SHA CI runner with production Composer vendor/assets using the existing packaging job. Record source SHA, ZIP SHA256 and PACKAGE-MANIFEST.json.
2. On an isolated publisher runner derive the code-only ZIP, replacing VERSION and SHA with the reviewed stable SemVer and exact 40-hex Git commit:

~~~sh
python3 -m qa.build_trusted_update_release \
  --cpanel-zip /trusted/WalidSite-Setup-vX.Y.Z.zip \
  --output-dir /private/releases --version "$VERSION" --sha "$SHA" \
  > /private/unsigned-build-receipt.json
~~~

This output reports observed artifact file/hash/bytes and publisher_signature_verified=false. The resulting ZIP is named WalidSite-update-vVERSION.zip. Verify compatibility with the PHP TrustedReleaseArchive on a disposable host before publication.

3. Derive the exact seven-field publisher payload, omitting local-only receipt attributes:

~~~sh
python3 - /private/unsigned-build-receipt.json > /private/unsigned-payload.json <<'PY'
import json, sys
with open(sys.argv[1], encoding='utf-8') as f:
    receipt = json.load(f)
if receipt.get('update_artifact_prepared') is not True or receipt.get('publisher_signature_verified') is not False:
    raise SystemExit('No verified release build')
fields = ('product', 'version', 'source_sha', 'artifact_sha256',
          'artifact_size', 'min_php', 'artifact_file')
print(json.dumps({name: receipt[name] for name in fields}, separators=(',', ':')))
PY
~~~

4. Only in an approved publisher environment, inject the true publisher Ed25519 secret as private environment variable WALIDSITE_PUBLISHER_SECRET_KEY_B64 (64-byte sodium secret encoded in base64). Do not put it in the repository, CI logs, CLI arguments, issue comments or the installed application. Execute:

~~~sh
php qa/sign_trusted_update_release.php /private/releases \
  < /private/unsigned-payload.json
~~~

The PHP script rechecks the artifact SHA256, size, identity and version before creating latest.json with exclusive creation and mode 0600. It rejects invalid secrets and refuses to overwrite an existing catalog. The envelope contains base64 payload and detached Ed25519 signature expected by PHP TrustedReleaseMetadata. Install only the matching public key on the application.

5. Do not publish automatically. A separate authorized publisher must verify the signed catalog using the approved public key and atomically publish the pinned artifact and latest.json to the trusted HTTPS origin. The app derives paths only from /releases/latest.json and /releases/WalidSite-update-vVERSION.zip. An Actions checksum is integrity evidence, NOT publisher authenticity.

## Security and acceptance boundary

- The code-only ZIP omits .env*, storage, user uploads, install token/lock and private runtime state, while retaining PHP, vendor, public assets, contracts and .htaccess. Backups, user data and APP_KEY must be preserved separately by the installer/updater.
- Run Python unittest qa.tests.test_visual_trusted_update_publication and PHP lint, plus the original cPanel builder tests. Fixture ephemeral signing keys are not release identities.
- CRITICAL OPEN BLOCKER: the standard cPanel one-extract ZIP creates physical application/ and public_html/ directories, while TrustedUpdateHostPreflight requires releases/current and public_html symlinks for atomic code replacement. Thus the first-installed layout currently fails closed on full code updates. W1/LEAD must implement and verify a safe transition or supported compatible initial layout. Do not remove this guard.
- Additional missing release evidence includes owner-authenticated CSRF/re-auth web apply, queue-write quiescence, DB rollback, actual publisher origin/keys, cPanel-host atomic code switch, recovery/restore and HTTPS staging smoke. This packaging/signing tool is NOT a successful Admin update or production release.
- Issue #27 / #732 remain open until real end-to-end release acceptance. Do not create a third workflow; promotion requires existing authorized release gates.

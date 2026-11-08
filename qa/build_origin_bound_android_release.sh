#!/usr/bin/env bash
# #1120: never publish from this inspection. Real host + owner/device acceptance
# and publisher-signed web release are separate gates.
set -euo pipefail

: "${WALIDSITE_PRODUCTION_SITE_ORIGIN:?missing approved production origin}"
: "${WALIDSITE_ANDROID_SIGNER_SHA256:?missing pinned Android cert fingerprint}"
: "${WALIDSITE_ANDROID_KEYSTORE_BASE64:?missing production keystore secret}"
: "${WALIDSITE_ANDROID_KEY_ALIAS:?missing production key alias}"
: "${WALIDSITE_ANDROID_STORE_PASSWORD:?missing production store password}"
: "${WALIDSITE_ANDROID_KEY_PASSWORD:?missing production key password}"
: "${WALIDSITE_SOURCE_SHA:?missing exact source SHA}"
: "${WALIDSITE_RELEASE_VERSION:?missing release version}"
test "${GITHUB_ACTIONS:-}" = "true" || { echo "GitHub Actions context required" >&2; exit 2; }
test "$(git rev-parse HEAD)" = "$WALIDSITE_SOURCE_SHA"
test "$(git ls-remote origin refs/heads/main | cut -f1)" = "$WALIDSITE_SOURCE_SHA"
test "$GITHUB_REF" = "refs/heads/main"
test -n "${ANDROID_HOME:-${ANDROID_SDK_ROOT:-}}"
# Accept both keytool's colon-delimited SHA256 and apksigner's 64-hex digest.
# Canonicalize once, fail closed on malformed delimiters or unexpected bytes.
WALIDSITE_ANDROID_SIGNER_SHA256="$(python3 -B -m qa.android_signer_fingerprint "$WALIDSITE_ANDROID_SIGNER_SHA256")"
export WALIDSITE_ANDROID_SIGNER_SHA256

mkdir -p dist/release-android
# This merely checks approved configuration. It is not a deployment/API proof.
python3 -B qa/release_site_origin.py \
  --origin "$WALIDSITE_PRODUCTION_SITE_ORIGIN" \
  --approved-origin "$WALIDSITE_PRODUCTION_SITE_ORIGIN" \
  --source-sha "$WALIDSITE_SOURCE_SHA" \
  --version "$WALIDSITE_RELEASE_VERSION" \
  --site-version-file backend/VERSION \
  > dist/release-android/site-origin-preflight.json

python3 -B - <<'PY'
import os
import re
from pathlib import Path
from qa.android_qa_build_metadata import metadata
source = metadata(Path('.'))
if (source['package_id'] != 'com.walidatiya.walidsite'
    or source['version_name'] != os.environ['WALIDSITE_RELEASE_VERSION']
    or source['placeholder_package_id']
    or not re.fullmatch(r'[a-fA-F0-9]{64}',
                      os.environ['WALIDSITE_ANDROID_SIGNER_SHA256'])):
    raise SystemExit('Release Android package/version or signer fingerprint not approved')
PY

private_dir="$(mktemp -d)"
cleanup() {
  rm -rf -- "$private_dir"
  unset WALIDSITE_ANDROID_KEYSTORE_PATH WALIDSITE_ANDROID_KEYSTORE_BASE64 \
    WALIDSITE_ANDROID_KEY_ALIAS WALIDSITE_ANDROID_STORE_PASSWORD \
    WALIDSITE_ANDROID_KEY_PASSWORD
}
trap cleanup EXIT
chmod 700 "$private_dir"
export WALIDSITE_ANDROID_KEYSTORE_PATH="$private_dir/release-signing.keystore"
python3 - <<'PY'
import base64
import os
from pathlib import Path
try:
    value = base64.b64decode(os.environ['WALIDSITE_ANDROID_KEYSTORE_BASE64'],
                             validate=True)
except (ValueError, base64.binascii.Error):
    raise SystemExit('Release signing keystore has invalid encoding')
if not 100 <= len(value) <= 1024 * 1024:
    raise SystemExit('Release signing keystore violates private byte limit')
destination = Path(os.environ['WALIDSITE_ANDROID_KEYSTORE_PATH'])
with destination.open('xb') as out:
    out.write(value)
destination.chmod(0o600)
PY
(cd mobile && flutter pub get)
source qa/gradle_distribution_preflight.sh
(cd mobile && with_gradle_distribution_retry flutter build apk --release --no-pub \
    --dart-define="WALIDSITE_API_ORIGIN=$WALIDSITE_PRODUCTION_SITE_ORIGIN")
apk="mobile/build/app/outputs/flutter-apk/app-release.apk"
test -s "$apk"
sdk_root="${ANDROID_HOME:-${ANDROID_SDK_ROOT}}"
aapt="$(find "$sdk_root/build-tools" -mindepth 2 -maxdepth 2 -type f -name aapt | sort -V | tail -1)"
apksigner="$(find "$sdk_root/build-tools" -mindepth 2 -maxdepth 2 -type f -name apksigner | sort -V | tail -1)"
test -x "$aapt" && test -x "$apksigner"

PYTHONPATH="$PWD" python3 -B - "$apk" "$apksigner" <<'PY'
import os
import re
import subprocess
import sys
apk, signer = sys.argv[1:]
result = subprocess.run(
    [signer, 'verify', '--verbose', '--print-certs', apk],
    capture_output=True, text=True, timeout=45, check=False)
# Neither stdout nor stderr of a failed signer command is printed.
if result.returncode:
    raise SystemExit('Release APK cryptographic signing validation failed')
found = re.findall(
    r'(?im)^Signer #1 certificate SHA-256 digest: ([a-fA-F0-9]{64})\s*$',
    result.stdout)
if (len(found) != 1 or found[0].lower()
        != os.environ['WALIDSITE_ANDROID_SIGNER_SHA256'].lower()
        or 'Signer #2 certificate' in result.stdout
        or re.search(r'(?im)^Signer #1 certificate DN:.*CN=Android Debug(?:,|$)',
                     result.stdout)):
    raise SystemExit('Release APK signer differs from pinned identity')
PY

# Bind the signer-inspected source bytes to later native and copied bytes.
export WALIDSITE_SIGNER_INSPECTED_APK_SHA256="$(sha256sum "$apk" | cut -d' ' -f1)"
python3 -B qa/android_qa_build_metadata.py > dist/release-android/native-source.json
python3 -B - <<'PY'
import json
from pathlib import Path
source = json.loads(Path('dist/release-android/native-source.json').read_text())
with Path('dist/release-android/native-build-env.txt').open('w') as f:
    for key in ('package_id', 'version_name', 'version_code'):
        f.write(f'{key}={source[key]}\n')
PY
# Avoid sourcing a file; read only an allowlisted trusted metadata schema.
package_id="$(python3 -c 'import json;print(json.load(open("dist/release-android/native-source.json"))["package_id"])')"
version_name="$(python3 -c 'import json;print(json.load(open("dist/release-android/native-source.json"))["version_name"])')"
version_code="$(python3 -c 'import json;print(json.load(open("dist/release-android/native-source.json"))["version_code"])')"
python3 -B qa/native_android_acceptance.py \
    --apk "$apk" --kind release-qa --package-id "$package_id" \
    --version-name "$version_name" --version-code "$version_code" \
    --source-sha "$WALIDSITE_SOURCE_SHA" \
    --aapt "$aapt" --apksigner "$apksigner" \
    > dist/release-android/native-archive-proof.json

cp -- "$apk" dist/release-android/WalidSite-Android-signed-UNPUBLISHED.apk
(cd dist/release-android && sha256sum WalidSite-Android-signed-UNPUBLISHED.apk \
  > WalidSite-Android-signed-UNPUBLISHED.apk.sha256)
python3 -B - <<'PY'
import hashlib
import json
import os
from pathlib import Path
dir = Path('dist/release-android')
preflight = json.loads((dir / 'site-origin-preflight.json').read_text())
native = json.loads((dir / 'native-archive-proof.json').read_text())
downloaded_apk = dir / 'WalidSite-Android-signed-UNPUBLISHED.apk'
hash_copy = hashlib.sha256()
with downloaded_apk.open('rb') as apk_stream:
    while chunk := apk_stream.read(1024 * 1024):
        hash_copy.update(chunk)
if (hash_copy.hexdigest() != native['apk_sha256']
        or hash_copy.hexdigest() != os.environ['WALIDSITE_SIGNER_INSPECTED_APK_SHA256']):
    raise SystemExit('Native and signer verification bytes differ from artifact')
if (not native['apk_integrity_verified']
    or not native['apk_signature_cryptographically_valid']
    or native['build_kind'] != 'release-qa'
    or native['debug_signature_verified']
    or native['production_ready']
    or not preflight['approved_origin_matches']
    or native['source_sha_supplied_not_attested'] != os.environ['WALIDSITE_SOURCE_SHA']):
    raise SystemExit('Signed candidate source/native acceptance is incomplete')
receipt = {
    'schema': 'walidsite.unpublished-signed-android-inspection.v1',
    'source_sha': os.environ['WALIDSITE_SOURCE_SHA'],
    'web_version': preflight['web_version'],
    'build_argument_origin': preflight['build_argument_origin'],
    'apk_package_id': native['package_id'],
    'apk_version': native['version_name'],
    'apk_version_code': native['version_code'],
    'apk_sha256': native['apk_sha256'],
    'apk_signer_fingerprint_sha256': os.environ['WALIDSITE_ANDROID_SIGNER_SHA256'].lower(),
    'configured_signer_fingerprint_matched': True,
    'compiled_native_manifest_verified': True,
    'native_signature_verified': True,
    'apk_runtime_origin_verified': False,
    'live_https_verified': False,
    'deployed_web_sha_verified': False,
    'publisher_signed_release_verified': False,
    'production_ready': False,
    'published': False,
}
(dir / 'inspection-receipt.json').write_text(json.dumps(receipt, sort_keys=True) + '\n')
PY
rm -f -- dist/release-android/native-build-env.txt
echo "Signed Android inspection accepted; production promotion remains BLOCKED"

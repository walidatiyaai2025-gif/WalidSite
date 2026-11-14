#!/usr/bin/env bash
set -euo pipefail
adb -s emulator-5554 get-state | grep -Fx device
sdk_root="$ANDROID_HOME"
test -n "$sdk_root"
aapt="$(find "$sdk_root/build-tools" -mindepth 2 -maxdepth 2 -type f -name aapt | sort -V | tail -1)"
apksigner="$(find "$sdk_root/build-tools" -mindepth 2 -maxdepth 2 -type f -name apksigner | sort -V | tail -1)"
test -x "$aapt" && test -x "$apksigner"
python3 qa/android_qa_build_metadata.py > dist/android-emulator/checked-source-identity.json
python3 - <<'PY'
import json
from pathlib import Path
data = json.loads(Path('dist/android-emulator/checked-source-identity.json').read_text())
assert data['debug_qa_only'] is True and data['distribution_ready'] is False
assert data['placeholder_package_id'] is False
PY
package_id="$(python3 -c "import json; print(json.load(open('dist/android-emulator/checked-source-identity.json'))['package_id'])")"
version_name="$(python3 -c "import json; print(json.load(open('dist/android-emulator/checked-source-identity.json'))['version_name'])")"
version_code="$(python3 -c "import json; print(json.load(open('dist/android-emulator/checked-source-identity.json'))['version_code'])")"
python3 -m qa.android_device_smoke \
  --apk dist/android-emulator/input/WalidSite-Android-debug-QA.apk \
  --package-id "$package_id" --version-name "$version_name" \
  --version-code "$version_code" --source-sha "$SOURCE_SHA" \
  --expected-sha256 "$(sha256sum dist/android-emulator/input/WalidSite-Android-debug-QA.apk | cut -d' ' -f1)" \
  --serial emulator-5554 --confirm-install \
  --aapt "$aapt" --apksigner "$apksigner" \
  > dist/android-emulator/emulator-launch.json
python3 - <<'PY'
import hashlib, json, os
from pathlib import Path
receipt = json.loads(Path('dist/android-emulator/emulator-launch.json').read_text())
identity = json.loads(Path('dist/android-emulator/checked-source-identity.json').read_text())
apk = Path('dist/android-emulator/input/WalidSite-Android-debug-QA.apk')
assert receipt['device_install_launch_verified'] is True
assert receipt['apk_sha256'] == hashlib.sha256(apk.read_bytes()).hexdigest()
for key in ('package_id', 'version_name', 'version_code'):
    assert receipt[key] == identity[key]
assert receipt['source_sha_supplied_not_attested'] == os.environ['SOURCE_SHA']
for key in ('replaced_existing_install', 'physical_device_type_verified',
            'visual_fidelity_verified', 'distribution_signing_identity_verified',
            'ios_build_verified', 'production_ready'):
    assert receipt[key] is False, key
PY

# Capture the real public Welcome screen from the SAME installed APK. This
# diagnostic must NOT certify visual acceptance, store signing or live hosting.
python3 -m qa.android_emulator_welcome_diagnostics \
  --source-sha "$SOURCE_SHA" --serial emulator-5554 \
  --output-dir "$PWD/dist/android-emulator" \
  > dist/android-emulator/welcome-diagnostic-run.json
python3 - <<'PY'
import hashlib, json, os
from pathlib import Path
root = Path("dist/android-emulator")
r = json.loads((root / "welcome-diagnostics.json").read_text())
live = root / r["runtime_capture"]
assert r["schema"] == "walidsite.mobile-01-emulator-visual-diagnostic.v1"
assert r["source_sha"] == os.environ["SOURCE_SHA"]
assert r["screen_id"] == "MOBILE-01" and r["route"] == "/welcome"
assert r["locale"] == "en" and r["device_kind"] == "disposable_android_emulator"
assert r["runtime_png_sha256"] == hashlib.sha256(live.read_bytes()).hexdigest()
assert r["mismatching_rgba_pixels"] <= r["total_pixels"]
assert r["total_pixels"] > 0
assert r["status"] in ("mismatch", "exact_pixels_unattested")
assert r["evidence_admitted"] is False and r["visual_fidelity_verified"] is False
assert r["physical_device_type_verified"] is False and r["production_ready"] is False
PY

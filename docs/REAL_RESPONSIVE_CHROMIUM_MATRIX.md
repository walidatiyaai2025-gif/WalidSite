# T024 / #481: real browser desktop, tablet and mobile checks

This is an executable accessibility/responsive **diagnostic**, not an image
parity certificate or a replacement for owner review. Every CI #481 branch and
every main push performs the following at a source-pinned clean checkout:

| Surface | Signed-in state | Screens | Languages | Viewports | Checks |
| --- | --- | ---: | ---: | ---: | ---: |
| Web public (static) | Real anonymous Chromium context | 12 | English and Arabic | 1440×900, 768×1024, 390×844 | 72 |
| Web public (published detail) | Real anonymous, publication-verified API + detail route | 0–2 | English and Arabic | Same three sizes | 0–12 |
| Admin | Locally authenticated, private file-only Playwright state | 13 | English and Arabic | 1440×900, 768×1024, 390×844 | 78 |
| **Combined** | **Both modes separately isolated** | **25–27** | **2** | **3** | **150–162** |

Each visit uses the **actual Laravel HTTP response** and current real DOM.
The checks require HTTP 200, canonical route and language direction, correct
landmarks/visible H1/known Admin screen heading, a keyboard-accessible skip link,
safe anchors, named interactive controls and no document horizontal overflow.
For Web-only overflow, the report may include the bounded privacy-redacted
structural hint from the previously merged #1109.

Security: Admin captures must use `0600` storage state in a `0700` private
directory; the public audit cannot load this state. Every Chromium context
blocks network requests outside the test origin. No page HTML, passwords,
cookies, arbitrary CSS names, API tokens or personal data may appear in the
diagnostic JSON. A check with defects is counted as a failure; a Chromium crash,
wrong route, missing locale or incomplete matrix fails the gate.

### Evidence format and gate

The **actual** reports are stored as CI artifacts under
`qa/visual-diagnostics/<sha>/admin-runtime-a11y.json` and
`qa/visual-diagnostics/<sha>-web/web-runtime-a11y.json`. Both contain
`tested_sha`, viewport dimensions and an exact list of individual
screen/locale/viewport diagnoses. GitHub Actions verifies every case and its
dimensions, not just the aggregate counts. The existing first-install CI
activation publishes approved project/README evidence, so WEB-05 must be
discovered through the **actual anonymous** `/api/v1/public/projects` API and
checked at the detail API before its six real HTML browser cases may run.
WEB-08 is included **only when** `/api/v1/public/articles` advertises a
currently published, rights-validated article that the detail API confirms.
Neither fake project slugs nor newly published QA articles are permitted.
Strict slugs, loopback-only discovery, bounded JSON and zero redirects are
required; URLs, titles and content never appear in the browser report. CI
checks `dynamic_web_verified`, `dynamic_web_pending` and exact row count
(78 or 84 Web if the verified seeded WEB-05 is present; 72 is a hard
diagnostic defect for the fresh-setup fixture).

The original `assets/screen-reference/*`, the 40-screen canonical contract,
`design/visual-fidelity-evidence.json` and the frozen owner-approved WEB-01,
WEB-03 and WEB-04 designs are unchanged. **Zero decoded-RGBA matches are NOT
implied by 150–162 accessible and responsive checks.** WEB-05 case-study
checks use the real approved setup fixture when available, but an absent
WEB-08 article remains explicitly pending rather than seeded with fake
claims. All 13 Flutter screens require a native runtime/device harness, and
real device breakpoints/contrast/assistive-technology acceptance remain
pending.

### Actual Android debug APK launch gate (CI, not a device screenshot)

The CI `build-android-qa-apk` job compiles a Debug-signed Android APK at
the exact checked-in main/PR SHA and verifies its manifest, signature and
bytes. The separate `android-emulator-install-launch` job now downloads
**those same artifact bytes**, independently verifies their SHA-256, native
identity and source-pinned receipt, starts a **hardware-accelerated Android 35
x86_64 emulator**, then uses `qa.android_device_smoke` to install the APK,
launch its actual Flutter MainActivity and require a running application
process. A failure blocks `verify-source-pinned-release-bundle` on main and
cannot be bypassed by an uploaded synthetic JSON file.

The successful, privacy-redacted receipt is retained under
`WalidSite-Android-emulator-launch-<commit-sha>`. This certifies a real APK
install and foreground app-process launch **only on the disposable CI
emulator**. It does not certify offline/online product journeys, an external
HTTPS backend, an Android or iOS physical device, native screen screenshots,
WCAG/device assistive technology, publisher identity, store distribution,
or strict RGBA parity with the immutable 13 mobile reference PNGs. The
`verify_release_android_qa.py` artifact-only receipt intentionally still
reports `device_launch_verified=false` because static APK inspection does
not run a device. The independent emulator receipt is explicitly checked by
CI instead of changing this evidence boundary.

Real device navigation and screenshots remain a distinct LEAD #481
acceptance: render all contracted screens at the original physical
dimensions and locale on an authorized handset or explicitly labeled
emulator; separately record exact-SHA screenshots, device serial
attestation (privately), DPR and zero-tolerance decoded RGBA comparisons.
Do not mark any visual ledger entry as `matched` from a successful app
launch alone.

### Repeat on a trusted local Laravel runtime

After provisioning the actual test database/server and a locally authenticated
Admin session via `qa/admin_visual_session.py` (not a checked-in cookie),
the two existing CLI commands run on the exact clean Git commit:

```sh
WALIDSITE_RENDER_BASE_URL=http://127.0.0.1:8000 \
  python3 -B qa/audit_web_runtime.py \
  --commit "$(git rev-parse HEAD)" \
  --output /tmp/walidsite-public-a11y-new.json

WALIDSITE_RENDER_BASE_URL=http://127.0.0.1:8000 \
WALIDSITE_CAPTURE_STORAGE_STATE=/path/to/private-0600-state.json \
  python3 -B qa/audit_admin_runtime.py \
  --provider-id "<genuine-local-qa-provider-uuid>" \
  --commit "$(git rev-parse HEAD)" \
  --output /tmp/walidsite-admin-a11y-new.json
```

Both output paths must not already exist. Run locally only on a throwaway QA
server, never against live production. The exit code is 0 for defect-free
checks, 1 for recorded nonfatal accessibility issues, and 2 for an incomplete,
unsafe or invalid check. Inspect defects before any design change and never
set the visual evidence ledger to `matched` from this report.

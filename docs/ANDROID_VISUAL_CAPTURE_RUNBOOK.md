# Real Android Flutter screen capture — Issue #481 (LEAD QA)

**Status: a reproducible capture adapter, not an accepted screenshot.** This
records the genuine pixels of the currently visible Android Flutter app, after
independently checking foreground package and localized accessibility heading.
It never synthesizes the v6 reference, draws a Flutter mockup, bypasses login,
or updates the visual ledger itself. Evidence is admitted only through
\`qa/capture_visual_evidence.py\` after exact decoded-RGBA comparison.

## Device and app prerequisites

1. Check out the exact source commit to be audited, without dirty source files.
   Build and install the **actual** Flutter app with matching repository code
   on a controlled Android emulator/device (no production credentials or PII).
   The current Android ID in \`mobile/android/app/build.gradle.kts\` is
   \`com.example.waleed_tech_companion\` (development only; not a release ID).
2. Use Android Platform Tools' \`adb\` and a **single explicitly identified**
   device serial; verify \`adb devices\` and \`adb -s SERIAL get-state\`. Launch
   the real app; navigate using the genuine Flutter UI to the desired screen.
   No caller-supplied text, faked ADB return, screenshots, app-package override
   or developer deep-link is considered proof of screen identity.
3. Select the **actual visible language** in the app itself. The renderer does
   not change language, impersonate a successful login, seed mock content or
   navigate past UI gates. Its accessibility header must match the requested
   screen/locale at the top of the viewport; otherwise it fails closed.
4. Configure the emulator resolution and DPR so actual \`screencap -p\` pixels
   equal the original PNG dimensions. The outer visual validator supplies the
   immutable physical dimensions and route; there is no resize or tolerance.
   The current Android UI may not match those originals: record differences,
   never mark acceptance without zero mismatching RGBA pixels.

## Verified heading-supported screens

The source-defined Flutter AppBar headings currently distinguish nine screens:

| Contract | Route | English visible title |
| --- | --- | --- |
| MOBILE-02 | /home | Waleed Tech Companion |
| MOBILE-03 | /tools/ai-troubleshooter | AI Troubleshooter |
| MOBILE-04 | /solutions/{id} | Solution detail |
| MOBILE-05 | /runbooks | Offline runbook library |
| MOBILE-07 | /articles | Technical articles |
| MOBILE-08 | /learn | Learning paths |
| MOBILE-09 | /learn/interview | Interview trainer |
| MOBILE-10 | /github | My GitHub |
| MOBILE-13 | /settings | Preferences |

Arabic titles are defined by the actual Flutter source and checked too.
**MOBILE-01, 06, 11, 12 are intentionally unsupported** by this adapter:
the current UI does not expose an independently unique static AppBar screen
identifier for their contract paths. A dynamic title, shared Saved/Profile tab
heading, or home card is not sufficient evidence; leave them \`pending\` until
W3 exposes a true screen-identification test hook or a separate verified
device integration runner exists. Do not use arbitrary target text to bypass it.

## Capture and pixel diagnostics

Create a local trusted plan **outside the repository**, e.g.:

\`\`\`json
{
  "version": 1,
  "captures": [
    {
      "screen_id": "MOBILE-03",
      "route": "/tools/ai-troubleshooter",
      "locale": "en",
      "dpr": 1,
      "renderer_command": ["python3", "qa/render_android_screenshot.py"],
      "timeout": 240
    }
  ]
}
\`\`\`

The physical dimensions are assigned from \`contracts/screens.json\` by the
outer validator, **not** from the plan or from ADB. Use the real device serial:

\`\`\`bash
export WALIDSITE_MOBILE_ADB_SERIAL=emulator-5554
sha="$(git rev-parse HEAD)"
python3 qa/visual_diagnostics.py --plan /private/qa/mobile-plan.json --commit "$sha"
\`\`\`

On an allowed route, the renderer runs \`adb -s SERIAL get-state\`, reads only
the current focused app from \`dumpsys window windows\`, checks Android
\`uiautomator dump /dev/tty\` for the exact localized AppBar text in the upper
fifth of the screen, and captures fresh native bytes with
\`adb -s SERIAL exec-out screencap -p\`. It validates PNG integrity and exact
source image size, then writes the fresh temporary PNG exclusively.
It neither logs Android XML containing user content nor stores private UI
metadata in source control.

A nonzero diagnostics result or inaccessible Android semantics must remain
**NOT VERIFIED**, even if the real app is running. Diagnostics are separate
from certification. Only after reproducing a real exact-pixel match may the
same plan be used for independent admission:

\`\`\`bash
python3 qa/capture_visual_batch.py --plan /private/qa/mobile-plan.json --commit "$sha"
python3 qa/validate_visual_fidelity.py
\`\`\`

An individual success does not make the 40-screen contract complete:
\`python3 qa/validate_visual_fidelity.py --require-complete\` must pass all 40.
Also test AR/EN, phone/tablet, screen readers, offline/empty/loading/consent,
and real-data privacy gates independently. No auto-approved screenshots,
invented route activation or production device proof is permitted.

## Limitations and security

- The app must already be showing the requested screen on a real Android
  device/emulator. This is a capture adapter, not an autonomous Flutter
  navigation integration test. Login/consent must be established lawfully
  through the running app.
- The accessibility hierarchy is checked at runtime, but Android/Flutter
  versions can expose accessibility differently. If the title is absent,
  reject capture, **do not disable the check**.
- Device screenshots or diagnostic artifacts may contain private information.
  Use nonpersonal QA data and controlled storage. Do not upload private user
  screenshots or device logs to a public PR/artifact. Reference images remain
  immutable, and the on-disk evidence ledger remains pending until genuine
  source-attested matching pixels exist.

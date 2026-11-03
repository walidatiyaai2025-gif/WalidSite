# Visual capture execution — Issue #481 (LEAD QA)

**Status: capture tooling only.** A successful CI or dry run does not certify a screenshot. Each original v6 reference stays immutable, and `design/visual-fidelity-evidence.json` keeps a screen at `pending` until a **real running** UI capture matches decoded RGBA pixels at the original dimensions with zero differences. The 14 public Web, 13 authenticated Admin and 13 Flutter screens need individual runtime evidence.

## Prepare real runtimes (never mock acceptance)

1. Use the checked-out target commit and install the pinned Pillow requirement already used by CI. For local web/Admin capturing, install Playwright and Chromium separately (`python3 -m pip install playwright` and `python3 -m playwright install chromium`). Start an actual local Laravel server with authorized data; never expose it to the public solely for screenshots.
2. Set `WALIDSITE_RENDER_BASE_URL=http://127.0.0.1:8000` for local Web/Admin, or a trusted HTTPS origin. `qa/render_web_screenshot.py` refuses remote HTTP, redirects and non-200 pages. It uses the exact native reference dimensions and locale/DPR furnished by the capture validator. For **both** `ar` and `en`, it appends the fixed `?lang=<locale>` query to the canonical route because the actual Laravel views select the language from that query (Chromium's browser locale alone is insufficient). The runtime HTML must then declare the same `lang` and `dir=rtl|ltr`, and late client-side redirects remain rejected before taking any screenshot. Neither a wrong-language capture nor a login/error page is accepted even if its renderer exits successfully.
3. For authenticated Admin only, provision a temporary **local, ignored** Playwright storage state outside the repository and export `WALIDSITE_CAPTURE_STORAGE_STATE`. Do not put tokens or storage state in commits, the capture plan, CLI arguments, screenshots or logs. An unauthenticated admin redirect must fail.
4. For Flutter, the plan must invoke a real device/emulator/integration-test capture command that writes its PNG to `WALIDSITE_CAPTURE_PATH` at `WALIDSITE_CAPTURE_WIDTH` x `WALIDSITE_CAPTURE_HEIGHT`. Flutter's canonical routes and image bounds are in `contracts/screens.json`. If a real device harness is unavailable, leave those entries `pending`; do not substitute a fixture, copied artwork or the preview PNG.

## Prove the renderer's exact checked-out source before capture

The supplied `--commit` is no longer accepted as proof by itself. **Both** the standalone screenshot command and the batch CLI verify `git rev-parse HEAD` equals the exact 40-character SHA **and** reject modified, staged or untracked source/renderer files (including app code, fixtures, contracts and reference assets). A standalone capture rechecks after rendering, before it can mark evidence as `matched`. A renderer that mutates source during execution fails and its candidate screenshot is removed.

Only two narrowly scoped types of local changes are permitted for a **resumable visual capture**: the already tracked `design/visual-fidelity-evidence.json` ledger and canonical `qa/visual-captures/{WEB-01..14,ADMIN-01..13,MOBILE-01..13}.png` result paths. These are still independently audited for strict pixel equality and provenance. Arbitrary screenshot filenames or extra local scripts are **not** accepted as evidence. Store local test plans and authenticated browser session files **outside** the Git checkout; ignored/generated dependencies are not independently certified by Git status. The source-checkout check complements, rather than replaces, genuine renderer/device origin, security, CI and decoded-RGBA evidence checks. The `--dry-run` batch also checks its source checkout.

If the check says the source checkout was changed, stop, review the changes and commit legitimate source edits through the normal PR process, then run against the **new** SHA. Do not use `--force`, fake a commit value or copy original reference PNGs to bypass it.

## Execute a checked, resumable plan

Create a **trusted local** JSON plan (not a checked-in credentials file). Example for two real public-page captures:

```json
{
  "version": 1,
  "captures": [
    {
      "screen_id": "WEB-01",
      "route": "/",
      "locale": "en",
      "dpr": 1,
      "timeout": 240,
      "renderer_command": ["python3", "qa/render_web_screenshot.py"]
    },
    {
      "screen_id": "WEB-02",
      "route": "/about",
      "locale": "en",
      "dpr": 1,
      "timeout": 240,
      "renderer_command": ["python3", "qa/render_web_screenshot.py"]
    }
  ]
}
```

```bash
sha="$(git rev-parse HEAD)"
python3 qa/capture_visual_batch.py --plan /tmp/walidsite-captures.json --commit "$sha" --dry-run
python3 qa/capture_visual_batch.py --plan /tmp/walidsite-captures.json --commit "$sha"
python3 qa/validate_visual_fidelity.py
```

The batch preflights **every entry** before executing commands, rejects duplicates, unknown screen IDs, wrong routes/locale/DPR, noninteger timeouts, and arguments referring to the original source images. It confirms `--commit` equals local Git HEAD. Screen captures are independent: already-verified screens are skipped only after the ledger passes a full audit; failed screens remain pending while the next screen may be attempted. A failure returns exit status 1. A malformed plan or invalid provenance returns 2. Dry-run prints `planned_only` and never captures or modifies evidence. The plan **executes programs**: do not accept one from an untrusted party.

For final coverage, explicitly include all 40 canonical IDs and specify `--require-all`. This checks **plan coverage**, not successful pixels. Then run:

```bash
python3 qa/capture_visual_batch.py --plan /tmp/walidsite-all-40.json --commit "$sha" --require-all
python3 qa/validate_visual_fidelity.py --require-complete
```

**Evidence identity is binding:** every `matched` record must point to `qa/visual-captures/<SCREEN-ID>.png` for its own screen ID, use the contracted route (including only a single safe segment for each `{slug}` or `{id}` placeholder), report a lowercase 40-character checked-out SHA, and use a DPR of 0.5–8 which divides both original PNG dimensions exactly. Encoded path escapes, control characters, cross-screen capture filenames and noncanonical routes are rejected even if a synthetic image happens to have matching pixels. This additional integrity check does **not** establish whether a capture came from a real UI; real renderer/device proof is still mandatory.

The second command is the definitive strict evidence gate. It fails with pending entries, invalid captured image provenance, or a single differing decoded RGBA pixel. Do not close #481 or T024 because the batch runner exists or its synthetic unit tests pass. Separate AR/EN, responsive width, empty/error, keyboard, accessibility and role/authorization QA remain required. Attach actual capture SHA, commands, CI and device/browser evidence in the Issue, not invented success totals.

## Inspect actual visual differences without claiming acceptance

The independent `qa/visual_diagnostics.py` works on **any 1–40 screens** in the same canonical, trusted capture plan. It uses the existing strict plan validator, checks out the declared real Git commit, re-audits immutable references and the current evidence ledger, then runs each genuine renderer at the exact source PNG dimensions. It does **not** copy original screenshots into runtime outputs, edit the evidence ledger or upgrade a screen to `matched`.

From a real Laravel/Chromium session and, separately, a real authenticated Admin context or Flutter device, run:

```bash
sha="$(git rev-parse HEAD)"
python3 qa/visual_diagnostics.py --plan /tmp/walidsite-captures.json --commit "$sha" --dry-run
python3 qa/visual_diagnostics.py --plan /tmp/walidsite-captures.json --commit "$sha"
```

For each successful renderer, the report is `qa/visual-diagnostics/<full-commit-SHA>/report.json`. A captured `<SCREEN-ID>.runtime.png` is **the browser/device-rendered output only**; a `<SCREEN-ID>.difference-preview.png` highlights each mismatching RGBA pixel (including alpha changes). Its white pixels indicate the diagnostic location of a difference; it is **never used to mask or relax** the zero-difference acceptance gate. The original reference is kept only at its immutable path.

Per-screen statuses distinguish `renderer_failed` (including unavailable authenticated state or device), `wrong_dimensions`, `invalid_png`, `oversized_png`, `source_copy_rejected`, `mismatch`, and `exact_pixels_unattested`. The latter means the decoded pixels compared equally, **not** that the runtime's identity or source was independently certified. Renderer stderr/stdout and environment credentials are not copied into the diagnostic report. A failing screen does not prevent diagnosing the next one.

Diagnostic renderers are re-checked against the actual clean Git HEAD **after every invocation**, including failed/timeout invocations. A renderer that alters source, contracts or immutable reference images aborts the batch; its screenshot is not compared or reported as a trustworthy diagnostic. A partial diagnostics directory from such an aborted attempt is not evidence and must be reviewed and removed only after restoring a clean checkout. This closes a separate integrity gap not covered by the initial preflight.\n\nThe diagnostic command returns 1 for mismatched/failed captures, 2 for invalid or tampered capture plans and unavailable Git provenance, and 0 for successful dry-runs or no measured differences. None of those exit codes constitutes final visual approval. Use the separate real-capture command `qa/capture_visual_batch.py` and `qa/validate_visual_fidelity.py --require-complete` for actual evidence admission and the final 40/40 gate.

Generated diagnostics are ignored under `qa/visual-diagnostics/.gitignore`, are refused if a SHA-specific destination already exists, and may contain authorized interface data. Keep them on the controlled QA machine or CI artifact with appropriately restricted access; **never** commit production PII, auth storage state, tokens, screenshots of private records, or privileged URLs. Delete a prior local output directory deliberately before repeating the same commit, rather than silently overwriting it. Synthetic unit tests for the diagnostic renderer validate security and math only: they do **not** count as actual screenshots of WalidSite.


## CI real public Web diagnostics — Issue #481

**CI execution provenance:** the real 13 authenticated Admin + 12 routable Web runtime diagnostic step and its artifact upload are admitted for `feat/481-*`, `fix/481-*`, and `chore/481-*` pull requests alike. Both steps must use the same explicit branch allowlist; a green required gate with the diagnostic step `skipped` **does not** attest a runtime capture. Check the job's exact tested head and completed step outcomes, plus the `issue-481-admin-and-web-runtime-visual-diagnostics` artifact; missing files cause a CI error, not a warning. Real PNGs remain diagnostics only until separately authenticated, independently matched against the original 40 reference screens with zero differing decoded RGBA pixels.

The existing PR CI workflow on `feat/481-*` runs an isolated loopback Laravel server and authenticates an owner only for protected Admin screenshots. It now also captures **12 currently routable static public Web routes** (WEB-01/02/03/04/06/07/09/10/11/12/13/14) at each canonical reference image's physical pixel dimensions, using a clean Chromium browser and the real Laravel app. The output artifact contains *separate* folders `qa/visual-diagnostics/<tested-sha>/` (13 Admin pages) and `qa/visual-diagnostics/<tested-sha>-web/` (12 currently routable public Web pages), including fresh PNGs, decoded RGBA mismatch counts and binary difference previews. The full required CI still runs on the actual PR SHA. **Mismatch is a truthful diagnostic, not a matched design certificate.**

`python3 -B qa/build_web_visual_plan.py --locale en` creates the 12-page static Web plan. WEB-06 `/ai-engineering` is now implemented on main and included by default, after verifying its Laravel GET route is registered. The Chromium renderer must still capture a valid live HTTP response at the canonical dimensions: route admission alone is not visual parity. `--exclude-ai-engineering` is available for explicitly partial diagnostics only; the legacy `--include-ai-engineering` flag is accepted for compatibility. To include WEB-05 and WEB-08, the QA operator must identify genuinely published, evidence-verified case-study and article slugs and run `python3 -B qa/build_web_visual_plan.py --project-slug VERIFIED_PROJECT --article-slug VERIFIED_ARTICLE --require-all` (replace placeholders with existing verified slugs). These are not seeded with fictional public content. For each runtime plan, `python3 -B qa/visual_diagnostics.py --plan ABSOLUTE_PLAN_PATH --commit EXACT_SOURCE_SHA --group web` produces a new, non-overwriting report path. Scripts do not edit original screenshots or promote `pending` ledger entries. Use `PYTHONDONTWRITEBYTECODE=1` and remove only known generated Composer/Python caches before strict clean-source attestation.

A passing CI indicates that real runtime PNGs were captured for all included pages and returned correct routes, locale and geometry. It does not establish pixel parity: do not close #481 until 40 real independently verified zero-RGBA matches, with mobile device and accessibility evidence, have been admitted.


## Canonical Android/Flutter device route coverage — Issue #481

The real device adapter at `qa/render_android_screenshot.py` supports the 13 original
MOBILE-01..13 screen identities, using foreground-process proof, an actual
Android accessibility hierarchy, localized visible headings or unique Flutter
Semantics markers, and a native PNG capture **before and after** a second
foreground/identity check. It accepts concrete solution and offline runbook
resource IDs; a literal `{id}`, fabricated screenshot, browser simulation or
an off-screen navigation card never establishes the active screen.

From the same exact clean Git checkout used to build/install the actual Flutter
app, connect an Android emulator or an authorized test device. Record its real
ADB serial; `WALIDSITE_MOBILE_ADB_SERIAL` is mandatory. Configure the device
**physical screenshot resolution** to match each immutable reference's
dimensions, choose the requested locale in the *running app*, then navigate to
the specified screen. The renderer does **not** navigate, log in, inject
fixtures or change app state. Do not capture private user data in public
artifacts. If the device cannot display the required resolution/route or a
protected screen is not actually authenticated, leave that screen pending.

For the 11 non-dynamic routes, construct the plan without resource arguments.
To include the two dynamic screens, obtain an actual visible, authorized
solution and downloaded offline-runbook ID from the running app; no example
identifier is a valid product claim. Then use the command pattern:

```bash
export WALIDSITE_MOBILE_ADB_SERIAL='YOUR_CONNECTED_DEVICE_SERIAL'
sha="$(git rev-parse HEAD)"
python3 -B qa/build_mobile_visual_plan.py --locale en --dpr 1 \
  --solution-id 'ACTUAL_SOLUTION_ID' --runbook-id 'ACTUAL_RUNBOOK_ID' \
  --require-all --output /tmp/walidsite-mobile-plan.json
python3 -B qa/capture_visual_batch.py --plan /tmp/walidsite-mobile-plan.json \
  --commit "$sha" --require-all --dry-run
python3 -B qa/visual_diagnostics.py --plan /tmp/walidsite-mobile-plan.json \
  --commit "$sha" --group mobile
```

Replace `YOUR_CONNECTED_DEVICE_SERIAL` and the two placeholder values with
identifiers independently observed on the real device; they are **not**
generated by the plan builder. `--require-all` fails if either dynamic
resource is absent; without it the builder deliberately emits only the 11
static routes. All device commands are invoked separately by a real operator,
one screen at a time, because the active foreground screen must match each
requested route and locale. A batch run without navigation will fail the
screen-identity gate as designed. For a repeated diagnosis at the same commit,
review and deliberately remove the existing ignored mobile diagnostics
directory before another attempt; the tool never overwrites it.

Inspect the mismatch report, then admit only independently verified matching
runtime pixels using `qa/capture_visual_batch.py` without `--dry-run` and
finally `qa/validate_visual_fidelity.py --require-complete`. A renderer can
validate that the correct kind of screen is visible; for variable resources,
the operator must also prove the **exact record ID** through the authorized
app/session. Native marker presence alone does not prove record identity.
Synthetic test XML/PNG exercises fail-closed admission only and is not device
capture evidence. No mobile screen is visually certified until that chain and
the original zero-RGBA comparison are both documented.

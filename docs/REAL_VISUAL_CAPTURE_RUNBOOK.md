# Visual capture execution — Issue #481 (LEAD QA)

**Status: capture tooling only.** A successful CI or dry run does not certify a screenshot. Each original v6 reference stays immutable, and `design/visual-fidelity-evidence.json` keeps a screen at `pending` until a **real running** UI capture matches decoded RGBA pixels at the original dimensions with zero differences. The 14 public Web, 13 authenticated Admin and 13 Flutter screens need individual runtime evidence.

## Prepare real runtimes (never mock acceptance)

1. Use the checked-out target commit and install the pinned Pillow requirement already used by CI. For local web/Admin capturing, install Playwright and Chromium separately (`python3 -m pip install playwright` and `python3 -m playwright install chromium`). Start an actual local Laravel server with authorized data; never expose it to the public solely for screenshots.
2. Set `WALIDSITE_RENDER_BASE_URL=http://127.0.0.1:8000` for local Web/Admin, or a trusted HTTPS origin. `qa/render_web_screenshot.py` refuses remote HTTP, redirects and non-200 pages. It uses the exact native reference dimensions and locale/DPR furnished by the capture validator.
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

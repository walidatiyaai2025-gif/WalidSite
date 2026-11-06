# T024 / #481: real browser desktop, tablet and mobile checks

This is an executable accessibility/responsive **diagnostic**, not an image
parity certificate or a replacement for owner review. Every CI #481 branch and
every main push performs the following at a source-pinned clean checkout:

| Surface | Signed-in state | Screens | Languages | Viewports | Checks |
| --- | --- | ---: | ---: | ---: | ---: |
| Web public (static) | Real anonymous Chromium context | 12 | English and Arabic | 1440×900, 768×1024, 390×844 | 72 |
| Admin | Locally authenticated, private file-only Playwright state | 13 | English and Arabic | 1440×900, 768×1024, 390×844 | 78 |
| **Combined** | **Both modes separately isolated** | **25** | **2** | **3** | **150** |

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
dimensions, not just the aggregate counts. Only the 25 source-backed pages
that can be rendered without unapproved publication are covered here.

The original `assets/screen-reference/*`, the 40-screen canonical contract,
`design/visual-fidelity-evidence.json` and the frozen owner-approved WEB-01,
WEB-03 and WEB-04 designs are unchanged. **Zero decoded-RGBA matches are NOT
implied by 150 accessible and responsive checks.** WEB-05 Case Study and
WEB-08 Article Detail still require actual approved published slugs; all 13
Flutter screens require a native runtime/device harness, and real device
breakpoints/contrast/assistive-technology acceptance remain pending.

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

# WEB-03 mobile horizontal-overflow attribution

**Scope:** Issue #481, LEAD QA diagnostics only. This runbook does not change the
owner-approved WEB-03 design, its protected Blade sources, immutable reference
PNGs, or `design/visual-fidelity-evidence.json`. It cannot certify pixel parity.

The fresh, real 48-slot Web Chromium matrix on `main@3c3a0ee` found only two
remaining public-Web accessibility/responsive defects: WEB-03 at mobile viewport
390 × 844 for EN and AR, both `document_horizontal_overflow`. The exact
source previously produced 0 visible-H1 errors; 52 authenticated Admin checks
also passed. A root scroll-width symptom alone does not identify which child
or CSS pseudo-element extends the document, and simply clipping content would
hide controls rather than repair responsiveness.

## What the updated existing audit collects

`qa/audit_web_runtime.py` runs its **normal anonymous real Chromium** check.
Only after an actual `document_horizontal_overflow` defect, it performs a
read-only, bounded probe on the same page to collect:

- `viewport_px`, number of examined DOM nodes (max 4,000) and truncation;
- at most eight rendered structural hints, sorted by horizontal overhang;
- each hint's **HTML tag**, **allowlisted source CSS class** (the `expertise-`,
  `t007-`, `site-` or `wa-` prefixes) and integer `overhang_px`.

The script deliberately **never copies** node text, HTML, IDs, URLs, input
values, aria labels, cookies, data attributes or arbitrary class names into
the artifact. It does not use an authenticated Admin session or external
network requests. On an invalid browser result, the summary is
`{"status":"unavailable","candidates":[]}`; the original overflow defect
is retained instead of being promoted to a false pass.

From a clean, source-pinned checkout with the approved local Laravel runtime,
Chromium Playwright dependencies and no private storage state:

```sh
WALIDSITE_RENDER_BASE_URL=http://127.0.0.1:8000 \
  python3 -B qa/audit_web_runtime.py \
  --commit "$(git rev-parse HEAD)" \
  --output /tmp/walidsite-web-a11y-fresh.json
```

The CLI exits 1 while any accessibility defects remain. Inspect the WEB-03
`mobile` rows' `overflow_structure` in the private CI artifact. A structural
hint is **not conclusive layout proof**: scrollable child containers,
transformed SVGs and pseudo-elements may still require actual browser
inspection. Use the source-class hints to propose the smallest legitimate
responsive fix; do not unfreeze or restyle the owner's WEB-03 source without
explicit reviewed authorization.

**Not fulfilled:** original 40-screen decoded-RGBA strict-zero matching,
WEB-05/08 authenticated published-route evidence, MOBILE-01..13 physical
device captures, staging, publisher signing and T024 integrated QA. The audit
always returns `evidence_admitted=false` and
`visual_parity=not_attested`.

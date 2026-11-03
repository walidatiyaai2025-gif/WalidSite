# Owner-approved design freeze — WEB-01, WEB-03 and WEB-04

**Recorded:** 2026-11-03. **Authority:** direct instruction from the repository owner in this conversation to personally approve the *current code and current rendered design* and close their completed design work, preventing worker redesign.

## What the owner approved

| Screen | Route | Design issue | Implemented PR | Final review baseline |
|---|---|---|---|---|
| WEB-01 — Landing | `/` | #798 (completed), #647 (legacy superseded) | #799 | `cf5b72f129d9daa11f3aa1d55a9d6d52740eeb0b` |
| WEB-03 — Expertise, Overview/Delivery/Technology/AI & Operations | `/expertise` | #652 | #800 | same `main` |
| WEB-04 — Projects | `/projects` | #659 | #800 | same `main` |

The owner explicitly approved all three *as implemented*, including the Landing terminating at the six-domain capability ribbon, four real Expertise tabs, and current Projects page. This decision applies to the owner-selected design at the baseline source SHA, not to old reference screenshots that conflict with the new owner approval.

## Proof types must not be confused

- **Owner design approval — YES.** Direct explicit acceptance of these specific three currently merged designs and the code baseline. Issues #652 and #659 can be closed for completed owner design sign-off after freeze protection merges.
- **Exact-head engineering CI — YES at the inspected baseline.** The `main` PR CI on `cf5b72f129d9daa11f3aa1d55a9d6d52740eeb0b` succeeded. The new freeze guard must also pass the merge PR and the resulting main SHA.
- **Pixel-perfect decoded-RGBA runtime comparison — NOT ATTESTED.** The 40-entry `design/visual-fidelity-evidence.json` ledger remains strict: never change `pending` to `matched` without a real browser/device capture at the relevant reference and zero-difference comparison. This approval is not an invented screenshot, pixel count or deployment.
- **Live production deployment / arbitrary dataset fidelity — NOT ATTESTED.** Continue release/security/QA issues (#481, #25, #732, #801) independently, especially real-world content, installer, and device tests.

## Engineering freeze — enforceable source file identities

`design/owner-approved-web-design-freeze.json` contains seven page-specific, tested Git blob identities. `qa/validate_owner_design_freeze.py` runs in the existing mandatory GitHub Actions CI and rejects an unapproved edit to any locked design template. The gate also checks that the screen inventory and original owner acceptance metadata are present. This controls page composition, scoped colors, artwork SVG, content containers, and essential owner-approved layout structure.

**Workers:** do not touch those frozen files, silently rebase them onto the old v6 assets, or edit the lock/gate as a bypass. Do not open repeated exact-design micro-tickets for these owner-approved screens. New functional work may use new controllers, database models, CMS content and evidence-safe data; never fabricate user CV, projects or credentials. Shared layout and route changes may proceed for other screens but **must preserve** the accepted display and links of these three.

**Exception path:** only a *new, explicit owner design directive* may authorize new visual fingerprints and a reviewed versioned amendment to this registry. The LEAD must reference that directive in an Issue/PR and run security, RTL/LTR, accessibility, page journeys and exact-head CI before merge. Ordinary requests to fix runtime security without a visual modification should not change the frozen files. A protection change requires a deliberate reviewed PR; repository owner/branch protection ultimately controls who can merge.

## Historical reference conflict

Original v6 reference files under `assets/screen-reference` and `contracts/screens.json` are unchanged. ADR-010 remains the default for all other screens. For these three ONLY, this later owner directive is the authoritative selected layout. The original reference pack and its unresolved pixel-difference ledger remain available for diagnostic QA without power to restore a rejected visual composition. Issue #481 remains open for independent visual evidence and remaining original screens; it must distinguish **owner accepted design** from **zero pixel-match evidence**.

## Traceability

- Baseline branch `main@cf5b72f129d9daa11f3aa1d55a9d6d52740eeb0b`.
- Owner acceptance: direct instruction dated 2026-11-03, explicitly listing Landing / WEB-01, Expertise / WEB-03 and Projects / WEB-04 and saying to lock current code/design.
- Canonical immutable, machine-readable fingerprints: `design/owner-approved-web-design-freeze.json`.
- Mandatory guard: `qa/validate_owner_design_freeze.py` with regression tests `qa/tests/test_owner_design_freeze.py`.

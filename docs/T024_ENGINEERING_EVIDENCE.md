# T024 / Issue #25 — Integrated QA evidence inventory (LEAD)

This is a **fail-closed engineering traceability instrument**, not a release,
staging, device, accessibility, security or runtime visual certification.

## Audited baseline (2026-11-02)

- Source: main at \`3ee7d1bce94ec4aace03f6367936d0020959f365\` before this QA PR.
- T001–T024 live Issues: **20/24 closed**; **T007 #8, T009 #10,
  T012 #13 and T024 #25 remain open**. The product Admin gap #476 and
  the 40-screen capture gap #481 also remain open.
- Visual ledger: **0 matched / 40 pending**, comprising 14 Web, 13 Admin,
  13 Flutter. A successful CI harness run never changes that result.
- \`contracts/screens.json\`: **40 screens and 97 screen/action pairs**.
- \`api/openapi-contract.yaml\`: **42 paths, 46 HTTP operations**.
- \`contracts/acceptance-gates.json\`: **20 blocking gates**.
- #596 privacy-export repair was already merged as PR #597, commit
  \`3ee7d1bce94ec4aace03f6367936d0020959f365\`; never reopen/duplicate.
- W2 draft PR #499 and #539 own Web work. No LEAD/W3 cross-owner edits
  are authorized by this QA bundle.

## Reproducible inventory

From an exact source checkout:

    python3 qa/t024_readiness.py
    python3 qa/t024_readiness.py --require-ready

The first command validates the contract/evidence structure and enumerates
unverified actions and API operations in JSON; it can return success even when
readiness is false. The **strict** second command exits **2** when evidence
is genuinely incomplete, **1** for invalid/untrusted inputs, and **0** only
after all local engineering acceptance evidence is present and internally
consistent. The tool invokes the existing independent zero-RGBA visual ledger
validator rather than treating a reference PNG as an application capture.

This inventory does **not** assert that a file marked as pinned proof is
itself a genuine runtime trace. The LEAD must separately review origin,
authorization, fixture, source SHA, test output, measurements, and immutable
device/browser captures before admitting it. Never submit a test fixture,
reference image, inaccessible CI artifact or generated approximation as proof.

## G-01..G-20 — explicit acceptance ownership

All current internal entries are **pending**, not certified; no runtime
G-01..G-20 successes are invented in this document. Register actual evidence
in \`design/t024-gate-evidence.json\`, including the recorded source SHA and a
cryptographically pinned evidence file in \`qa/t024-evidence/\`. **G-12** belongs
to staging/release Issue #26 and **G-20** to owner handoff #28; they are recorded
\`deferred_external\`, not silently passed or required as premature T024
deployment claims.

| Gate | Evidence required before verification | Ownership |
| --- | --- | --- |
| G-01 | Public professional proof and negative unpublished-claim tests | W1/W2, LEAD |
| G-02 | Original portrait/provenance SHA and asset integrity scan | LEAD |
| G-03 | 97 real screen actions: target, permission, empty/error and no inert controls | W2/W3, LEAD |
| G-04 | Real AI provider configure/test/disable/switch from Admin | W4/W1/W2 |
| G-05 | Secret redaction and credentials absent from app bundles, logs, commits | W1/W4/LEAD |
| G-06 | Measured queue/idempotency/retry concurrency and recovery tests | W4 |
| G-07 | Real Android offline/stale/reconnect behavior | W3/LEAD |
| G-08 | Low/high-risk publish, approvals, source evidence and scheduled withdrawal | W1/W2 |
| G-09 | Verified public GitHub allowlist, owner rights, fresh source and negative cases | LEAD/W1 |
| G-10 | Current exclusive issue/branch/PR/file ownership audit | LEAD |
| G-11 | Successful exact-HEAD required CI for every acceptance PR | LEAD |
| G-12 | Staging artifact/tag/deploy/rollback provenance (external, not T024 closure) | #26 T025 |
| G-13 | Actual WCAG 2.2 AA AR/EN focus/keyboard/contrast/screen-reader tests | W2/W3/LEAD |
| G-14 | Real viewport/device responsive layout, scroll, hit areas and locale checks | W2/W3/LEAD |
| G-15 | SEO canonical/sitemap/robots/hreflang/redirect runtime checks | W2 |
| G-16 | Consent/privacy/export/delete/access-denial real journeys | W1/W3 |
| G-17 | Sanitized legacy-content import and disallowed table/upload rejection | W1 |
| G-18 | AI image source/licensing/provider policy, no generated owner portrait | W4 |
| G-19 | Provider budget/rate caps, breaker, costs and failed-provider responses | W4/W1 |
| G-20 | Final owner handoff, not intermediary questions (external) | #28 T027 |

## Evidence format and negative rules

The register starts with **no** action/API proof and all 18 internal gates
\`pending\`. Every action key is \`SCREEN-ID/action_name\`, and API key is
\`/contract/path#httpmethod\`. For an action or API operation, an evidence value
is a nonempty array of records with these exact fields:

- \`path\`: an existing small, non-symlink file in \`qa/t024-evidence/\`.
- \`sha256\`: exact lowercase SHA-256 of that file.
- \`source_sha\`: exact 40-digit lowercase Git commit under test.
- \`command\`: the reproduction command, with no secrets.

A gate may become \`verified\` only with a nonempty array of the same
independently reviewed proof records. A hash validates integrity **only**,
not the truth or adequacy of the recorded observation. Unknown API operations,
unknown actions, missing gates, unsupported external deferrals, symlink proofs,
bad source SHAs and missing/modified files are rejected.

Full T024 requires **40 actual independent pixel-exact screenshots** admitted
by #481; 97 verified screen actions; 46 independently verified API operations
with positive/negative roles and DTO checks; all 18 internal gates certified
with actual runtime/CI evidence. The original 40 references remain immutable.
The existing visual-capture runner and browser/ADB renderer require real
sessions and authentic content; a renderer harness test never counts.

Performance includes actual endpoint/Flutter startup/soak budgets and measured
failure behavior, not inferred CI runtimes. Also perform tested auth, MFA,
RBAC, authorization, permission negative cases, AI-provider outages, SSRF,
injection, secrets, offline, AR/EN and accessibility; record discovered
defects under their owning Issue and re-run exact-head CI after fixes.

## Explicit blockers at this baseline

The Web/UX #8 and #13 final reference-accurate PRs are draft and cannot be
treated as accepted production UI. #10 depends on #8. Admin #476 awaits
real runtime final acceptance. Real device/browser credentials, published
project/article IDs and actual screenshots are required for #481. At this
inspection, the authorized Desktop Commander device \`GCC\` was offline,
so no Android pixel screenshot was possible from that machine. No screenshot,
security penetration result, WCAG AA audit, performance/soak result or
cross-service E2E result is claimed by this evidence tooling PR.

Release and deployment validation and owner acceptance stay with #26–#28,
not with this T024 branch. Keep #25 OPEN until **all** its real engineering
evidence is accepted and merged.

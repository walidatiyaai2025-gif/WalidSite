# Engineering-first delivery — final owner acceptance only (2026-11-01)

## Stage A: complete and close software tasks
1. LEAD reviews live GitHub issues, branch ownership, current `main`, dependencies and open PRs. W1/W2/W3/W4 work in real independent lanes only where independent tools exist; otherwise execute sequentially. Never call a logical lane a running agent.
2. Implement the *whole* accepted software scope of T001–T024: Laravel backend/API, Blade/admin, Flutter Android/iOS, AI adapters/orchestration, bilingual experience, security/accessibility, real states and local/simulator testability. Ship small PRs and reuse existing branches/PRs instead of duplicating.
3. Use reversible defaults without owner questionnaires. Where third-party access is unavailable, provide encrypted configuration, test doubles, explicit unavailable states and negative tests; record external verification in [external activation ledger](EXTERNAL_DEPENDENCIES.md).
4. Verify scoped tests, API/DB conformance, feasible local integration and screenshots, plus **successful exact PR-head CI**. Merge into `main`, verify merge SHA and close the corresponding T001–T024 Issue only after **all its engineering acceptance** is delivered. Never close an Issue for one small PR if other code is still missing.
5. Missing owner test time, cloud keys, DNS, cPanel, real SMTP, hardware device, iOS signing, billing, store account or editorial/publication rights do **not** block a fully completed engineering Issue. Carry the evidence-based external obligations into T025–T027 and leave unsafe features disabled/unpublished until activated. Do not fake a real deployment or production integration.

## Stage B: complete final integration and activation
6. Once software is implemented, complete T024 automated E2E, AR/EN, security, accessibility, responsive and performance matrices. Any remote-only smoke is tracked explicitly in T025 and never mislabelled as performed.
7. T025 validates authorised staging environment, backed-up migrations, TLS/health/smoke, actual provider/contact paths when configured and rollback. T026 validates versioned Android/iOS/web artifacts, identities/signatures/store submissions when authorised. Their Issues remain open while verifiable prerequisites are absent.
8. Only after the complete website **and** app are technically ready and staged for an integrated demo does T027 request one consolidated owner testing/review round. No routine owner testing or sign-off mid-development.

## Status and protection
- A code-complete Issue with external items pending reads: `ENGINEERING COMPLETE / RELEASE UNVERIFIED` (closed once engineering evidence passes). Its linked T025–T027 gate stays open.
- `BLOCKED ENGINEERING` means a genuine missing code/security/test deliverable, not a missing subscription or owner UX walkthrough.
- `BLOCKED EXTERNAL` is a truthful condition on the activation/release gate; never fabricate a key, legal clearance, deployed site, signed app, or test run.
- **The canonical baseline denominator remains 27**, including T025–T027; to communicate progress, report the verified 27-task percentage and optionally engineering-complete N/24 separately. Never reclassify a release gate as done just to reach 100%.
- Retain two effective GitHub Actions workflows, exact-head required CI, branch lifecycle, code ownership and all security/publication/content restrictions.

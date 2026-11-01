# ADR-009 — Engineering-first closure; one final owner review

**Status:** Owner-approved, 2026-11-01. **Decision record:** [GitHub program Issue #1](https://github.com/walidatiyaai2025-gif/WalidSite/issues/1). **Applies to:** T001–T027.

This is a live operational amendment to the original, checksum-protected v6 reference bundle. Keep the immutable `MANIFEST.json` integrity checks and historic baseline source files unchanged. The current owner instruction is that **all site/admin/API/AI and Flutter development finishes before the owner tests either product**.

## Stage A — software implementation and evidence (T001–T024)

1. Follow the live issue/PR dependencies and exclusive path owners. Implement the **full engineering acceptance** of each Issue, not merely a merged slice. Use cohesive, reviewable **feature bundles** (ADR-011), real code, secure configuration and automated tests. Avoid a separate PR for each small button, translation or test when a full user journey is still unfinished. No routine owner screenshots, testing or tech-choice approval during implementation.
2. Mandatory engineering evidence: functional web/admin/API/Flutter code, contract correctness, security/privacy/publication policy, AR/EN + RTL/LTR + accessibility, error/loading/offline/permission behavior, scoped tests, feasible local/emulator/simulator/integration checks and **green exact-head required CI**. UI screenshots where a local emulator/browser can produce them. Merge and verify main SHA. Never close on red CI or unimplemented requirements.
3. **Close a T001–T024 engineering Issue when all its programmatically verifiable software deliverables pass and the merged PR/CI evidence is documented.** Do not keep that completed code Issue open solely for unprovided live domain, hosting permissions, keys, billing account, certificates, store credentials, external legal permissions or the owner's final UX review. Link each deferred release step to T025/T026/T027 and record `ENGINEERING COMPLETE / RELEASE UNVERIFIED`.
4. External provider integrations are still engineered: secure settings in admin, validation, budget/rate/circuit-breaker protections, test-double/fixture or local-server contract tests, fail-closed disabled/unavailable states, and documented activation checks. Mock results never count as actual provider connectivity. Do **not** alter source-contract security thresholds or bypass paid-provider, rights or fact-check gates.
5. **Keep genuinely missing code and failing automated checks open.** Partial features, omitted UI actions, missing safe states, weak authorization, counterfeit content, unverified CV claims, broken CI and missing integration test coverage remain engineering blockers. T024's automated end-to-end QA must be real, not confused with later remote smoke.

## Stage B — final activation, distribution and owner acceptance (T025–T027)

| Gate | External/human prerequisite (if unavailable) | Engineer now, verify later |
| --- | --- | --- |
| **T025 — Staging** | Authorised cPanel/SSH/server access, domain DNS, valid TLS, real database and backup/restore scope, provider endpoints and secrets, verified SMTP/notification sender, legally approved public evidence/media | Create and CI-test deployment/rollback scripts, migration checks, health probes, API mocks/failures, permissions and anti-spam; verify actual hosting, real service delivery and rollback **only when possible** |
| **T026 — Release** | Approved Android application ID and iOS bundle ID, developer/store accounts, real signing certificates/provisioning, distribution/privacy declarations | Native Flutter source, Android debug + iOS simulator regression, reproducible versioning/build automation; actual signed APK/AAB/IPA and store submission remain unverified until real identities exist |
| **T027 — Final owner test** | Owner reviews the finished website and mobile application together | Prepare documented URLs, all tested flows and consolidated review checklist **after every software feature is finished and final build is ready**; never request interim owner review |

These are *potential* dependencies, not proof that access is absent. Check usable authorisations already available to the project; never request passwords/keys in an Issue, commit or chat and never pretend a token, payment, approval, staging URL or signed build exists.

A release gate may remain **BLOCKED EXTERNAL** with a precise record while independent code work continues and other engineering Issues close. T025/T026/T027 **cannot** be closed merely on mocked checks or completed code: they require actual staging, signing/distribution and owner-handoff evidence as defined in those Issues. No confidential employer/government details may be published without real permission; defaults must be anonymised/unpublished.

## Progress and escalation

- The canonical v6 count remains **27 tasks (T001–T027)**. Close engineering Issues T001–T024 only with full evidence; complete T025–T027 only with real external acceptance. Do not inflate the canonical progress figure.
- Optionally report a **separate engineering progress** `N/24` for verified closed code/QA tasks, and show release gates separately; never claim `24/24` means a released app.
- If an engineering Issue had only an external release/owner blocker, review source/tests/PRs and close it with test SHA/merge evidence while linking T025/T026/T027; do not blanket-close all open tasks.
- Once all engineering is complete, invite **one consolidated owner evaluation** of both working products (T027). If an external account remains unavailable, record its release gate, do not interrupt software work, and never claim production readiness.
- Project source hierarchy remains: live GitHub state and latest explicit owner decision; checksum-protected v6 bundle as immutable reference; existing contracts, risk/security protections and two Actions workflows remain binding.

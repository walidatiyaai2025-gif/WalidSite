# External activation ledger — deferred until engineering completion

Owner decision (2026-11-01): Finish **all** development for the Laravel site, admin, AI orchestration and Flutter app before requesting the owner to run either product. Do not repeatedly interrupt the owner for ordinary approvals, test sessions, API tokens, production access or UI selections. Every such dependency is a **deferred activation gate** rather than a coding blocker.

## Deferred human / external prerequisites

| Gate | Potential external input | What engineering can finish now | Verification deferred to |
| --- | --- | --- | --- |
| Hosting | Authorised cPanel/SSH/deployment access, DNS and real TLS configuration | Laravel build, DB migrations, environment templates, deploy/rollback scripts, CI and non-production smoke | T025 staging, health, backup/rollback |
| AI providers | Working self-hosted access or paid provider credentials/billing | Provider CRUD/routing, encrypted secret handling, budgets, safety checks, fake-server integration and failure modes; disabled until configured | T025 provider network smoke before activation |
| Contact/notifications | Verified mail recipient, SMTP credentials, push/SMS sender where required | Validation, CSRF/rate limits, queue/retry, no-mail safe fallback and automated delivery fakes | T025 outbound delivery smoke |
| Image/content rights | Verified asset provenance and publication/legal permission for sensitive claims | Provenance data and approval controls, evidence/rights enforcement, draft previews and fail-closed publishing | T025 authorisations before any public publish |
| Distribution | Approved Android/iOS package and bundle IDs, store accounts, signing certificates, privacy/store declarations | Native runners, simulator/emulator tests, packaging scripts, release source hardening, unsigned build evidence | T026 real signed/store submissions |
| Owner UX feedback | Owner opens both live site and mobile application | Full product functions, bilingual/responsive/a11y tests, integration coverage, screenshots and final preview instructions | **T027 only after engineering is fully complete** |

The entries describe **potentially required** items, not assertions that credentials are definitely missing. Check existing authorised environment access without requesting new secrets; do not store credentials in Git, logs or comments.

## Engineering issue close-out (T001–T024)

- Complete every implementable endpoint, UI action, integration adapter, domain model, test and required screen state; do not close a partially implemented task.
- Run required contract, security, regression, AR/EN, accessibility and feasible local or simulator checks plus **passing exact-head `required-ci-gate`**; merge and record real SHAs and test links.
- If all engineering obligations are met, close the Issue as **engineering complete** even when live staging, production signing or owner acceptance is deferred. Link the remaining external gate (T025, T026 or T027) rather than copying the blocker into every engineering Issue.
- A live-only API/credential prerequisite must have a secure config interface, a test double or fixture-backed contract test, fail-closed disabled state and documented activation check. Do **not** claim a real call/installation was tested using a fixture.
- Blocking engineering defects, security/privacy gaps, missing scope, red CI, or unimplemented review enforcement must be fixed first. Human approvals never justify bypassing publication permissions or exposing confidential material.

## Release gate truth

T024 retains the end-to-end automated/integration QA obligations; staging-specific checks that technically require a remote server are recorded under T025. T025, T026 and T027 **cannot close until actual evidence exists**: staging deploy/rollback, signed distributable artifacts/credentials as applicable, and final owner preview respectively. They should not stop other code from being merged. This separation avoids inflating the canonical 27-task completion metric.

# WALEED ATIYA – AUTONOMOUS EXECUTION CONSTITUTION v6.0

## Authority precedence
1. Live GitHub issue/PR/branch/CI exact-head evidence; 2. this AGENTS.md; 3. `contracts/`, `design/`, `api/`, `database/`; 4. code and tests; 5. dated handoffs. History and mockup text are NOT runtime truth.

## Mandatory preflight on EVERY task
1. Fetch latest target branch and current task/PR registry (including merged and draft PRs).
2. Check `tasks/master-task-register.json` and issue ownership, paths and dependencies. The registry is only a seed until Issues exist; after that, live GitHub wins.
3. If another active branch/PR already implements the issue or paths, CONTINUE that work. Do not duplicate.
4. Claim a single Issue through coordinator; include owner, scope, base SHA, test plan, changed paths, lease expiry.
5. No code until the exact matching screen spec, route, UX asset and API contract have been read.

## Autonomy and escalation
No owner involvement for normal development or repeated design questions. Make reversible, low-risk choices consistent with approved contracts and log them in an ADR. If mandatory credentials, signing identities, confidential data, permission/legal approvals or inaccessible services are missing, record the activation dependency in T025/T026/T027, implement and test all safe code paths using non-secret local substitutes/fixtures, and continue engineering. NEVER fabricate a successful integration. No mid-development owner testing requests.

## Parallel work
4 executing lanes maximum plus one LEAD integrator. W1=backend; W2=web/admin; W3=Flutter; W4=AI. Shared contracts/assets/CI owned by LEAD. Scope changes across paths require LEAD split, not workers racing edits. Pull requests must have small blast radius.

## Simplicity
Exactly two GitHub Actions workflows: PR CI and Release. One integration target `main` (protected). Short-lived branches `feat/<task-id>-slug`, `fix/<task-id>-slug`, `chore/<task-id>-slug`. Use path-aware tests + one stable exact-head `required-ci-gate`; no workflow explosion.

## Merge/release
Issue -> existing branch or new short-lived branch -> full implementation/test -> PR -> exact-head required checks -> security/design/regression evidence -> merge through repo policies -> **engineering Issue closure (T001–T024 only, when full code acceptance is verified)**. External staging/store/owner gates are separate T025–T027 milestones. No external dependency alone holds a fully verified engineering Issue open. Never claim merged/deployed from an opened PR or historical green CI. If a PR fails, investigate or re-run only the failed step when appropriate.

## Nonnegotiable security and honesty
Do not publish credentials, private repositories, private employer systems, fake certificates, fake project counts, fictional clients, or personal portraits generated from prompts. Source photo: original provided by owner. AI generated editorial art is allowed if rights, provenance and technical accuracy gates pass. Source DB dump contains sensitive information and MUST NOT be committed.

## Two distinct completion gates (owner decision 2026-11-01)
**Engineering completion (T001–T024):** All code and contract deliverables for the Issue are present, no known code defects or unimplemented acceptance remain, scoped tests/quality/security/accessibility checks and feasible local/simulator integration tests pass, exact-head required CI passes, PR is merged to `main`, and the Issue records evidence (PR, SHA, tests, scope). **Then close that engineering Issue as completed**, even when production keys, human evaluation, physical devices or remote staging are not yet available. A merged *partial slice* does not qualify. A mocked provider test is described as mocked, never as live integration evidence.
**External activation / release completion (T025–T027):** Retain outstanding hosting access, DNS, signed store accounts/IDs, live provider credentials, permission/legal review, actual staging and final owner acceptance as separate explicit, truthful gates. T025 staging, T026 signed release and T027 final owner handoff must remain open until verified. The owner evaluates the fully programmed site **and** Flutter app together at the end, not during development.
**Never loosen product gates:** Real safety, privacy, authorization, provenance, billing/quota limits, publication permission checks, mandatory CI, and offline/error states remain required in engineering. Without legally permitted publication, fail closed/unpublished by default; human proof of rights is an external activation gate, not a license to bypass it. No false green, deployment, signature or user acceptance.
**Dependency sequencing:** Dependency-ready tasks may rely on merged, fully verified engineering closures, not on T025–T027 release status. Record `ENGINEERING COMPLETE / RELEASE UNVERIFIED` as separate truths when appropriate. Baseline progress remains the 27-task metric; report engineering (T001–T024) and release (T025–T027) separately where helpful. Follow `docs/SETUP_AND_DELIVERY.md` and `docs/EXTERNAL_DEPENDENCIES.md`.


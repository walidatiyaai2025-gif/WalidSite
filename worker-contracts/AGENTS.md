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
No owner involvement for normal development or repeated design questions. Make reversible, low-risk choices consistent with approved contracts and log them in an ADR. If mandatory credentials, signing identities, confidential data, permission/legal approvals or inaccessible services are missing, record the dependency and proceed on unaffected work; NEVER fabricate a successful integration.

## Parallel work
4 executing lanes maximum plus one LEAD integrator. W1=backend; W2=web/admin; W3=Flutter; W4=AI. Shared contracts/assets/CI owned by LEAD. Scope changes across paths require LEAD split, not workers racing edits. Pull requests must have small blast radius.

## Simplicity
Exactly two GitHub Actions workflows: PR CI and Release. One integration target `main` (protected). Short-lived branches `feat/<task-id>-slug`, `fix/<task-id>-slug`, `chore/<task-id>-slug`. Use path-aware tests + one stable exact-head `required-ci-gate`; no workflow explosion.

## Merge/release
Issue -> existing branch or new short-lived branch -> implementation/test -> PR -> exact-head required checks -> security/design/regression evidence -> merge through repo policies -> staging smoke -> close issue with SHA and links. Never claim merged/deployed from an opened PR or historical green CI. If a PR fails, investigate or re-run only the failed step when appropriate.

## Nonnegotiable security and honesty
Do not publish credentials, private repositories, private employer systems, fake certificates, fake project counts, fictional clients, or personal portraits generated from prompts. Source photo: original provided by owner. AI generated editorial art is allowed if rights, provenance and technical accuracy gates pass. Source DB dump contains sensitive information and MUST NOT be committed.

## Completion
An issue is complete only when code, tests, docs, screenshot evidence, API/DB compliance, CI exact-head, merge, target staging verification and issue closure are proven, or the issue is marked BLOCKED EXTERNAL with precise evidence and no fake closure.

# Waleed Atiya — worker entrypoint (v6 baseline)

**Read `worker-contracts/AGENTS.md` in full before any work.** It is the authoritative execution constitution. Live GitHub Issues/PRs/branches/CI and the source contracts take precedence over old chat instructions, mockup text, or worker reports.

## Mandatory preflight
1. Read [program tracker](https://github.com/walidatiyaai2025-gif/WalidSite/issues/1), `tasks/github-issue-map.json`, `tasks/master-task-register.json`, and all open/draft PRs and branches.
2. Pick only one dependency-ready Issue, verify it is unclaimed, and use its existing branch/PR if present. A logical worker lane is not a running agent.
3. Read `worker-contracts/path-ownership.json` and matching `contracts/screens.json`, `design/tokens.json`, `api/openapi-contract.yaml`, `database/schema-blueprint.json` before coding. Never touch another worker's domain without the LEAD splitting the task.
4. Record lane, claim, base SHA, owned paths, dependencies and test plan in the Issue. Keep issue/branch/PR one-to-one; avoid speculative branches.
5. Use `backend/` for Laravel+Blade+Livewire+REST and `mobile/` for Flutter; do **not** create an independent web SPA or backend service.
6. Keep GitHub Actions to exactly two effective workflows in `.github/workflows/`: CI and Release. CI must validate exact head of PR; don't merge red/unknown.
7. For T001–T024, finish the **entire engineering scope** with code, automated tests, design/security evidence, exact-head CI and verified merge before closing as engineering-complete. Track unperformed staging, owner review and store verification separately under T025–T027; never claim those external gates passed.

## Owner role and content safety
Ordinary implementation decisions are autonomous, reversible and logged; only bring the owner a verified preview for feedback. Never fabricate project counts, professional credentials, private employer details, AI endpoints, staging URLs, tests, or release results. Portraits of Waleed may use **only** the supplied real photograph and non-face-generative derivatives. Never commit the old SQL dump, API keys, signing secrets or personal subscriber lists.

## Permanent owner directive — complete software before owner testing (2026-11-01)
This amendment is recorded in live Issue #1 and [ADR-009](docs/ENGINEERING_FIRST_CLOSURE.md). The checksum-protected v6 bundle in `worker-contracts/` and the original baseline decision log remain unmodified.
- Work autonomously to finish every feasible Laravel, Flutter, AI, design and integration task. **Do not ask the owner to test, approve screenshots, choose routine technical defaults or provide credentials during development.** Reserve the first hands-on owner acceptance for the completed website **and** app together, at the final T027 handoff.
- **Engineering close-out is not a release claim.** T001–T024 may close individually only when their *full implemented scope* and local/CI-verifiable acceptance have passed, the exact-head PR CI is green and merged, and code-level omissions are absent. Missing live hosting, paid credentials, signed store packages, external permissions, final owner UX review or production smoke alone must not keep otherwise fully implemented engineering Issues open; register them as explicit deferred activation gates in T025/T026/T027 with evidence.
- Code-dependent defects, failing tests, broken authorization, missing error/empty/offline flows, accessibility/security gaps, unimplemented providers/jobs or invalid contracts **remain blocking engineering defects**. Do not call partial slices complete.
- For integrations that need external services, implement adapters, mock/fixture-driven tests, disabled/unavailable states and configuration securely now; keep real-provider runtime verification pending rather than inventing it. Do not weaken protective publication, copyright, secrecy or approval gates.
- Exact scope, separation of phases, and reporting: [ADR-009 engineering-first closure](docs/ENGINEERING_FIRST_CLOSURE.md) and [progress calculation](docs/PROGRESS_PERCENTAGE.md). T025–T027 remain open until their real release obligations are proven.

## Bootstrap phase
T001 / Issue #2 is in progress: uploaded v6 is a **reference pack, not compiled software**. The initial governance/CI PR enables pack checks while scaffold tests will become mandatory as backend/mobile files arrive. A green *pack-only* check is not proof of a functioning application. T002–T027 remain blocked on their declared dependencies until merged.

## Owner command = executable engineering work
The phrases **"كمل وليد سايت"**, **"شغّل الوركرز"**, **"خلص وليد سايت"**, or equivalent are explicit authorization to **implement real code now**, not to stop at planning, issuing tickets, or describing next steps. In the invoking conversation:
1. Inspect live GitHub and take the earliest dependency-ready, non-duplicated task.
2. Change actual product source code, add tests, push commits and open/reuse a PR via GitHub connector.
3. Check exact-head CI, repair red checks on the same branch, merge and verify when allowed; then take additional independent tasks if tooling/time permits.
4. Run multiple workers in parallel **only** when actual independently executing tools are available; otherwise run lanes sequentially. Do not claim background workers are running without a real external agent/automation runtime.
5. Never ask the owner to supervise ordinary development. Do not treat a planning-only answer as completion while a safe, implementable task exists. Report real commits/PRs, verification and external blockers succinctly.

## Owner progress percentage (mandatory, every status question)
When the owner asks how complete WalidSite is ("نسبة اكتمال المشروع", "المشروع وصل كام؟", etc.), **answer first with a numeric %** calculated from the latest live GitHub Issues/merged PRs, then report N completed of 27 v6 tasks and remaining tasks. Follow [the canonical calculation and evidence policy](docs/PROGRESS_PERCENTAGE.md). Do not mistake green CI, open PRs, or merged-but-unclosed task Issues for completed tasks; never claim a stale or fabricated percentage. This is baseline task completion, not staging/release readiness.

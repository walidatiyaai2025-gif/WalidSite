# Waleed Atiya — worker entrypoint (v6 baseline)

**Read `worker-contracts/AGENTS.md` in full before any work.** It is the authoritative execution constitution. Live GitHub Issues/PRs/branches/CI and the source contracts take precedence over old chat instructions, mockup text, or worker reports.

## Mandatory preflight
1. Read [program tracker](https://github.com/walidatiyaai2025-gif/WalidSite/issues/1), `tasks/github-issue-map.json`, `tasks/master-task-register.json`, and all open/draft PRs and branches.
2. Pick only one dependency-ready Issue, verify it is unclaimed, and use its existing branch/PR if present. A logical worker lane is not a running agent.
3. Read `worker-contracts/path-ownership.json` and matching `contracts/screens.json`, `design/tokens.json`, `api/openapi-contract.yaml`, `database/schema-blueprint.json` before coding. Never touch another worker's domain without the LEAD splitting the task.
4. Record lane, claim, base SHA, owned paths, dependencies and test plan in the Issue. Keep issue/branch/PR one-to-one; avoid speculative branches.
5. Use `backend/` for Laravel+Blade+Livewire+REST and `mobile/` for Flutter; do **not** create an independent web SPA or backend service.
6. Keep GitHub Actions to exactly two effective workflows in `.github/workflows/`: CI and Release. CI must validate exact head of PR; don't merge red/unknown.
7. Finish with code, tests, screenshots where UI, exact-head CI, merge SHA, staging evidence and issue closure; or explicitly mark external blockers without pretending deployment succeeded.

## Owner role and content safety
Ordinary implementation decisions are autonomous, reversible and logged; only bring the owner a verified preview for feedback. Never fabricate project counts, professional credentials, private employer details, AI endpoints, staging URLs, tests, or release results. Portraits of Waleed may use **only** the supplied real photograph and non-face-generative derivatives. Never commit the old SQL dump, API keys, signing secrets or personal subscriber lists.

## Bootstrap phase
T001 / Issue #2 is in progress: uploaded v6 is a **reference pack, not compiled software**. The initial governance/CI PR enables pack checks while scaffold tests will become mandatory as backend/mobile files arrive. A green *pack-only* check is not proof of a functioning application. T002–T027 remain blocked on their declared dependencies until merged.

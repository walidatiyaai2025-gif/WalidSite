# ADR-011 — Finish user-visible feature bundles, not micro-PRs

**Status:** Owner-directed change effective 2026-11-01. **Owner request:** stop splitting WalidSite into tiny tasks/PRs. **Authority:** canonical [Issue #1](https://github.com/walidatiyaai2025-gif/WalidSite/issues/1), live GitHub and this dated operational amendment. It supplements ADR-009/010 and root AGENTS.md; it does **not** edit or weaken immutable v6, `worker-contracts/AGENTS.md`, original visual references, contracts, privacy or security gates.

## Decision and non-goals

The 27 immutable task IDs, their Issue mapping, dependency order, W1–W4 ownership and LEAD role stay. **The unit of engineering delivery is a coherent, testable user journey or an entire issue acceptance set — not a button, translation string, or isolated test.** A branch/PR is a transport mechanism; it is not a completion metric. Source truth is always the latest GitHub state, not historical status comments.

1. **Before coding, inventory the remaining acceptance gaps for ONE existing task.** Read the live issue, route/API, screen reference, implementation, tests, release gates, active PRs and branches. Produce an explicit definition of done in that Issue, tagged `FEATURE BUNDLE`, including routes/screens, real data, AR/EN, roles, loading/empty/offline/error, security, QA, references, file owners and external blockers.
2. **Implement the whole ready slice before requesting merge:** for a cross-screen journey, wire navigation and related screens together; complete backend + UI adapters if needed, using LEAD-disjoint files or sequenced lane handoffs. Tests cover the journey and negative states. Avoid PRs that only add one label, button or trivial unit test when it naturally belongs in the same unfinished feature.
3. **One active branch/PR per task/lane at a time.** Reuse and expand an existing active PR for related acceptance; do not open a second PR for the same files or function. Separate dependency-ready lanes may execute concurrently only when actual parallel tools exist. A lane can be simulated sequentially, but never claimed to run autonomously.
4. **Integration: branch -> code + scoped tests -> cohesive PR -> exact-head required CI -> review evidence -> squash merge -> main verification -> issue acceptance audit.** Do not spam full workflow runs on each edit: develop/commit several related changes on one branch, then evaluate one authoritative latest-head gate; a failed check gets corrected **on that same PR**, after which its new head must pass. Keep existing two workflows and required checks; never merge red, incomplete or skipped-as-sole-evidence gates.
5. **After merging, update the parent Issue with a checklist of DONE / OPEN / BLOCKED-EXTERNAL acceptance.** Close an engineering task only if **all of its programmatically testable requirements** are met, and add evidence links/SHA; do not count its micro-merges as additional completed tasks. External hosting, secrets, signing and owner review go only to T025–T027, never disguised as shipping.
6. **Report results by customer-visible functionality and task acceptance, not PR volume**: completed journeys/screens; outstanding code gaps; tested head + CI result; merged SHA; actual staging/device/visual evidence; genuine blockers. A long list of tiny PRs is not evidence of higher completion.

### Narrow, justified exceptions

Make a focused hotfix PR immediately when it contains a security/privacy breach, regression, production outage, CI repair blocking other feature work or a genuinely independent small full acceptance unit. Link to the parent bundle, verify tests and exact-head CI, and **do not mark the parent task complete** merely for this exception. Avoid artificial minimum lines/files/commits: they encourage filler code. No giant cross-domain PR overriding `worker-contracts/path-ownership.json`; LEAD can coordinate sequential dependent PRs within one **journey completion window**, with no duplicate edits.

## Active completion bundles

Work through this *sequence of outcomes*, subject to live dependency/ownership checks, **without creating new task IDs**:

| Outcome bundle | Canonical issues | Engineering acceptance focus |
| --- | --- | --- |
| Public website visitor/recruiter journeys | T007 #8, T009 #10, T012 #13 | Entire Home/About/Expertise, verified portfolio/resume/contact, article/lab/search journeys; bilingual states and real data/evidence; all associated reference captures when runtime is available |
| Admin operations & AI controls | T016 #17, T020 #21 | Real dashboard, provider configuration/cost/health, editorial approval, image studio, rights/quality/queue workflows, cross-page navigation, role isolation |
| Flutter connected experience | T019 #20, T021 #22 | Auth/session, troubleshoot/solutions, learning/interview, saved/profile/preferences, navigation, AR/EN, offline/online/error states and account/privacy controls; no invented results |
| Integrated acceptance and launch | T024 #25, T025 #26, T026 #27, T027 #28 | Contract/security/a11y/performance/visual evidence, staging smoke/rollback, signing, then single complete owner handoff |

Dependencies remain canonical and no release-stage gate is assumed complete. Each issue's original acceptance, route and screen mapping governs the precise work; this table **does not grant permission to skip screen evidence**.

## Review checklist for each completion bundle

- [ ] One explicit supported journey works from entry to destination, including real authenticated/API behavior when relevant.
- [ ] All in-scope screens/actions and responsive AR/EN/LTR/RTL, keyboard/touch, permissions, loading/empty/error/offline states tested.
- [ ] No fabricated data or claims; source/photo/content rights, secrets, cost and rate caps preserved.
- [ ] Validated with relevant scoped tests, integrated exact-head `required-ci-gate` and verified main merge SHA.
- [ ] Approved reference screenshots actually compared at identical dimensions; pending entries remain **open**, never claimed visually complete.
- [ ] Parent Issue acceptance updated; unresolved code blockers stay OPEN; external release blockers moved to T025–T027.

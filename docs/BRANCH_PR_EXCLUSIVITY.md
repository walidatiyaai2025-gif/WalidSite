# ADR-012 — Issue / Branch / PR exclusivity (2026-11-02)

Owner directive: prevent abandoned branches, overlapping PRs and competing implementation of one Issue. This ADR is binding for LEAD and W1–W4 and supplements root `AGENTS.md`. The immutable v6 reference pack stays unchanged.

## Exclusive ownership before coding

1. Read live `main`, open/draft PRs, **all** branches, canonical Issue (including `Txxx` mapping), dependencies, path ownership and latest CI. Record Issue number, lane, branch, head/base SHA, exact files, acceptance and conversation-scoped lease.
2. **Exactly one active code branch and one open PR per Issue.** Reuse and extend existing branches and PRs, including drafts. A branch without an open PR still reserves its Issue until reconciled. Multiple agents/conversations cannot independently claim the same Issue or modify the same file.
3. For new work name branches `feat/<issue>-slug`, `fix/<issue>-slug`, `chore/<issue>-slug` or `feat/T007-slug` (Txxx maps to its original Issue). The PR title includes `#<issue>` or the correct Txxx. Issue #1 is the PROGRAM, **never** a generic code-work claim. No speculative branches.
4. LEAD alone coordinates overlapping paths, and must serialize competing branches rather than pushing divergent changes. A ready PR colliding with another ready PR on any file is blocked; a draft collision is reported and must be rebased/reconciled before it becomes ready. Do not create a second PR as a workaround for failing tests.
5. On every PR head and ready-for-review event, existing `required-ci-gate` invokes `qa/validate_pr_ownership.py` with read-only GitHub API access. It verifies canonical Issue state, unique open PR, exclusive branch even without a PR, ready-PR file collision, and that the tested SHA is the current PR SHA. API/read failure **blocks**, never silently passes. Other Issues' historical duplicates do not block independent tasks. Push to main still runs the standard full CI.
6. Merge only after latest-head required CI, applicable reviewers/policies, and verified no competing branch/PR. Post-merge cleanup is already SHA-pinned: delete **only** the merged feature/fix/chore branch if its current SHA still equals merged PR head, and do not delete any unmerged work, divergent branch, draft, release branch or protected main.
7. If duplication already exists: stop concurrent work, keep a primary existing PR, compare all commits/files, cherry-pick/reimplement missing changes in that primary branch, run complete exact-head CI, merge, verify main and only then remove superseded remote branches with an explicit proof record. Never force delete a branch with unmerged commits or assume a closed PR equals a merged PR.

## Existing legacy exception inventory at adoption

At the preflight there were multiple #480 branches and open PRs (#505/#506, with #502/#504 closed later), old #476 branches, and draft #499 sharing `.github/workflows/ci.yml`. **These are existing collisions, not authorized permanent exceptions.** The new gate blocks new #480 PRs until its branches/PRs are reconciled and blocks ready/ready shared-file changes. This policy PR can proceed while #499 remains a draft, but its overlap is logged and #499 must rebase/reconcile before ready/merge.

## Enforcement boundary

CI validates admission when a PR is opened/updated; it cannot prevent a branch from being created on GitHub, or an administrator/actor from bypassing CI by direct push or unprotected merge. **Main was observed unprotected at preflight**. Repository administrators should enable a GitHub ruleset/branch protection requiring `required-ci-gate` and banning direct pushes/force pushes/deletion of main. This must be *verified in GitHub settings* before claiming hard enforcement. The current connector has no branch-protection write action. No third workflow, no destructive cleanup of unmerged code.

## Evidence and maintenance

Pure unit tests: `python3 -m unittest qa.tests.test_pr_ownership -v`. Live PR check: `python3 qa/validate_pr_ownership.py` in PR CI using scoped `GH_TOKEN`. `required-ci-gate` must pass at the exact PR head. Add any newly found failure as a checklist item on the owning Issue, not a duplicate micro-Issue.

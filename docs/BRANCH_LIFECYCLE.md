# Branch lifecycle and duplicate-work prevention

## Desired steady state
After a task PR merges into `main`, remove the source branch **only after verifying the merge**; never delete `main`, `release/*`, active PR branches or branches with commits added after merge.

## Mechanism
The existing `.github/workflows/ci.yml` is the **only** CI workflow. It listens for PR close events and runs a separate minimal cleanup job only if `merged == true`, the source repository matches this repository, and branch prefix is `feat/`, `fix/` or `chore/`. It reads the current branch head and compares it with the merged PR's recorded head SHA before requesting GitHub branch deletion. It does not check out or execute untrusted PR code, and requires GitHub Actions `contents: write` permission. This is a narrow GitHub write privilege limited to cleanup job.

## Operational constraints
- An approved merged PR must have exact-head green CI before merging. Branch cleanup **does not** compensate for failed or unknown tests.
- Cleanup failures must be surfaced and reconciled as repository hygiene; do not claim successful deletion without a successful cleanup job or verifying absence on GitHub.
- The repo's native GitHub `delete_branch_on_merge` setting is currently false. If later enabled, both GitHub native deletion and the guarded job tolerate already-removed branches.
- Older branch `feat/T001-governance-and-ci` pre-dates this workflow and may require a one-time verified cleanup by a GitHub-admin-capable tool; do not equate it with new active work.
- No worker may create duplicate branch/PR for an active Issue; consult `AGENTS.md` and `tasks/github-issue-map.json`.

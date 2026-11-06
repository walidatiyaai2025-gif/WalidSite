# Public GitHub repository policy — T017

This read-only service is `App\\Integrations\\GitHub\\PublicRepositoryDirectory`.
Configure only explicitly approved public `owner/repo` names, using the server-side
`GITHUB_PUBLIC_REPOS` environment variable (comma-separated). Empty is the safe
default. Setting the allowlist does **not** itself publish content or create routes.
T018 will consume the directory in the real portfolio UI.

Safeguards:
- Never accept a URL from a browser/mobile request; only configured exact allowlist slugs.
- GitHub REST `GET https://api.github.com/repos/{owner}/{repo}` only, with no credentials.
- Both `private=false` and `visibility=public` plus exact full name and canonical HTTPS github.com link are required before returning a clickable URL.
- Successful public snapshots are cached for five minutes with a real verification timestamp. A failure or private/missing visibility returns `status=unavailable` and **no URL**; no stale fallback or invented statistics.
- Up to 20 explicitly allowed repos, no unbounded pagination, timeouts of 3/5 seconds and no retry storm.
- No access to private projects, contributor metrics, personal tokens, or internal employer repositories. The returned data is deliberately a small field allowlist; views must escape all remote description text.
- The owner must approve which repositories can appear publicly. T018 owns presentation/RTL/filtering, T008 owns broader read APIs, and T017 does not imply the final portfolio is live.

CI: `php artisan test --filter=PublicRepositoryDirectoryTest`. Staging GitHub connectivity is not claimed by mocked HTTP tests.

## ADMIN-08 owner-approved database proposals (#1094 / #476)

The independently authenticated Admin API persists proposal decisions in
`github_allowlist_proposals` and append-only `github_allowlist_proposal_events`.
The public directory reads the server-side `GITHUB_PUBLIC_REPOS` entries first,
then fills any remaining slots (combined maximum 20) with **approved** database
entries that have a valid non-future verification timestamp, distinct submitter
and reviewer, a non-future review timestamp, and a matching persisted approved
review event. Pending, rejected, self-reviewed, missing-receipt, or malformed
entries never become public directory candidates.

A database approval is **not** proof of continuing public visibility. Each
candidate must still pass the existing unauthenticated, no-redirect GitHub REST
check for public visibility, canonical identity and HTTPS permalink. The short
verified snapshot cache remains bounded to five minutes. A failed check never
returns a private or unverified identity/link. If migrations are not yet installed
or the database cannot be queried, only the pre-existing environment entries
are eligible. No owner tokens, arbitrary user URLs, private data, or provider
credentials are read or returned. This integration does not itself build the
owner's bilingual browser review UI or certify a live staging/release.

Test with `php artisan test --filter=ApprovedRepositoryDirectoryTest` and
`php artisan test --filter=PublicRepositoryDirectoryTest`; the exact-head
required CI and real hosting acceptance remain separate gates.

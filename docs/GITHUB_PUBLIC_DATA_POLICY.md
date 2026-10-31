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

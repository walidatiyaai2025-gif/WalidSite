# Security & privacy control contract
- Keep provider credentials encrypted server-side with write-only UI and key rotation.
- Admin MFA, CSRF, authn/authz/RBAC, signed media URLs and audit events.
- Read-only GitHub public data by allowlist, no private repo mirroring.
- AI tool invocation deny-by-default and prompt-injection defense around retrieved content.
- Sanitize legacy SQL content; exclude password hashes, user tables, subscribers and untrusted PHP extensions.
- User data minimization, consent for notifications, removal and exports; disaster recovery and encrypted backups.
- Dependency and SAST scanners in CI (optional scoped checks beyond 2 workflow files, not additional workflows).
- Public portfolio claim evidence and government confidentiality rules are release blockers.

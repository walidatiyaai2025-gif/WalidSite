# Identity security and administration (T002)

- All accounts are UUID-keyed, matching the v6 \`users.id\` blueprint.
- \`password_hash\` is the canonical password column. No hard-coded account or password is seeded.
- Admin web access uses Laravel's server-side session/CSRF protection, login throttling, session ID rotation and explicit logout. Roles owner/admin are enforced on the server; other accounts cannot access admin.
- Mobile API identity uses Laravel Sanctum, hashed bearer tokens with seven-day expiry, device scope and explicit revocation. Mobile tokens do not grant admin privileges even for an owner account.
- \`GET /api/v1/mobile/me\` requires authentication and active status. \`GET /api/v1/admin/identity-check\` is a guarded diagnostic endpoint, not a finished dashboard.
- Creating/provisioning the first owner is an operations activity, not automatic public registration. Provision through a reviewed secure administration process and never commit a real secret.
- Use TLS in deployments; the API should not receive credentials without HTTPS. Keys and actual DB credentials are environment-only.
- This task adds tables necessary for identity (\`roles\`, \`role_user\`, \`personal_access_tokens\`); broader profile, skills and certification schema remains T003.
- The admin UI remains intentionally unavailable (503) pending T016; it is not an inert fake dashboard.

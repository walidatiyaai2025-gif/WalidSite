# T002 Identity

UUID users and roles are server-side, based on v6 schema. Admin web sessions use Laravel CSRF, throttled login, session regeneration and logout. Only owner/admin roles access admin.

Mobile POST /api/v1/auth/login uses 7-day device-bound Sanctum bearer tokens. GET /api/v1/mobile/me and POST /api/v1/auth/logout require authenticated tokens. Mobile tokens never gain admin privileges.

The admin dashboard returns an honest HTTP 503 until T016; no fake actions. No real owner credentials are seeded. Provision the first owner through controlled operations, never through a public registration route.

TLS, production database, APP_KEY and deployment smoke are mandatory before release. The OpenAPI contract does not yet describe the new auth extension paths and requires LEAD reconciliation. Run php artisan test and review exact-head CI before merge.

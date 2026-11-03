# T024 — canonical API registration and negative authorization regression

## Source of truth and status (2026-11-03)

The canonical `api/openapi-contract.yaml` defines **42 paths / 46 HTTP operations**: **20 public** and **26 authentication-protected** (22 admin, 4 authenticated mobile). These are contract counts, not a live deployment certificate.

The earlier 2026-11-02 audit on `main@291a2c993b773a7c999f647983c5acd0e6f7d28a` found `POST /api/v1/admin/articles` missing (45/46 routes). This was subsequently **implemented and regression-tested** under Issue #619 in `backend/routes/api.php`, `AdminArticleController::store` and `backend/tests/Feature/Api/AdminArticleCreateTest.php`. The old temporary single-route exception in T024 is now unsafe and removed.

## Enforced integration checks

`backend/tests/Feature/Integration/ApiContractAccessMatrixTest.php` reads the **current contract** and runs through the actual Laravel route registry and HTTP test kernel:

1. Assert all 46 operations exist, no missing-operation allowlist; preserve all 42 unique paths and 26 restricted method definitions.
2. Verify private routes carry `auth:sanctum` and `RequireSecureApiTransport` and public routes are not accidentally login-gated.
3. Issue a real anonymous JSON HTTP request to **each of 26 protected endpoints**, including authenticated mobile and privileged Admin writes, and require HTTP 401.
4. Issue real bearer-authenticated requests to **each of 16 static Admin operations** with both an active reader carrying an admin token scope and an active owner carrying a mobile-only scope. Both must return HTTP 403 (32 negative role/ability requests). This denies role bypass and scope bypass independently; only rate-limit middleware is disabled within this authorization-specific test to avoid false throttle responses. Network calls must not occur.
5. Parameterized Admin route authorization, positive draft creation, DTO validation, HTTPS checks, and no-auto-publication are already tested by the dedicated API feature tests (not inferred from this matrix alone).

The matrix does **not** certify positive DTOs/response schemas for all 46 methods, MFA, third-party runtime providers, screenshot parity, WCAG 2.2 AA, real device experience, performance or external staging. Any failing negative access test is a security blocker requiring correction, not an accepted exception.

## Reproduce and acceptance boundaries

With Laravel dependencies and test database available:

```sh
cd backend
php artisan test --filter=ApiContractAccessMatrixTest
php artisan test --filter=AdminArticleCreateTest
php artisan test
```

The PR is eligible to merge only when `required-ci-gate` succeeds on the **exact final head SHA**, including the Laravel/MariaDB/Flutter pipeline. Keep Issue #25 OPEN until the separate 40 real pixel-matched screens under #481, 97 actions, all positive/negative contract responses, security/a11y/performance evidence, and the remaining internal G gates have independently passed. External staging and owner handoff remain #26/#28; CI is not deployment proof.

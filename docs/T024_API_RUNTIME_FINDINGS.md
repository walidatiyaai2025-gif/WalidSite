# T024 API operation / guest authorization runtime audit

## Canonical source and ownership

Based on checked-out `main@291a2c993b773a7c999f647983c5acd0e6f7d28a` on 2026-11-02, under Issue #25, LEAD-only integration QA. The immutable `api/openapi-contract.yaml` contains **42 paths and 46 HTTP operations**, with **26 role-restricted** and **20 public**. The source Laravel `backend/routes/api.php` registers **45 of the 46** after normalizing dynamic variable *names* (e.g., `{provider}` versus `{id}`). A route signature in source is not equivalent to a successfully tested API response.

## Concrete missing operation

`POST /api/v1/admin/articles` is documented as `admin` in the OpenAPI contract but absent from Laravel route registration. The list endpoint `GET /api/v1/admin/articles` is present, as are the separate publish and AI image routes. This means article **creation** cannot be certified against the published API contract; record the defect in #25 and route any actual W1 implementation through its owning backend Issue rather than creating a second LEAD route/controller.

## Executable, integrated regression

`backend/tests/Feature/Integration/ApiContractAccessMatrixTest.php` is discovered by the existing Laravel Feature PHPUnit suite. It reads **the actual canonical OpenAPI file**, enumerates every method/role pair, then introspects Laravel's **real runtime route registry**, checking `auth:sanctum` plus `RequireSecureApiTransport` on every restricted operation and rejecting authentication middleware on the 20 public operations.

It also issues guest JSON HTTP requests through Laravel's real test kernel against **each registered private operation**, asserting exact HTTP 401. It uses synthetic valid UUID *path parameters only*, never a user/token or real provider. No external AI/GitHub request, authorization bypass, data mutation or production service is needed. The existing CI executes this test on the exact PR commit as part of mandatory Laravel/MariaDB/Flutter tests.

The test permits **only the single known W1 contract gap**, but counts it as **UNTESTED**, not a passing API operation. Any additional unregistered route fails the test. After W1 adds POST /admin/articles, the same test accepts 46/46 without weakening policy or requiring a new test allowlist.

### Limits and next required evidence

Route registration plus unauthorized guest responses are not positive DTO/schema, MFA, owner-versus-admin RBAC, API security under authentication, rate-limit, SSRF, real service outages or end-to-end acceptance. Those still require separately proven positive/negative tests for **all 46** contract operations, including currently absent article creation, and the **40 genuine pixel-matched screens** under #481. The 97 screen actions, measured performance/soak, WCAG 2.2 AA, AR/EN responsive, offline/AI-provider failures and internal G gates remain open until independently verified. G-12 staging and G-20 owner handoff belong to T025–T027.

Do not close #25 or mark a contract operation accepted based solely on this test bundle.

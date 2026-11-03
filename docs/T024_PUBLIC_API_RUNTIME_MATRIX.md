# T024 — complete anonymous API operation matrix

**Canonical issue:** #25. **Source contract:** `api/openapi-contract.yaml` (42 paths, 46 methods). The public subset is 20 operations: **19 GET** (16 static, 3 parameterized) and **one consent-controlled POST** (`/public/contact`).

## What the executable matrix verifies

`backend/tests/Feature/Integration/PublicApiGuestContractMatrixTest.php` reads operations and roles directly from the canonical OpenAPI path section. It refuses a changed/partially enumerated contract and sends actual anonymous requests through the Laravel HTTP test kernel, with an isolated migrated test database and English/Arabic locale selection.

- All 16 static GET endpoints must return HTTP 200 and JSON with a `data` envelope in both `en` and `ar`. An unexpected login page, 401, 403, 500 or HTML response is a regression.
- All three parameterized guest endpoints (`/public/projects/{slug}`, `/public/articles/{slug}`, `/mobile/solutions/{id}`) use deliberately missing but well-formed values. Their response must be an honest JSON 404, not a fabricated project, article or guide.
- The only public write is the consent-based contact operation. With mail delivery deliberately disabled, submissions in both languages must return HTTP 503 without disclosing the inquiry or reporting a sent email.
- Outbound HTTP is prohibited; no live GitHub/AI services, recipient credentials or production resources are used. The verified GitHub allowlist is empty. Only throttling is disabled for this dense route-coverage matrix; its existing dedicated tests remain authoritative.

## Limits and acceptance

This is **real empty/negative-state Laravel integration coverage** with no network access. It does **not** certify every positive DTO/schema and data-rich response for all 46 operations, production mail delivery, authenticated access, performance, accessibility, screenshots, hosting or deployment. Existing endpoint feature tests and Issue #638 cover richer published-content and revocation cases. The mandatory `required-ci-gate` must pass on the exact final PR HEAD before merge; #25 remains open until the 97 UI action proofs, 40 actual zero-RGBA screenshot matches, positive/negative DTOs, and internal G-gate evidence are independently complete.

To reproduce in a configured local Laravel test environment:

```sh
cd backend
php artisan test --filter=PublicApiGuestContractMatrixTest
php artisan test
```

Do not copy fixture identities or empty responses into real QA evidence as proof of a deployed server.

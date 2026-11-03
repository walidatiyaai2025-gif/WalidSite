# API contract amendments

This file records owner-directed runtime API additions that cannot be written back into the checksum-protected v6 source file `api/openapi-contract.yaml`. The immutable baseline remains authoritative for its original methods; these dated amendments are additive and are verified against real Laravel routes and feature tests.

## 2026-11-02 — Issue #477 account privacy lifecycle

**Authority:** Issue #477 and the later complete-product closure matrix in Issue #1.

### GET /api/v1/mobile/account/export

Authentication: active Sanctum user with `mobile` ability.

Response: `200` JSON attachment containing only the authenticated user's profile, saved-resource references and notification preferences. Password hashes, bearer tokens, private AI prompts, provider credentials and other users are excluded.

Errors: `401` unauthenticated/expired token, `403` inactive or wrong scope, `413` bounded export safety limit, `422` unsupported input, `429` throttle.

### DELETE /api/v1/mobile/account

Authentication: active Sanctum user with `mobile` ability.

Input:

```json
{"password":"<current password>","confirmation":"DELETE"}
```

No target user identifier is accepted. Successful deletion reauthenticates, cancels owned in-flight mobile diagnosis work, erases private diagnosis input/saved resources/notification preferences, revokes all tokens, removes role links, anonymizes the account email and writes a non-PII deletion audit record.

Errors: `401`, `403`, `409`, `422`, `429`.

### GET /api/v1/mobile/notification-preferences

Authentication: active Sanctum user with `mobile` ability.

Returns the complete supported matrix. Missing persisted rows are represented as `enabled=false`; therefore consent is default-deny.

Purposes: `account_activity`, `editorial_updates`, `product_updates`.

Channels: `email`, `push`.

### PUT /api/v1/mobile/notification-preferences

Authentication: active Sanctum user with `mobile` ability.

Input:

```json
{
  "preferences": [
    {"purpose":"product_updates","channel":"push","enabled":true}
  ]
}
```

Between one and six unique purpose/channel entries are accepted. Opt-in records `consented_at`; opt-out sets `enabled=false` and records `revoked_at`. Duplicate or unknown purpose/channel combinations fail validation.

### Verification

Runtime registration and semantics are covered by `backend/tests/Feature/Api/AccountPrivacyLifecycleTest.php`. The baseline OpenAPI file is intentionally unchanged because CI validates it against `MANIFEST.json`. T024 must treat this additive amendment together with the immutable baseline when performing integrated route/schema coverage.

## 2026-11-03 — Issue #699 paid mobile AI diagnosis idempotency

**Authority:** Release freeze #732, backend #711, Flutter #748, open integrated security acceptance #699. This is an amendment to the existing **POST /api/v1/mobile/ai/diagnose**, not a new endpoint. The v6 OpenAPI baseline is checksum-protected and stays unchanged.

### POST /api/v1/mobile/ai/diagnose — idempotent paid admission

Authentication: active Sanctum user with a live bearer carrying `mobile` ability; affirmative `consent_to_process` in the JSON body is mandatory. Query string cannot supply consent or request fields. The accepted JSON body has `question` (4–2000 characters), `technology` (1–120), optional nullable `code_snippet` (up to 8000), and `consent_to_process: true`; unknown fields, files and unsupported query parameters are rejected.

**Header:** `Idempotency-Key: <opaque random UUIDv4>` is generated once per intended diagnosis by the current Flutter client. The backend accepts exactly 16–128 ASCII `[A-Za-z0-9_-]` characters; during legacy-client cutover the header is **optional in the server implementation**, not yet required for all historical clients. The key must be independent of the account email, request contents and bearer token. A cryptographic HMAC digest and canonical request fingerprint, not the plaintext key, gate replay in the database.

**200 success / replay:** The first accepted request can create a diagnosis/job. A deliberate same-key, same-authenticated-account, same-canonical-body and consent retry after an ambiguous timeout returns the **same `data.id` and current job status**, without an additional AI job or provider call. Once a job ID is known, the client polls status by GET rather than reissuing a potentially paid POST. Responses use `Cache-Control: no-store`.

**409 conflict:** Reuse of a key with another account or changed payload is rejected without disclosing the other account's job. **Never silently issue another key or automatically resubmit a paid POST to work around 409.** New diagnostic intent must be an explicit separate user action.

**Other responses:** `401` unauthenticated/revoked bearer, `403` suspended/unauthorized account, `422` invalid key/body/consent, `429` quota on a new paid admission, `503` missing selected provider/reviewed runbook or temporarily incomplete prior admission. Do not interpret a lost HTTP acknowledgement as a failed or uncharged request; offer an explicit same-key retry while the original request snapshot remains available. Never log raw prompts, bearer tokens, code snippets or raw intent keys.

### Outstanding P1 security acceptance — not certified by this amendment

When a request may have been accepted but its response is lost, the existing Flutter session-scrub path clears the private persisted key on explicit logout, token expiry or a `401/403` response. After reauthentication this can allocate a new key for the same intended paid operation. **This is an unresolved #699 duplicate-admission risk**, not a demonstrated provider charge. W3/W1 must deliver a privacy-safe recovery/reconciliation implementation and an actual lost-ACK → session expiry/logout → same-account reauthentication → retry integration test with exactly one diagnosis, one job, one paid provider call and one cost receipt. Retain no bearer or original private prompt persistently. Keep #699 OPEN; this documentation and source-alignment test do not certify provider billing, staging, or production readiness.

### Regression and rollout

The existing PHP `MobileAiIdempotencyTest.php` verifies keyed replay, conflict, revoked identities and quota with fixture-backed Laravel HTTP tests. The Flutter `mobile_paid_ai_http_transport_test.dart` checks genuine loopback lost-ACK header reuse while the same session is retained. `qa/tests/test_visual_mobile_ai_idempotency_contract.py` verifies this normative amendment and source integration under existing CI without modifying the pinned baseline. Post-release removal of optional-header compatibility requires coordinated client/server cutover and independent native acceptance; none is authorized by this release-freeze change.

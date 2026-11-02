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

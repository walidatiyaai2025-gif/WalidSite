# Account privacy lifecycle

Issue #477 implements the code-dependent portion of G-16. This document is an engineering data-flow record, not legal advice and not a claim that jurisdiction-specific retention language has been approved. Final legal wording and external provider/store obligations remain release gates under T026.

## Data inventory and technical retention

| Data | Storage | Exported to the account owner | Deletion behavior | Technical retention |
| --- | --- | --- | --- | --- |
| Email and account status | `users` | Yes | Email is replaced with a deterministic non-routable anonymized address, password hash is removed, status becomes `deleted` | PII removal is immediate on successful deletion |
| Password hash | `users.password_hash` | No | Set to null | Immediate |
| Bearer tokens | `personal_access_tokens` | No | All tokens for the account are deleted | Immediate |
| Saved resources | `saved_resources` | Yes | Deleted | Immediate |
| Notification preferences | `notification_preferences` | Yes | Deleted; all admission becomes deny | Immediate |
| Mobile diagnosis private input | `ai_diagnosis_requests.request_blob` | No | Diagnosis rows are deleted | Immediate |
| In-flight mobile diagnosis job | `ai_jobs` + `job_leases` | No | Queue/running/retry jobs are cancelled, result reference cleared, lease removed | Operational non-PII job metadata remains under the general AI job lifecycle |
| Deletion audit | `account_deletion_events` | No | Stores only a one-way subject hash, reason code, cancelled-job count and timestamps | Engineering audit record; jurisdiction-specific retention duration requires legal approval before production release |

No legal basis or statutory retention period is asserted by the application. Production policy text must not claim a duration until it is approved. The implemented code minimizes retained account data immediately and keeps the surviving deletion event free of email, password, prompt text, token values, provider secrets and content payloads.

## API behavior

- `GET /api/v1/mobile/account/export` returns only the authenticated account's profile, saved-resource references and notification preferences. Secrets, password hashes, bearer tokens, private AI prompts and other users are excluded. The self-service export fails explicitly if saved-resource volume exceeds the safety bound rather than silently truncating.
- `GET /api/v1/mobile/notification-preferences` returns the full supported purpose/channel matrix. Missing rows are returned as disabled.
- `PUT /api/v1/mobile/notification-preferences` accepts explicit per-purpose/per-channel choices. Opt-in records `consented_at`; opt-out records `revoked_at` and immediately blocks `NotificationConsentGate`.
- `DELETE /api/v1/mobile/account` requires the current password plus the literal `DELETE` confirmation. It does not accept a target user ID, so cross-account deletion is not addressable through the endpoint.

All four operations require an active account, an unexpired Sanctum token with the `mobile` ability, secure API transport and route throttling.

## Deletion transaction and race handling

Deletion takes a database lock on the user. Any owned mobile diagnosis rows are locked, their active queue jobs are cancelled, leases are removed, and encrypted diagnosis input is deleted before account PII is anonymized. Saved resources, notification preferences, role links and all bearer tokens are removed in the same transaction. A replay using the old bearer token is therefore unauthenticated.

A worker that already holds private input in process memory cannot be retroactively erased from that process; the database-side cancellation and lease removal prevent subsequent durable continuation. Real-provider runtime behavior remains a staging/release verification obligation and must not be represented as tested without credentials.

## Notification admission

`App\Domain\Privacy\NotificationConsentGate` is fail-closed. Delivery is allowed only when:

1. the user is still active;
2. purpose and channel are recognized;
3. an explicit preference row exists;
4. `enabled = true`;
5. `consented_at` is present; and
6. `revoked_at` is null.

Admin Audience counts distinct active users satisfying those persisted consent conditions. Its read endpoint still reports delivery disabled because no send is performed by that endpoint.

## Mobile and QA handoff

T021 should consume the three mobile privacy surfaces above rather than storing server consent only on-device. T024 should exercise export, opt-in, revocation, deletion/replay, throttling, expired-token behavior and the account-delete/AI-job race on the integrated build. External legal approval, push credentials and store disclosures remain release gates and are not marked complete by #477.

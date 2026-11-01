# Admin AI model registry and diagnosis route REST API — T016

**Status:** Implemented API extension on the T016 feature branch. This is a dated implementation addendum, **not a modification to the checksum-protected v6 OpenAPI source** `api/openapi-contract.yaml`. The immutable v6 contract remains exactly unchanged. Source of executable truth: `backend/routes/api.php`, `backend/app/Http/Controllers/Api/AdminAiTaskRouteController.php` and `backend/tests/Feature/Api/AdminAiTaskRouteJourneyTest.php`.

All endpoints below are relative to `/api/v1`, require secure transport in production and a valid **active administrator account** with a real Sanctum PersonalAccessToken containing the `admin` ability. Session-only authentication, mobile-ability tokens and reader accounts are forbidden. Admin UI browser forms retain their separate CSRF-protected routes. All responses are `no-store`.

## Complete API workflow

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/admin/ai/models` | 25-per-page real model registry; optional `provider_id` UUID and `page` (1–10000) |
| POST | `/admin/ai/models` | Create a model for an active provider; upstream ID, enabled state, text support |
| PATCH | `/admin/ai/models/{model}` | Enable/disable an existing model; no arbitrary pricing or secret edits |
| GET | `/admin/ai/task-routes/mobile-ai-diagnose` | Read saved route and recorded-state model eligibility; no live provider call |
| PUT | `/admin/ai/task-routes/mobile-ai-diagnose` | Save primary/fallback model IDs, budget and per-user limits |

POST request example (real IDs come from the administrator's provider registry; these illustrative UUIDs are **not** credentials or real registered models):

```json
{
  "provider_id": "00000000-0000-4000-8000-000000000001",
  "provider_model_id": "model-name",
  "enabled": true,
  "supports_text": true
}
```

PUT request shape:

```json
{
  "primary_model_id": "00000000-0000-4000-8000-000000000001",
  "fallback_model_ids": [],
  "currency": "USD",
  "reservation_minor": 25,
  "per_minute": 3,
  "per_day": 30
}
```

All model UUIDs must exist under active providers, be enabled and support `text`. At most 8 distinct fallback UUIDs, not repeating the primary. Paid model pricing currency, provider configured currency and task reservation currency must agree and the paid reservation must be strictly positive. Rate limits must satisfy 1–60 per minute, 1–10000 per day, and daily ≥ minute. Extra fields are rejected rather than silently persisted. Changes are transactionally stored in the same `ai_models`/`ai_routes` consumed by the mobile diagnosis selector.

Model registry responses expose only model ID, provider ID/display name, upstream model ID, capabilities, enabled status and configured pricing currency. Task routing responses expose saved IDs/budget/rate limits, and an **observation-only** current selection. They never expose provider secrets, provider base URLs, raw requests, private data, actual remaining prepaid balance, live connectivity guarantees or simulated AI responses.

Expected result codes: `200` successful read/update, `201` model created, `401` missing authentication, `403` insufficient admin scope or insecure transport, `404` missing model or archived provider, `422` invalid/unsupported input, `429` rate limited. No paid AI network requests are initiated by this management API. The deployment and original admin design-pixel fidelity remain unverified, and T016 is not wholly accepted until remaining criteria pass.

## Verification

Run the feature tests `php artisan test --filter=AdminAiTaskRouteJourneyTest` in the Laravel test environment and the repository's existing exact-head `required-ci-gate` for Laravel/MariaDB/Flutter. Confirm tests, merged SHA and main CI in the T016 Issue. The fixed v6 checksum validation must remain green: no changes to `api/openapi-contract.yaml`, worker v6 artifacts or their manifest.

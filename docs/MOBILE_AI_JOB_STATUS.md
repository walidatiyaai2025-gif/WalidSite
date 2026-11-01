# Mobile AI diagnosis job-status extension — T019

This additive endpoint complements the immutable v6 POST diagnosis contract without
rewriting the checksum-protected source API reference.

## GET `/api/v1/mobile/ai/jobs/{id}`

- `id` must be a UUID returned by `POST /api/v1/mobile/ai/diagnose`.
- The request requires a valid Sanctum bearer with the `mobile` ability and
  an active account. Missing authentication receives 401, wrong ability or
  inactive account receives 403. Unknown, malformed or other-user job IDs
  receive 404 (never enumerate private requests).
- Read-only and rate-limited (60/min); **no enqueue, retry, provider call,
  quota reservation, or spending** occurs on GET.
- The response is `Cache-Control: no-store`, with precisely these fields:

```json
{
  "data": {
    "id": "11111111-1111-4111-8111-111111111111",
    "status": "queued",
    "progress_percent": 0,
    "result_url": null,
    "retry_after_seconds": 30
  }
}
```

The UUID above is an illustrative schema example, **not** an actual issued job.
`status` is one of `queued`, `running`, `completed`, `failed`,
`blocked`, `cancelled`. A completed job may have a reviewed solution URL
only if a reviewed runbook was selected. The API never returns prompts,
snippets, model output, API credentials, provider metadata or private input.

The Flutter client must poll this endpoint (never POST diagnosis again to
refresh an existing queued job), respect the advised retry delay, avoid
unbounded polling/background work, and recover explicitly from 401/403/404,
offline and expired sessions. A completed result still must be independently
validated through the existing reviewed-runbook detail endpoint.

This is backend engineering evidence only; live scheduled workers and remote
provider availability are not implied. UI device screenshots and staging
verification remain separate under T024–T027.

# Reviewed runbook offline publisher — MOBILE-05 / MOBILE-06

This is a **private publisher preparation tool**, not an automatic production
publication, AI provider integration, user account cache, or app deployment.
Only run it after the exact reviewed public runbook UUID/version has cleared
the backend's real `PublicRunbookController::verifiedData` publication gate
(`offline_ok`, approved title, reviewed version, content checksum and review
timestamp). Rejected, revoked and unreviewed content fails closed.

## One explicit signing operation

A release publisher with PHP 8.3 and the sodium extension provisions an
Ed25519 keypair **outside this repository**. The private 64-byte libsodium
secret key is passed to the CLI process through
`WALIDSITE_RUNBOOK_SIGNING_KEY_B64` (standard Base64). It must never be
checked in, printed, passed as a CLI argument, stored in a browser or uploaded
to an admin dashboard. The mobile release is compiled with the corresponding
32-byte public verification key in `WALIDSITE_RUNBOOK_PUBLIC_KEY` and a
reviewed immutable HTTPS URL in `WALIDSITE_RUNBOOK_PACK_URL`.

Run from `backend/`, with separately provisioned private process environment:

```bash
php artisan runbooks:prepare-signed --id=12345678-1234-4234-8234-123456789012
```

Repeat `--id=<UUID>` for up to 20 unique currently reviewed IDs. The command
does not generate any content, call an AI provider, or automatically publish.
It writes only to `backend/storage/app/private/runbook-publisher/` as a
mode-0600, SHA-256-addressed JSON artifact. Existing candidates cannot be
overwritten. The console prints only the candidate filename and digest, never
the key or source markdown.

A separate trusted release manager must verify the candidate file SHA-256,
approval status, content rights, revocation policy and key ownership **again**
before uploading it to the immutable HTTPS URL. No URL, publisher key or
production signing receipt is bundled with the source. The app must be built
with the actual approved URL and pinned public key. HTTPS alone is NOT proof
of verified content; the Flutter client verifies the Ed25519 signature and
the signed per-publication markdown SHA-256.

## Cross-client format and scope

The signed envelope contains exact signed UTF-8 payload bytes:

`walidsite.signed-runbook-pack.v1`:
`schema`, `payload_base64`, `signature_base64`.

The underlying `walidsite.offline-runbook-pack.v1` has canonical version,
trusted timestamp, a bounded list of up to 20 published UUIDs, and for each
reviewed entry: exact `published_version`, `published_markdown`,
`published_validated_at` and `published_body_sha256`, alongside its
reviewed title. No fabricated translation or unreviewed content is issued.

In Flutter, clicking Download refreshes the current public review, then
requires all four values and the UUID to match this **signed** pack before
saving. Mismatching, stale, revoked, interrupted or unsigned downloads must
retain the previously trusted offline snapshot; bundled educational drafts
are never treated as downloaded live publications.

**Limitations until release acceptance:** no production publisher or
approved delivery URL/key has been provisioned; changing a published item may
leave old signed offline content on a previously disconnected device. Offline
readers must see provenance/staleness warnings and must not assume that a
locally cached snapshot is currently authorized for production operations.
Actual app-device screenshot/DPR acceptance belongs to #481; installation,
hosted smoke, signature custody and release readiness remain #25/#26/#27/#28.

## Verification

Run the scoped Laravel tests:

```bash
cd backend
php artisan test --filter=SignedPublishedRunbookPublisherTest
```

GitHub's required exact-head CI additionally runs the complete Laravel,
MariaDB, Flutter, ownership, and policy gates. The presence of a signed
candidate does **not** mean deployment or safe live delivery has succeeded.

# Legacy content import (G-17)

This engine is for **owner-authorized historical content only**. It never executes SQL from the source file. It tokenizes a deliberately small subset of literal `INSERT INTO ... VALUES ...;` statements and maps accepted rows into unpublished review queues.

## Required source contract

The first line must be exactly:

```sql
-- walidsite-legacy-schema: 1
```

Only these source tables are accepted: `articles`/`legacy_articles`, `projects`/`legacy_projects`, `project_evidence`/`legacy_project_evidence`, and `runbooks`/`legacy_runbooks`. Their allowlisted columns are defined in `LegacyContentImporter::TABLES`. Unknown tables/columns, expressions, DDL, comments beyond the required version header, backslash-ambiguous strings, unsafe URLs, XSS-capable markup, remote Markdown images, path traversal, symlinks, oversized input, invalid UTF-8 and broken project-evidence relationships are rejected.

Identity/accounts, passwords, tokens, subscriber lists, private employer data, arbitrary media and existing verified/public records are never import targets.

## Safety semantics

- Maximum source size: 2 MB; maximum parsed rows: 500.
- Only files under `storage/app/legacy-import` with a simple `.sql` filename are exposed by the Artisan command.
- Default command behavior is **dry-run**. `--apply` refuses the whole source if any row/statement is rejected.
- Imported articles remain draft with unverified citations and failed validation gates.
- Imported projects remain private/unpublished; evidence remains private/unverified.
- Imported runbooks remain unvalidated, offline-disabled and have no reviewed title.
- Existing target slugs are never overwritten.
- SHA-256 record receipts make retries idempotent. The database stores hashes of source filename and legacy key rather than their raw values.
- All writes run in one database transaction. Any unexpected write failure rolls back targets and receipts together.

## Operator sequence

1. Place an authorized, sanitized export in `storage/app/legacy-import/<name>.sql`; never commit the dump.
2. Run `php artisan legacy:import-content <name>.sql`.
3. Resolve every rejection. The dry-run output contains only statement/row/table/reason metadata, counts and source SHA-256; it does not echo raw legacy values.
4. Preserve the approved source SHA-256 in the deployment record.
5. Run `php artisan legacy:import-content <name>.sql --apply` only after the dry-run is clean and the source authorization is documented.
6. Review imported drafts/private records through the normal evidence, rights and editorial gates before any publication.

## Recovery and staging handoff

Application-level validation failures perform no writes. Unexpected database/model failures occur inside a single transaction and roll back the entire import. If a database/platform failure leaves an uncertain outcome, do **not** rerun blindly: compare the source SHA-256 and `legacy_import_receipts`, verify every referenced target exists, restore the pre-import database backup if integrity is uncertain, then rerun the dry-run.

The repository contains no authorized historical production dump. Engineering completion uses synthetic hostile/valid fixtures only. Real backup, authorized source receipt, dry-run output, apply receipt, post-import integrity checks and rollback/restore evidence remain T025 staging obligations.

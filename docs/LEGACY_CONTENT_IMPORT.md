# Safe legacy content import (G-17)

Issue #478 provides an offline, allowlist-only migration path for historical **content**. It is not a generic SQL restore tool. No historical production dump has been supplied or approved by this implementation, so engineering acceptance uses synthetic fixtures only; an authorized real-data dry run remains a T025 staging obligation.

## Operator entry point

Place one reviewed file under `storage/app/legacy-import/` and use a simple `.sql` filename.

```bash
php artisan legacy:import-content legacy-export.sql
php artisan legacy:import-content legacy-export.sql --apply
```

The first command is always dry-run. `--apply` is an explicit mutation and still refuses the entire import when any statement or row is rejected. The command prints counts, bounded rejection codes and the source SHA-256 only; it never prints imported text, email addresses, secrets, SQL values or provider credentials.

The reader resolves the real path, forbids traversal/symlink sources, accepts only `.sql`, caps the file at 2,000,000 bytes and 500 parsed rows, and requires valid UTF-8. The parser never submits source SQL to MariaDB/SQLite/PDO. It recognizes only literal `INSERT INTO ... (...) VALUES (...);` statements and implements its own string/integer/NULL tokenizer. DDL, UPDATE/DELETE, comments attached to statements, functions/expressions, ambiguous backslash escaping and unterminated statements are rejected.

## Accepted staging schema

The source must be exported/transformed into one of the following explicit staging shapes. Prefixing table names with `legacy_` is supported and recommended. No other table or column is accepted.

| Source | Required columns | Optional columns | Destination behavior |
| --- | --- | --- | --- |
| `articles` / `legacy_articles` | `slug,title_en,title_ar,canonical_lang,body_md` | `legacy_key,citations_json` | New Article + revision 1, always draft; citations are stored as unverified and validation is false |
| `projects` / `legacy_projects` | `slug,title,summary_en,summary_ar,status` | `legacy_key,role_en,role_ar` | New Project, always private and unpublished |
| `project_evidence` / `legacy_project_evidence` | `project_slug,evidence_type,url` | `legacy_key` | May attach only to a project created by this importer; always private and unverified |
| `runbooks` / `legacy_runbooks` | `slug,title,body_md,version` | `legacy_key` | New runbook/version, always unvalidated and offline-disabled |

Supported project statuses are `completed`, `in_progress`, `research`, and `archived`. Supported evidence types are `github`, `documentation`, `case_study`, `reference`, and `other`.

The importer has no mapping for users, passwords, roles, subscribers, notification recipients, tokens, sessions, AI credentials, employer-confidential tables, assets or media. Their presence therefore fails closed instead of silently skipping private material.

## Sanitization and publication safety

Slugs use a strict lowercase ASCII slug grammar. Text must be valid UTF-8 and respects destination length limits. Unsafe active markup such as scripts, iframes, event handlers, `javascript:` and `data:` is rejected. Markdown image syntax and HTML images are rejected so an old dump cannot import unlicensed or remote media.

Evidence and citation URLs must be public HTTPS URLs without credentials, custom ports, IP literals, internal/local host suffixes or secret-bearing query/fragment keys. Imported citations are explicitly persisted with `verified=false`; imported article revisions have citation/technical validation false. Existing publication review code must independently approve any later publication.

No import can overwrite an existing article, project or runbook slug. Project evidence can attach only to a project whose provenance receipt proves it came from this importer, preventing a legacy dump from modifying an already verified owner project.

## Dry-run, idempotency and provenance

Each normalized row receives a deterministic SHA-256 content hash. Applied rows write a `legacy_import_receipts` record containing source filename, source SHA-256, record hash, source table/key, target type/id and import timestamp. Replaying the same normalized content is counted as a duplicate and creates no new target row, even when the source file is renamed.

If a provenance receipt points at a missing target, the importer rejects the row rather than silently recreating it. Conflicting rows that target the same slug but normalize to different hashes are rejected before writes.

## Transaction and rollback behavior

The apply path first performs a complete parse/validation/preflight. If **any** rejection exists, no write transaction starts. A clean plan is applied inside one database transaction, with projects created before their evidence. A database constraint, race, or unexpected exception rolls back the whole batch; the operator should fix the source/state and rerun dry-run. The 2 MB / 500-row cap deliberately keeps this atomic transaction bounded rather than using unsafe partial chunk commits.

Recovery procedure:

1. Keep the original authorized file and its reported SHA-256.
2. If apply fails, do not edit database rows manually; rerun dry-run after the underlying conflict is understood.
3. If an applied imported record must be withdrawn before review, use a reviewed database rollback/maintenance procedure keyed by `legacy_import_receipts`; never delete existing non-imported owner content by slug.
4. A successful code-level import is not publication. Editorial evidence, rights, privacy and approval gates remain mandatory.

## Real-data release gate

A production/staging legacy dataset must have a documented owner/source authorization and be converted to this staging schema without confidential records. T025 must record the real file hash, dry-run report, backup/restore evidence, applied counts (if approved), and rollback verification. This issue does not claim that any historical data has been received, sanitized, licensed or imported.

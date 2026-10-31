# Canonical first-run CV data contract — owner instruction

This requirement is binding from the first operational release: **Waleed must not be asked to type, re-enter or repair his existing professional CV data through an Admin form.** Canonical source: `contracts/owner-cv-baseline.json` transcribed from the two owner-supplied DOCX files:

- `Senior_SQL_DBA_Realistic_Final.docx`
- `Waleed_TPM_Riyadh_Hybrid.docx`

## Required runtime behavior (T003 / #4)

1. Implement typed profile, role/experience, skill, certification, project, CV source and provenance models and migrations; names, dates, experience, certifications and claims must match the owner documents without embellishment.
2. Ship a deterministic idempotent first-run seeder that imports the approved JSON at installation/staging migration time. On a second run it MUST NOT duplicate records or overwrite owner-edited fields, later updates or AI-acquired verified additions.
3. Give public Website, mobile CV panels and Admin the same source records via versioned API. Public homepage must render source-derived facts immediately after seed without dummy text, Lorem Ipsum, fake percentages or generated professional claims.
4. Treat dual DBA and PM roles for the same employer/time period as **one overlapping employment period with multiple roles**; no double-counting of years.
5. Publish broad work history and skill descriptions, but redact confidential system details until approved. Government case studies are restricted. Education, degree, awards, exact KPI, years-count and certificate validity not established by the attached CV must remain absent or clearly unverified; certification titles are self-reported CV claims, not independently checked.
6. Never insert user tables/password hashes/subscribers from the legacy SQL. Never publish stale visa date or privately sensitive items from CV.
7. The real provided portrait is the only owner-photo source; preserve provenance SHA from approved asset inventory.
8. Add tests for seed idempotence, source attribution, public visibility filtering, AR/EN presentation, and a blank database immediately populated with approved CV data after seeding.

## Governance
- No routine owner intervention during development; only verified staging preview feedback.
- Changes to this baseline must be source-backed and reviewed; AI-generated content cannot rewrite owner-authored experience/certification records.
- Source data here is a content contract, **not** evidence that the DB seed or rendered page is already implemented.

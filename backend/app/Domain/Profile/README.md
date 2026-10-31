# T003 — Canonical CV-first data schema

Run `php artisan migrate` and `php artisan db:seed --class=OwnerCvBaselineSeeder` from `backend/` when `../contracts/owner-cv-baseline.json` is deployed alongside it.

The owner-supplied canonical CV is the **only** seed source. Idempotent `firstOrCreate` preserves subsequent editorial updates. It creates a **passwordless, pending-activation** identity anchor without roles, so it cannot sign in until controlled provision. It seeds the four historical positions, all stated technical competencies and all five self-reported certifications; none are misrepresented as independently verified. The general CV summary becomes a **private review claim**, not a public verified claim. Profile status is `review` and career publishability defaults to false until the editorial evidence gate is implemented. Certificates have null unknown issuers/dates and no invented evidence.

The source gives year-only job periods; date columns normalize start to Jan 1 and end to Dec 31 strictly for database indexing, with `date_precision=year`. Frontends must display the years only. Arabic skill/role strings currently retain technical source wording rather than invent an unreviewed translation. The original portrait is a reference asset in `assets/portraits/`; T003 does not fabricate or assign an asset UUID before the asset registry exists.

No credential, historic SQL dump, private infrastructure information or account passwords are seeded. Verify with `php artisan test --filter=OwnerCvBaselineTest`. Release/staging still requires credential provisioning, editorial verification and full app checks.

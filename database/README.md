# Schema blueprint (NOT a migration)
The JSON file is the canonical MVP data-model contract. A backend worker converts it to versioned Laravel migrations and models with tests; never assume a schema was deployed because this file exists.

Foreign keys, unique constraints, encodings, migrations, seed fixtures, and online changes must be verified against the deployed MariaDB version. No production data or old SQL dump is bundled.

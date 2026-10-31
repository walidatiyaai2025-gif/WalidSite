# WaleedSite Laravel foundation — T001

This directory contains a real Laravel 13 scaffold, based on the official MIT-licensed Laravel starter. It does not imply deployed services or completed product modules.

Requirements: PHP 8.3+, Composer 2. MariaDB 10.11+ is the production data target in `../database/schema-blueprint.json`. The SQLite bootstrap is only for local development/test and carries no approved production schema.

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
php artisan test
```

Route `/` displays a neutral foundation page, and `/up` is framework health. No generated portrait, fabricated credentials, certifications, achievements or fake live AI are present.

**Task boundaries:** T002 owns secure authentication/RBAC, T003 owns UUID database migrations, T004 owns actual web design, T005 owns Flutter platform runners, T006+ owns configurable AI. No default Laravel integer-ID migrations were imported, since they would conflict with the canonical UUID schema. No Vite package.json was added until real frontend build tooling is implemented.

Production environments require unique secrets (especially APP_KEY), secure database credentials, queue budgets and staging smoke validation. Never reuse the phpunit test-only key in deployment.

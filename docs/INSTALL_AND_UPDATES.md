# WalidSite first-run setup and trusted updates

This is an **installer for a prepared Laravel host**, not a public database-credentials editor, Git puller, ZIP/PHP uploader or a fully automatic hosting provisioner. Requires PHP 8.3+, Composer, the approved MariaDB database, web server pointed to backend/public, and standard private writable Laravel storage.

## First boot (server operator; never commit credentials)

1. Deploy **reviewed** application source (including the sibling contracts directory), run Composer and build frontend assets. Give storage and bootstrap/cache normal Laravel permissions. Take a DB backup if reusing a database.
2. Provision a fresh database and database user in the host panel. Set APP_KEY, APP_URL, DB_CONNECTION=mariadb (or mysql), DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_ENV=production, APP_DEBUG=false, SESSION_DRIVER=file, CACHE_STORE=file in private backend/.env. Generate APP_KEY server-side with php artisan key:generate before visiting the wizard. Never use the test key.
3. Generate a cryptographically random setup token (at least 32 characters), set SITE_SETUP_ENABLED=true and SITE_SETUP_TOKEN in the private .env. If config is cached, run php artisan config:clear or config:cache after changes. Never expose the token in URLs, screenshots or logs.
4. Open **/setup** over HTTPS. Complete Verify → Database check and migrate → Activate actual owner account with a strong password. The CV seeder uses ../contracts/owner-cv-baseline.json, never a fabricated profile or public admin signup. A filesystem lock and existing admin identity prohibit rerunning setup.
5. After finishing, REMOVE SITE_SETUP_TOKEN, set SITE_SETUP_ENABLED=false, re-cache config, and sign in at /admin/login. Deny web access to .env, storage and project root with web-server configuration.

If the DB or APP_KEY are not configured, the wizard cannot configure them remotely. Errors fail closed. If a first install is interrupted, inspect the DB and private Laravel logs before retrying. An activated existing owner blocks reinstall even if the lock file is missing.

## Trusted patch and migration handling

The owner-only **Admin → Updates & patches** screen shows SITE_RELEASE_REF (an operator-supplied release identifier), a DB connection check and exactly which checked-in migrations have not run. POST Apply (CSRF protected) runs only Laravel's own migrate --force with a non-overlapping cache lock, then re-checks the migration repository. There is no arbitrary uploaded code or downloading unreviewed ZIP packages. The application log records counts/failures; this is not a substitute for backups.

For unattended updates **after the trusted code release has been installed by your deployment pipeline**, set SITE_AUTO_MIGRATE=true and run the normal cPanel cron every minute:

    cd /absolute/path/to/WalidSite/backend && /usr/bin/php artisan schedule:run >/dev/null 2>&1

The site's existing scheduler dispatches site:updates:apply every five minutes, but **only** when SITE_AUTO_MIGRATE=true. Host cron is a real external prerequisite; a GitHub merge alone does not update your live server. Use a shared atomic lock backend for multi-node installs, never the array cache. SiteUpdater compares existing database/migrations/*.php against Laravel's migrations table and does not execute anything if already current. Failed/missing connections are never shown as "up to date".

For safe production patches: backup database and files; deploy a tested, versioned artifact from a green GitHub CI commit; upload/replace reviewed PHP and vendor assets via a trusted release deployment; invoke migrations automatically or via the owner-only panel; smoke test health/routes; record the release SHA. Destructive schema changes, data backfills, and incompatible application versions need their own phased migration and verified rollback. Schema rollbacks are **not automatically safe**. To avoid outages, keep SITE_AUTO_MIGRATE=false until backup/rollback and cron have been verified. The update panel never performs git pull/composer install or arbitrary PHP execution.

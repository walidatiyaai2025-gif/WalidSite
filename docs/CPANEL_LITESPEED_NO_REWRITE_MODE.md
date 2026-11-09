# cPanel LiteSpeed No-Rewrite URL Mode (Issue #1120)

**Production symptom, 2026-11-09:** On the owner's `https://walidatiya.com` host, the Laravel homepage and `/index.php/admin/login`, `/index.php/admin`, `/index.php/admin/updates`, `/index.php/expertise`, `/index.php/projects` worked, but the corresponding clean paths returned a LiteSpeed 404. A temporary exact `.htaccess` rewrite returned 404; cPanel's document root showed `/home/walidatiya/public_html`. This does **not** establish the host's underlying cause or authorize unsafe changes.

## Reversible application-level workaround

The optional **`SITE_FRONT_CONTROLLER_URLS=true`** is read through `config('app.front_controller_urls')` and is OFF by default. Once explicitly enabled on a reviewed, installed compatible release, Laravel's URL generator creates dynamic route, link, form action and redirect URLs with `/index.php/`. It keeps `APP_URL` as the canonical **HTTPS domain root without `/index.php`**, and keeps `asset()` links on that normal root (or an explicitly configured `ASSET_URL`). No arbitrary request Host or query can change the routing origin. Existing CSRF, authorization, rate limits and signed-route checks remain enforced.

This mode does not reconfigure LiteSpeed and **does not make incoming clean paths functional**. External bookmarks, inbound links, SEO canonicals, emails, API clients and native apps using clean paths may still 404. The root homepage may work without the prefix, but generated dynamic links need it. Do not hide or claim this as a production-wide rewrite fix; confirm forms, all admin operations, multilingual links, media, signed URLs and downloads on the physical site.

For a properly configured rewrite server keep the flag absent/false and use standard clean URLs.

### cPanel activation — only after a verified application release

1. Ensure the correct verified, compatible code is installed and the site and MariaDB backups are recoverable. Do **not** extract an unsigned ZIP over a live site, alter `APP_KEY`, reinstall or reset the database.
2. With File Manager (no SSH or Composer), add the **non-secret** line `SITE_FRONT_CONTROLLER_URLS=true` to the **private** `/home/walidatiya/application/.env` only after a verified release and only with the real `APP_URL=https://walidatiya.com` (no path) already configured. Never share or expose the rest of `.env`.
3. The configuration may be cached. Ensure the supported update/maintenance mechanism rebuilds Laravel configuration; do not arbitrarily delete protected or unknown cache/maintenance files on production. If the route URLs still remain clean, treat the activation as unverified.
4. Test `/index.php/admin/login` (GET and POST with CSRF), redirect to `/index.php/admin`, `/index.php/admin/updates`, public/en/ar routes and physical assets. A healthy response is **not** evidence that the 0.1.0→0.1.x database/code upgrade or signed automated Cron release is complete.
5. When LiteSpeed rewrite is repaired and validated, remove the flag and verify normal canonical clean links again.

## Release boundary

This is a **code-level compatibility patch**, not a published GitHub Release or evidence of an activated Cron publisher. The current legacy host and existing issue #1120 still require a publisher-signed and upgrade-tested release, cPanel host adapter, restore rehearsal, real HTTPS cutover and GitHub branch protection before automatic deployment can be declared working.

Acceptance tests: `php artisan test --filter=CpanelNoRewriteUrlsTest` on a CI/dev runner (not an owner production command), plus mandatory exact-head PR CI.

## CI regression evidence (owner-approved pages)

The owner-frozen home (WEB-01) and expertise (WEB-03) templates and the current
about (WEB-02) page intentionally do not share the old T007 hero structure.
The release-blocking browser matrix must check their *actual* visible
headings, local-language links, AR/EN journeys and keyboard navigation without
replacing approved markup. The 390px expertise rail is horizontally scrollable:
a focused link must become visible under native browser focus scrolling, with a
bounded settling interval and a failure receipt containing the index/rail
geometry if it remains clipped. No test may set scrollLeft, programmatically
scroll a failed link into view, discard a failing viewport, or reinterpret a
pixel mismatch as a visual match.

This remediation is code-level only. A green exact-head CI and a signed,
approved, physically installed cPanel release are independently required.

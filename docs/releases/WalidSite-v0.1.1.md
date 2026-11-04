# WalidSite 0.1.1 — Home Header Language Radio

**Release type:** PATCH candidate · Target: existing 0.1.0 installations

## Changes

- Move the homepage language switch to the top header next to the Waleed Atiya identity.
- Use native, keyboard-operable EN / عربي radio inputs on desktop and mobile, with the selected locale reflected in both radio groups.
- Preserve the established page language query `?lang=en` / `?lang=ar`, RTL support, navigation, content controls and approved portrait.
- Add Laravel home acceptance tests and an explicit, tightly scoped exception to the otherwise unchanged WEB-01/03/04 design freeze.

## Packaging and verification

The exact-head CI run builds:
- `WalidSite-Setup-v0.1.1.zip` (full fresh installation; **not** to be extracted over an existing active site).
- `WalidSite-update-v0.1.1.zip` (code-only **UNSIGNED INSPECTION CANDIDATE**, including checksum and provenance receipt).

The update package is **NOT an installable production patch**. The current `/admin/updates` verifies a publisher-authenticated Ed25519 signed package tied to a configured trusted HTTPS publisher and installed version; it does **not** perform a live code cutover on the existing cPanel physical-directory layout. A SHA256 sidecar is integrity, **not** authenticity.

**Never upload the unsigned archive to the production Update screen expecting it to install code.** Do not overwrite `.env`, storage, personal uploads, MySQL records, installation locks or APP_KEY. Existing site 0.1.0 stays live until a verified compatible, signed updater with tested backup and recovery has been accepted on this host.

## Acceptance gates

- Exact-head Laravel/Flutter tests.
- Exact-head Setup ZIP actual HTTP + MariaDB first-install acceptance.
- Cross-language and mobile/desktop header control tests.
- Valid Setup ZIP manifest, ZIP checksum and code-only update inspection inventory.
- **Not yet accepted**: browser pixel fidelity on `walidatiya.com`, cryptographically signed publisher publication, cPanel owner's real code Apply, independent MariaDB restore and atomic rollback/forward-recovery proof.

This document is release notes, not a claim of production deployment.

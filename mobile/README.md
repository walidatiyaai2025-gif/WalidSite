# Waleed Tech Companion — Flutter bootstrap (T001)

This folder contains **executable Flutter UI and widget tests**, not an Android/iOS store package yet. Generate platform runners once mobile CI and developer signing requirements are checked; do not pretend APK/IPA artifacts exist.

## Development

```bash
cd mobile
flutter pub get
flutter analyze
flutter test
flutter run
```

The bootstrap has functional in-app navigation to informational topic screens, with deliberately honest forthcoming content. Task T005 owns the complete Flutter scaffold/platform runners and agreed design system, T013/T019/T021 own product flows. Do not add placeholder statistics, mock API results or fake live AI.

Use only the approved source image and `design/tokens.json` for future branding; the current W mark is a text glyph until vector assets are imported from canonical `assets/brand/`.

## T005 bilingual theme increment

The mobile shell uses canonical v6 light design colors and Flutter SDK localization delegates to switch between English/LTR and Arabic/RTL without a service call. Topic cards navigate to real localized details pages; future content is clearly marked unavailable. No owner portrait is generated.

`flutter pub get`, `flutter analyze`, and `flutter test` are enforced in PR CI.

**Platform runner limitation:** Android/iOS runner directories are not yet checked into this repo. A developer with a current Flutter SDK can generate and review those files from `mobile/` using `flutter create --platforms=android,ios .` before a signed/device build. Do not mark T005 fully complete, release an APK/IPA, or close its Issue on widget tests alone. The canonical owner will only test after a verified app build is available.


## T013 offline navigation and packaged runbooks

The Home → Offline runbooks → Runbook detail flow works from an application asset without an HTTP request, including after a cold restart or in airplane mode. A version, original bundle source, last sync and prominent stale/unverified warning are displayed on library and detail views. The bundled content is short, original educational draft guidance only: **never treat it as a verified operational runbook**.

This is the first offline-cache fallback, NOT the completed remote sync/download feature: the runbook API, signed update integrity check, persisted downloaded snapshots, storage quotas, and verified content publishing belong to later integration. A missing/invalid bundle displays a real error state; there is no fabricated live server data. CI runs `flutter analyze` and `flutter test`.

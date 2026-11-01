# Waleed Tech Companion â€” Flutter bootstrap (T001)

This folder contains executable Flutter UI, widget tests, and generated **Android/iOS native project runners**. Platform scaffolds are not signed store artifacts; production package identifiers, signing, iOS/Xcode and staging verification are still required.

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

**T005 platform runner status (2026-11-01):** Generated from Flutter stable 3.44.8 with `flutter create --platforms=android,ios --project-name waleed_tech_companion .`. Android launcher and iOS AppIcon images were derived from the approved `../assets/app-icons/app-icon-*.png` assets rather than Flutter demo artwork. The app title is `Waleed Tech Companion`. Both platform project trees are source controlled; no signing keys or certificate profiles are included. **Flutter-generated `com.example` Android application ID and iOS bundle identifier are temporary development placeholders** and must be replaced with approved identifiers before any store submission. Flutter analyze and widget tests passed on Windows; iOS Xcode/device/signing and staging are unverified. T005 remains open until release acceptance evidence is complete.


## T013 offline navigation and packaged runbooks

The Home â†’ Offline runbooks â†’ Runbook detail flow works from an application asset without an HTTP request, including after a cold restart or in airplane mode. A version, original bundle source, last sync and prominent stale/unverified warning are displayed on library and detail views. The bundled content is short, original educational draft guidance only: **never treat it as a verified operational runbook**.

This is the first offline-cache fallback, NOT the completed remote sync/download feature: the runbook API, signed update integrity check, persisted downloaded snapshots, storage quotas, and verified content publishing belong to later integration. A missing/invalid bundle displays a real error state; there is no fabricated live server data. CI runs `flutter analyze` and `flutter test`.


## T019 verified article reading (increment)

The bilingual Home â†’ Verified technical articles â†’ Article detail route reads actual
source-verified public article JSON from the implemented Laravel endpoint
`/api/v1/public/articles?lang=en|ar`. Configure the HTTPS **origin only** at
build time with `--dart-define=WALIDSITE_API_ORIGIN=https://your-real-host.example`;
no production hostname, credentials, or sample articles are compiled into the app.
Without an approved endpoint, the UI says the service is not configured. Network,
response/size, evidence-validation and empty states fail closed. Article bodies are
shown as **plain selectable Markdown source**, never interpreted as arbitrary HTML.
Sources are displayed for examination, not claimed to prove a hands-on execution.

This is **not** completed T019: there is no authenticated mobile AI diagnosis or
solution endpoint yet, no secured mobile article sync, bookmark persistence,
offline article cache, actual device build or staging verification. The offline
runbooks in T013 are independent educational fallback data, **not** substitute
verified online article content. Do not claim AI diagnosis or publish results.

## T005 Android release security (2026-11-01)

The Android main manifest now declares `android.permission.INTERNET` for **release** as well as debug/profile, because the verified article reader uses the approved HTTPS public API. The main application explicitly rejects cleartext traffic. No network request is made if the configured origin is absent.

Release APK signing no longer falls back to Flutter's debug keystore: the release variant remains **unsigned until a verified store identity is installed securely**, not from a committed key. `com.example.waleed_tech_companion` remains a development-only application ID and must be replaced with an approved package ID before distribution. These changes do not imply an uploaded APK, production signing, iOS device smoke, or staging verification. `mobile/test/platform_security_test.dart` guards these native source constraints in Flutter CI.

## T005 automatic device language (2026-11-01)

On initial launch, the Flutter shell selects Arabic/RTL if the device primary
locale is Arabic (including regional variants), otherwise English/LTR. Manual
language switching remains available without network access or added permissions.
The choice is session-local: restarting follows the device again, not a claimed
persistent preference. Widget tests cover Arabic launch/manual switching and
unsupported-language fallback.

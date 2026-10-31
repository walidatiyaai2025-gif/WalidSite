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

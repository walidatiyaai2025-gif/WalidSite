# Android QA native build — P0 release-freeze gate (#751, #732, #27)

The existing **PR CI** workflow now compiles an **actual debug-signed Android APK** on the exact #751 PR head, and on successful `main` pushes. It reuses the two existing workflows; it does not create a new Actions workflow, an iOS build, a production release, or a deploy.

## What a successful run proves

1. `required-ci-gate` passed on the exact checked-out commit before the native build job.
2. `flutter build apk --debug` completed with an installed Java 17 / Android SDK / stable Flutter toolchain, without modifying W3-owned Flutter source.
3. `qa/android_qa_build_metadata.py` read the expected Android package ID and version from checked-in `mobile/android/app/build.gradle.kts` and `mobile/pubspec.yaml`. A missing or ambiguous identity fails CI.
4. The pre-existing independent `qa/native_android_acceptance.py` checked **real APK bytes**, ZIP/CRC/path safety, AndroidManifest package/version/INTERNET permission via `aapt`, and debug APK signature via `apksigner`. It wrote a fail-closed JSON receipt.
5. The output artifact named `WalidSite-Android-debug-QA-<SOURCE-SHA>` contains `WalidSite-Android-debug-QA.apk`, the `.apk.sha256` sidecar, `android-qa-verification.json` and `android-qa-build-metadata.json`. Its source identity is the GitHub checkout SHA (confirmed by `git rev-parse HEAD`), **not** an independently attested release publisher signature.

**Get the APK:** open the successful GitHub Actions **PR CI** run on `main`, locate `build-android-qa-apk` = success and download its named artifact. Extract on a trusted machine; verify `sha256sum -c WalidSite-Android-debug-QA.apk.sha256`, check the JSON receipt and install only on a test device. The artifact ZIP that GitHub wraps around the APK is not the APK itself. Do not ask testers to install the public original reference images as app screenshots.

## Known release restrictions

- The Android Gradle `applicationId` is now `com.walidatiya.walidsite`, the source-defined reverse-DNS **release candidate**. A non-placeholder ID does not prove Google Play package ownership, distribution approval, signing or device installation; these gates remain pending. The CI derives, never invents, the current version from `pubspec.yaml`.
- The debug APK uses Android debug signing, suitable **only for QA install**. `distribution_ready=false`, `device_install_launch_verified=false`, `ios_build_verified=false`; no Play/App Store signing key, AAB/IPA, TestFlight, store listing, production entitlements, original 40-screen zero-RGBA acceptance, provider credentials, or staging URL is implied.
- A passing Flutter `analyze`/`test` cannot substitute for a compiled Android APK. A passing native build cannot substitute for a **real device/emulator install + launch smoke**, bilingual app acceptance, or verified owner preview.
- If Android SDK/Gradle/build tools cannot compile or `aapt`/`apksigner` rejects the binary, the native job fails and **no successful verified artifact** is published. Do not bypass the check to get a green badge.
- T026 #27, release #732 and staging #26 remain OPEN until their independent full acceptance, secure updates, verified backups and external approvals are complete.

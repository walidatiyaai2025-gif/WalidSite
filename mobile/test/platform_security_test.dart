import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Android release has Internet permission and rejects cleartext traffic', () {
    // Read the release-merged source manifest, not debug/profile overlays:
    // a permission only in those overlays will not ship in a release APK.
    final manifest = File(
      'android/app/src/main/AndroidManifest.xml',
    ).readAsStringSync();

    expect(
      RegExp(
        r'<uses-permission\s+android:name="android.permission.INTERNET"\s*/>',
      ).hasMatch(manifest),
      isTrue,
      reason: 'The production HTTPS article reader needs Internet permission.',
    );
    expect(
      manifest,
      contains('android:usesCleartextTraffic="false"'),
      reason: 'Do not allow HTTP fallback for production article requests.',
    );
  });

  test('Android release never inherits development debug signing', () {
    final buildScript = File(
      'android/app/build.gradle.kts',
    ).readAsStringSync();

    expect(
      buildScript,
      isNot(contains('signingConfig = signingConfigs.getByName("debug")')),
      reason: 'Store releases must never be silently signed by debug keys.',
    );
  });
}

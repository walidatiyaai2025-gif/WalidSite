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

  test('Android and iOS use the same non-placeholder candidate identity', () {
    const id = 'com.walidatiya.walidsite';
    final gradle = File('android/app/build.gradle.kts').readAsStringSync();
    final activity = File(
      'android/app/src/main/kotlin/com/walidatiya/walidsite/MainActivity.kt',
    ).readAsStringSync();
    final ios = File('ios/Runner.xcodeproj/project.pbxproj').readAsStringSync();

    expect(gradle, contains('namespace = "$id"'));
    expect(gradle, contains('applicationId = "$id"'));
    expect(gradle, isNot(contains('com.example.')));
    expect(activity, contains('package $id'));
    expect(activity, contains('class MainActivity : FlutterActivity()'));
    expect(ios, contains('PRODUCT_BUNDLE_IDENTIFIER = $id;'));
    expect(ios, contains('PRODUCT_BUNDLE_IDENTIFIER = $id.RunnerTests;'));
    expect(ios, isNot(contains('com.example.')));
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

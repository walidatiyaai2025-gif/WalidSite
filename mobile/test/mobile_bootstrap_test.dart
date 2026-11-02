import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/bootstrap/mobile_bootstrap.dart';
import 'package:waleed_tech_companion/main.dart';

const origin = 'https://api.example.org';

String bootstrap({
  bool arabic = false,
  bool aiAvailable = true,
  Object? privacy,
  Map<String, dynamic>? routes,
  Map<String, dynamic>? availability,
}) {
  final locale = arabic ? 'ar' : 'en';
  return jsonEncode({
    'data': {
      'app_name': arabic ? 'رفيق وليد التقني' : 'Waleed Tech Companion',
      'locale': locale,
      'default_locale': 'en',
      'supported_locales': ['en', 'ar'],
      'guest_entry_allowed': true,
      'available_themes': ['light'],
      'routes': routes ?? {
        'home': '/api/v1/mobile/home',
        'articles': '/api/v1/mobile/articles',
        'runbooks': '/api/v1/mobile/runbooks',
        'learning_paths': '/api/v1/mobile/learn',
        'saved_resources': '/api/v1/mobile/saved',
        'ai_diagnosis': '/api/v1/mobile/ai/diagnose',
      },
      'availability': availability ?? {
        'guest_reading': true,
        'guest_runbooks': true,
        'guest_learning_paths': true,
        'authenticated_ai_diagnosis': aiAvailable,
        'server_saved_resources': true,
        'saved_resources_requires_auth': true,
      },
      'privacy_policy_url': privacy,
    },
  });
}

void main() {
  test('MOBILE-01 bootstrap parser accepts exact safe bilingual capabilities', () {
    final en = MobileBootstrapCapabilities.parse(
      bootstrap(),
      false,
      Uri.parse(origin),
    );
    expect(en.appName, 'Waleed Tech Companion');
    expect(en.locale, 'en');
    expect(en.defaultLocale, 'en');
    expect(en.aiDiagnosisAvailable, isTrue);
    expect(en.privacyUri, isNull);

    final ar = MobileBootstrapCapabilities.parse(
      bootstrap(arabic: true, aiAvailable: false),
      true,
      Uri.parse(origin),
    );
    expect(ar.appName, 'رفيق وليد التقني');
    expect(ar.locale, 'ar');
    expect(ar.aiDiagnosisAvailable, isFalse);
    expect(ar.privacyUri, isNull);
  });

  test('MOBILE-01 bootstrap parser rejects unsafe or stale capability claims', () {
    final invalid = <String>[
      bootstrap(privacy: 17),
      bootstrap(privacy: 'https://evil.example/privacy?lang=en'),
      bootstrap(privacy: '/terms?lang=en'),
      bootstrap(privacy: '/privacy?lang=ar'),
      bootstrap(privacy: '/privacy?lang=en#secret'),
      bootstrap(routes: {
        'home': '/api/v1/mobile/home',
        'articles': '/api/v1/mobile/articles',
        'runbooks': '/api/v1/mobile/runbooks',
        'learning_paths': '/api/v1/mobile/learn',
        'saved_resources': '/api/v1/mobile/saved',
        'ai_diagnosis': '/api/v1/mobile/fake-ai',
      }),
      bootstrap(availability: {
        'guest_reading': true,
        'guest_runbooks': true,
        'guest_learning_paths': true,
        'authenticated_ai_diagnosis': true,
        'server_saved_resources': false,
        'saved_resources_requires_auth': true,
      }),
      bootstrap().replaceFirst('"available_themes":["light"]',
          '"available_themes":["light","dark"]'),
      bootstrap().replaceFirst('"guest_entry_allowed":true',
          '"guest_entry_allowed":false'),
      bootstrap().replaceFirst('"locale":"en"', '"locale":"fr"'),
      bootstrap(privacy: '/privacy?lang=en').replaceFirst(
        '"privacy_policy_url":"/privacy?lang=en"',
        '"privacy_policy_url":"/privacy?lang=en","private_key":"SECRET"',
      ),
      'x' * (128 * 1024 + 1),
    ];
    for (final body in invalid) {
      expect(
        () => MobileBootstrapCapabilities.parse(
          body,
          false,
          Uri.parse(origin),
        ),
        throwsFormatException,
      );
    }
  });

  testWidgets('unconfigured production Welcome remains truthful and startable offline',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('welcome-bootstrap-unconfigured')), findsOneWidget);
    expect(find.textContaining('Offline guest entry remains available'),
        findsOneWidget);
    await tester.scrollUntilVisible(find.byKey(const Key('welcome-start')), 120);
    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();
    expect(find.byType(CompanionHome), findsOneWidget);
  });

  testWidgets('canonical null privacy stays ready with truthful unavailable state',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileBootstrapPanel(
          arabic: false,
          fetch: (_) async => MobileBootstrapCapabilities.parse(
            bootstrap(aiAvailable: true),
            false,
            Uri.parse(origin),
          ),
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('welcome-bootstrap-ready')), findsOneWidget);
    expect(find.byKey(const Key('welcome-privacy-unavailable')), findsOneWidget);
    expect(find.byKey(const Key('welcome-privacy')), findsNothing);
    expect(find.textContaining('has not been published yet'), findsOneWidget);
  });

  testWidgets('future verified privacy route is validated before opening',
      (tester) async {
    Uri? opened;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileBootstrapPanel(
          arabic: false,
          fetch: (_) async => MobileBootstrapCapabilities.parse(
            bootstrap(
              aiAvailable: true,
              privacy: '/privacy?lang=en',
            ),
            false,
            Uri.parse(origin),
          ),
          openPrivacy: (uri) async {
            opened = uri;
            return true;
          },
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('welcome-bootstrap-ready')), findsOneWidget);
    expect(find.text('AI diagnosis is available after sign-in.'), findsOneWidget);
    await tester.tap(find.byKey(const Key('welcome-privacy')));
    await tester.pumpAndSettle();
    expect(opened, Uri.parse('$origin/privacy?lang=en'));
  });

  testWidgets('bootstrap failure is redacted and retry can recover',
      (tester) async {
    var calls = 0;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileBootstrapPanel(
          arabic: true,
          fetch: (_) async {
            calls++;
            if (calls == 1) {
              throw const FormatException('PRIVATE_BOOTSTRAP_TRACE');
            }
            return MobileBootstrapCapabilities.parse(
              bootstrap(arabic: true, aiAvailable: false),
              true,
              Uri.parse(origin),
            );
          },
          openPrivacy: (_) async => true,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('welcome-bootstrap-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_BOOTSTRAP_TRACE'), findsNothing);
    expect(find.textContaining('تعذر التحقق'), findsOneWidget);

    await tester.tap(find.byKey(const Key('welcome-bootstrap-retry')));
    await tester.pumpAndSettle();

    expect(calls, 2);
    expect(find.byKey(const Key('welcome-bootstrap-ready')), findsOneWidget);
    expect(find.text('تشخيص الذكاء الاصطناعي غير متاح حاليًا.'), findsOneWidget);
  });

  testWidgets('privacy launch failure is explicit without leaking exceptions',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileBootstrapPanel(
          arabic: false,
          fetch: (_) async => MobileBootstrapCapabilities.parse(
            bootstrap(privacy: '/privacy?lang=en'),
            false,
            Uri.parse(origin),
          ),
          openPrivacy: (_) async {
            throw const FormatException('PRIVATE_LAUNCH_TRACE');
          },
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('welcome-privacy')));
    await tester.pumpAndSettle();

    expect(find.text('Could not open the privacy disclosure.'), findsOneWidget);
    expect(find.textContaining('PRIVATE_LAUNCH_TRACE'), findsNothing);
  });
}

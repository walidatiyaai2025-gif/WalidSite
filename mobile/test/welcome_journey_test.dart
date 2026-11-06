import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/bootstrap/mobile_bootstrap.dart';

void main() {
  testWidgets('MOBILE-01 is the production entry and Start enters Home once',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('mobile-welcome-screen')), findsOneWidget);
    expect(find.byKey(const Key('welcome-start')), findsOneWidget);
    expect(find.byKey(const Key('welcome-language')), findsOneWidget);
    expect(find.byKey(const Key('offline-runbooks-entry')), findsNothing);
    expect(find.text('Welcome to Waleed Tech Companion'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('welcome-title')))),
      TextDirection.ltr,
    );

    await tester.scrollUntilVisible(find.byKey(const Key('welcome-start')), 120);
    await tester.ensureVisible(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('mobile-welcome-screen')), findsNothing);
    expect(find.byType(CompanionHome), findsOneWidget);
    await tester.scrollUntilVisible(find.byKey(const Key('offline-runbooks-entry')), 120,
      scrollable: find.descendant(of: find.byKey(const Key('mobile-home-scroll-feed')), matching: find.byType(Scrollable)),
    );
    expect(find.byKey(const Key('offline-runbooks-entry')), findsOneWidget);
    expect(
      Navigator.of(tester.element(find.byType(CompanionHome))).canPop(),
      isFalse,
      reason: 'Start replaces launch state; Back must not expose a fake prior route.',
    );
  });

  testWidgets('language can be chosen before Start and persists into Home',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(find.byKey(const Key('welcome-language')), 120);
    await tester.ensureVisible(find.byKey(const Key('welcome-language')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('welcome-language')));
    await tester.pumpAndSettle();

    expect(find.text('مرحبًا بك في رفيق وليد التقني'), findsOneWidget);
    expect(find.text('ابدأ'), findsOneWidget);
    expect(find.text('English'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('welcome-title')))),
      TextDirection.rtl,
    );

    await tester.scrollUntilVisible(find.byKey(const Key('welcome-start')), 120);
    await tester.ensureVisible(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();

    expect(find.text('رفيق وليد التقني'), findsOneWidget);
    expect(find.byKey(const Key('offline-runbooks-entry')), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byType(CompanionHome))),
      TextDirection.rtl,
    );
  });

  testWidgets('MOBILE-01 language switch discards stale bootstrap locale', (tester) async {
    final english = Completer<MobileBootstrapCapabilities>();
    final arabic = Completer<MobileBootstrapCapabilities>();
    final requested = <bool>[];
    await tester.pumpWidget(WaleedTechApp(
      bootstrapFetch: (isArabic) {
        requested.add(isArabic);
        return isArabic ? arabic.future : english.future;
      },
    ));
    await tester.pump();
    expect(requested, [false]);
    expect(find.byKey(const Key('welcome-bootstrap-loading')), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('welcome-language')));
    await tester.tap(find.byKey(const Key('welcome-language')));
    await tester.pump();
    expect(requested, [false, true]);
    expect(find.text('مرحبًا بك في رفيق وليد التقني'), findsOneWidget);

    english.complete(MobileBootstrapCapabilities(
      appName: 'Waleed Tech Companion',
      locale: 'en',
      defaultLocale: 'en',
      aiDiagnosisAvailable: false,
      privacyUri: Uri.parse('https://api.example.org/privacy?lang=en'),
    ));
    await tester.pump();
    expect(find.byKey(const Key('welcome-bootstrap-ready')), findsNothing);

    arabic.complete(MobileBootstrapCapabilities(
      appName: 'رفيق وليد التقني',
      locale: 'ar',
      defaultLocale: 'en',
      aiDiagnosisAvailable: true,
      privacyUri: Uri.parse('https://api.example.org/privacy?lang=ar'),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('welcome-bootstrap-ready')), findsOneWidget);
    expect(find.text('تشخيص الذكاء الاصطناعي متاح بعد تسجيل الدخول.'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('welcome-title')))),
      TextDirection.rtl,
    );
  });

  testWidgets('MOBILE-01 offline guest Start remains usable during stalled API',
      (tester) async {
    final pending = Completer<MobileBootstrapCapabilities>();
    await tester.pumpWidget(WaleedTechApp(
      bootstrapFetch: (_) => pending.future,
    ));
    await tester.pump();
    expect(find.byKey(const Key('welcome-bootstrap-loading')), findsOneWidget);
    await tester.ensureVisible(find.byKey(const Key('welcome-start')));
    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();
    expect(find.byType(CompanionHome), findsOneWidget);
    expect(find.byKey(const Key('mobile-welcome-screen')), findsNothing);
  });

  testWidgets('Arabic device locale applies to Welcome before any user action',
      (tester) async {
    tester.binding.platformDispatcher.localeTestValue = const Locale('ar', 'KW');
    addTearDown(tester.binding.platformDispatcher.clearLocaleTestValue);

    await tester.pumpWidget(const WaleedTechApp());
    await tester.pumpAndSettle();

    expect(find.text('مرحبًا بك في رفيق وليد التقني'), findsOneWidget);
    expect(find.text('ابدأ'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('welcome-title')))),
      TextDirection.rtl,
    );
  });
}

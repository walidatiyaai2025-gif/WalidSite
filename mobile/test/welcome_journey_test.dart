import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';

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

    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('mobile-welcome-screen')), findsNothing);
    expect(find.byType(CompanionHome), findsOneWidget);
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

    await tester.tap(find.byKey(const Key('welcome-language')));
    await tester.pumpAndSettle();

    expect(find.text('مرحبًا بك في رفيق وليد التقني'), findsOneWidget);
    expect(find.text('ابدأ'), findsOneWidget);
    expect(find.text('English'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('welcome-title')))),
      TextDirection.rtl,
    );

    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();

    expect(find.text('رفيق وليد التقني'), findsOneWidget);
    expect(find.byKey(const Key('offline-runbooks-entry')), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byType(CompanionHome))),
      TextDirection.rtl,
    );
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

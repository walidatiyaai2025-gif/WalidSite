import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/account/account_journey.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

const _id = '00000000-0000-4000-8000-000000000001';

void main() {
  testWidgets('MOBILE-01 native screen ID disappears on Start into Home',
      (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(const WaleedTechApp());
    expect(find.bySemanticsIdentifier('walidsite.mobile.01'), findsOneWidget);
    await tester.tap(find.byKey(const Key('welcome-start')));
    await tester.pumpAndSettle();
    expect(find.bySemanticsIdentifier('walidsite.mobile.01'), findsNothing);
    expect(find.text('Waleed Tech Companion'), findsWidgets);
    handle.dispose();
  });

  testWidgets('MOBILE-06 native ID is attached to genuine offline detail',
      (tester) async {
    final handle = tester.ensureSemantics();
    const guide = OfflineRunbook(
      id: 'offline-only',
      titleEn: 'Offline recovery',
      titleAr: 'استرداد دون إنترنت',
      stepsEn: ['Check the local state'],
      stepsAr: ['راجع الحالة المحلية'],
    );
    const pack = OfflineRunbookPack(
      version: '0.1.0', source: 'Educational bundled fixture',
      lastSynced: null, verifiedAt: null, items: [guide],
    );
    await tester.pumpWidget(const MaterialApp(
      home: OfflineRunbookDetail(arabic: false, item: guide, pack: pack),
    ));
    expect(find.bySemanticsIdentifier('walidsite.mobile.06'), findsOneWidget);
    expect(find.text('General guidance steps'), findsOneWidget);
    expect(find.bySemanticsIdentifier('walidsite.mobile.04'), findsNothing);
    handle.dispose();
  });

  testWidgets('MOBILE-04 ID exists only after reviewed solution data loads',
      (tester) async {
    final handle = tester.ensureSemantics();
    final reviewed = ReviewedRunbook(
      id: _id, title: 'Reviewed technical recovery',
      markdown: '# Only reviewed steps',
      version: 2, validatedAt: DateTime.utc(2026, 10, 31),
    );
    await tester.pumpWidget(MaterialApp(
      home: ReviewedRunbookDetail(
        id: _id, arabic: false,
        loader: (_) => Future<ReviewedRunbook>.value(reviewed),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.bySemanticsIdentifier('walidsite.mobile.04'), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-body')), findsOneWidget);

    // An unreviewed/withdrawn response must not receive the marker, even
    // though the Flutter detail route and its AppBar remain visible.
    await tester.pumpWidget(MaterialApp(
      home: ReviewedRunbookDetail(
        key: const ValueKey('withdrawn'),
        id: _id, arabic: true,
        loader: (_) async => throw const FormatException('Not approved'),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.bySemanticsIdentifier('walidsite.mobile.04'), findsNothing);
    expect(find.byKey(const Key('online-runbook-detail-unavailable')),
        findsOneWidget);
    handle.dispose();
  });

  for (final arabic in [false, true]) {
    testWidgets('MOBILE-11/12 IDs reflect only authenticated selected tab: $arabic',
        (tester) async {
      final handle = tester.ensureSemantics();
        final page = jsonEncode({
        'data': <Object>[],
        'meta': {'current_page': 1, 'has_more_pages': false},
      });
      await tester.pumpWidget(MaterialApp(
        home: MobileAccountJourney(
          arabic: arabic,
          loginAction: (_, __) async =>
              const MobileAiSession(token: '1|fixture', expiresAt: null),
          profileAction: (_) async => const MobileAccount(_id, 'qa@example.test'),
          savedAction: (_, __, pageNumber) async =>
              MobileSavedPage.parse(page, pageNumber),
          logoutAction: (_) async {},
        ),
      ));
      // No authenticated private route exists before the real account data.
      expect(find.bySemanticsIdentifier('walidsite.mobile.11'), findsNothing);
      expect(find.bySemanticsIdentifier('walidsite.mobile.12'), findsNothing);
      expect(find.bySemanticsIdentifier('walidsite.mobile.account.locked'),
          findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('account-email')), 'qa@example.test');
      await tester.enterText(
        find.byKey(const Key('account-password')), 'fixture-only');
      await tester.tap(find.byKey(const Key('account-login')));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('walidsite.mobile.11'), findsOneWidget);
      expect(find.bySemanticsIdentifier('walidsite.mobile.12'), findsNothing);
      expect(find.text(arabic ? 'المحفوظات' : 'Saved resources'), findsWidgets);
      await tester.tap(find.byKey(const Key('account-profile-tab')));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('walidsite.mobile.12'), findsOneWidget);
      expect(find.bySemanticsIdentifier('walidsite.mobile.11'), findsNothing);
      expect(find.text(arabic ? 'الملف الشخصي' : 'Personal profile'), findsOneWidget);
      await tester.tap(find.byKey(const Key('account-signout')));
      await tester.pumpAndSettle();
      expect(find.bySemanticsIdentifier('walidsite.mobile.11'), findsNothing);
      expect(find.bySemanticsIdentifier('walidsite.mobile.12'), findsNothing);
      expect(find.bySemanticsIdentifier('walidsite.mobile.account.locked'),
          findsOneWidget);
      handle.dispose();
    });
  }
}

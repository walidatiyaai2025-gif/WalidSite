import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:waleed_tech_companion/main.dart';

void main() {
  testWidgets(
      'T021 home reaches every canonical MOBILE-08 through MOBILE-13 destination',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.pumpAndSettle();

    Future<void> openDestination(Key entry, Finder destination) async {
      await tester.pumpWidget(const WaleedTechApp());
      await tester.pumpAndSettle();

      final list = find.byType(ListView).first;
      final tile = find.byKey(entry);
      for (var attempt = 0;
          attempt < 20 && tile.evaluate().isEmpty;
          attempt++) {
        await tester.drag(list, const Offset(0, -320));
        await tester.pumpAndSettle();
      }

      expect(tile, findsOneWidget, reason: 'Home entry must be reachable by scrolling.');
      await tester.ensureVisible(tile);
      await tester.pumpAndSettle();
      await tester.tap(tile);
      await tester.pumpAndSettle();
      expect(destination, findsOneWidget);
    }

    await openDestination(
      const Key('verified-learning-entry'),
      find.byKey(const Key('learning-unconfigured')),
    );
    await openDestination(
      const Key('interview-practice-entry'),
      find.byKey(const Key('interview-unconfigured')),
    );
    await openDestination(
      const Key('verified-github-entry'),
      find.byKey(const Key('github-unconfigured')),
    );
    await openDestination(
      const Key('account-saved-profile-entry'),
      find.byKey(const Key('account-unconfigured')),
    );
    await openDestination(
      const Key('mobile-preferences-entry'),
      find.byKey(const Key('preferences-unconfigured')),
    );

    expect(find.text('Waleed Tech Companion'), findsOneWidget);
  });
}

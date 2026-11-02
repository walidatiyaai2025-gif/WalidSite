import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:waleed_tech_companion/main.dart';

void main() {
  testWidgets(
      'T021 home reaches every canonical MOBILE-08 through MOBILE-13 destination',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.pumpAndSettle();

    Future<void> openAndReturn(Key entry, Finder destination) async {
      final tile = find.byKey(entry);
      await tester.scrollUntilVisible(tile, 180);
      await tester.ensureVisible(tile);
      await tester.pumpAndSettle();
      await tester.tap(tile);
      await tester.pumpAndSettle();
      expect(destination, findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();
    }

    await openAndReturn(
      const Key('verified-learning-entry'),
      find.byKey(const Key('learning-unconfigured')),
    );
    await openAndReturn(
      const Key('interview-practice-entry'),
      find.byKey(const Key('interview-unconfigured')),
    );
    await openAndReturn(
      const Key('verified-github-entry'),
      find.byKey(const Key('github-unconfigured')),
    );
    await openAndReturn(
      const Key('account-saved-profile-entry'),
      find.byKey(const Key('account-unconfigured')),
    );
    await openAndReturn(
      const Key('mobile-preferences-entry'),
      find.byKey(const Key('preferences-unconfigured')),
    );

    expect(find.text('Waleed Tech Companion'), findsOneWidget);
  });
}

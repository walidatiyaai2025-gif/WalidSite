import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/preferences/mobile_preferences.dart';

String capabilities(bool arabic) => jsonEncode({'data': {
  'locale': arabic ? 'ar' : 'en',
  'default_locale': 'en',
  'supported_locales': ['en', 'ar'],
  'available_themes': ['light'],
  'theme': 'light',
  'locale_saved_on_server': false,
  'requires_authentication': false,
}});

void main() {
  test('server preferences parsing enforces real supported features only', () {
    expect(MobilePreferenceCapabilities.parse(capabilities(false), false).locale,
        'en');
    expect(MobilePreferenceCapabilities.parse(capabilities(true), true).locale,
        'ar');
    for (final bad in [
      capabilities(false).replaceFirst('"locale":"en"', '"locale":"fr"'),
      capabilities(false).replaceFirst('"light"', '"dark"'),
      capabilities(false).replaceFirst('"locale_saved_on_server":false',
          '"locale_saved_on_server":true'),
      capabilities(false).replaceFirst('"requires_authentication":false',
          '"requires_authentication":true'),
      capabilities(false).replaceFirst('["en","ar"]', '["en"]'),
      capabilities(false).replaceFirst('"theme":"light"', '"theme":"dark"'),
      capabilities(false).replaceFirst('}}', ',"api_key":"PRIVATE"}}'),
      'garbage',
      'x' * 65537,
    ]) {
      expect(() => MobilePreferenceCapabilities.parse(bad, false),
          throwsFormatException);
    }
  });

  for (final arabic in [false, true]) {
    testWidgets('real settings, no fake server controls, signed cache confirm ' +
        (arabic ? 'Arabic' : 'English'), (tester) async {
      var clears = 0;
      var fetches = 0;
      final selected = <bool>[];
      await tester.pumpWidget(MaterialApp(home: MobilePreferencesScreen(
        arabic: arabic,
        onLanguageChanged: selected.add,
        fetch: (ar) async {
          fetches++;
          return MobilePreferenceCapabilities.parse(capabilities(ar), ar);
        },
        clearSignedCache: () async { clears++; },
      )));
      await tester.pumpAndSettle();
      expect(fetches, 1);
      expect(find.byKey(const Key('preferences-verified')), findsOneWidget);
      expect(find.byKey(const Key('preferences-language')), findsOneWidget);
      expect(find.byKey(const Key('preferences-offline')), findsOneWidget);
      expect(find.byKey(const Key('preferences-alerts')), findsOneWidget);
      expect(find.byKey(const Key('preferences-ai')), findsOneWidget);
      expect(find.byKey(const Key('preferences-data')), findsOneWidget);
      expect(find.byKey(const Key('preferences-clear-cache')), findsOneWidget);

      await tester.ensureVisible(find.byKey(const Key('preferences-clear-cache')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('preferences-clear-cache')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('preferences-clear-confirm')), findsOneWidget);
      await tester.tap(find.byKey(const Key('preferences-clear-cancel')));
      await tester.pumpAndSettle();
      expect(clears, 0, reason: 'A cancelled deletion must never touch disk');

      await tester.tap(find.byKey(const Key('preferences-clear-cache')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('preferences-clear-confirm')));
      await tester.pumpAndSettle();
      expect(clears, 1);
      expect(find.byKey(const Key('preferences-clear-status')), findsOneWidget);
      expect(find.textContaining('server data'), arabic ? findsNothing : findsWidgets);
      expect(selected, isEmpty);
    });
  }

  testWidgets('language switch changes in-memory root app locale and returns',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.scrollUntilVisible(
        find.byKey(const Key('mobile-preferences-entry')), 130);
    await tester.ensureVisible(find.byKey(const Key('mobile-preferences-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('mobile-preferences-entry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('preferences-unconfigured')), findsOneWidget);
    expect(find.text('Preferences'), findsOneWidget);
    final arabic = find.descendant(
      of: find.byKey(const Key('preferences-language-picker')),
      matching: find.text('العربية'),
    );
    await tester.ensureVisible(arabic);
    await tester.tap(arabic);
    await tester.pumpAndSettle();
    expect(find.text('التفضيلات'), findsOneWidget);
    expect(find.textContaining('الخادم'), findsWidgets);
    // The back tooltip is localized in RTL; pop the current route directly
    // to verify that the parent app retains the newly selected language.
    tester.state<NavigatorState>(find.byType(Navigator)).pop();
    await tester.pumpAndSettle();
    expect(find.text('رفيق وليد التقني'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('رفيق وليد التقني'))),
        TextDirection.rtl);
  });

  testWidgets('preferences failed fetch retries without hiding local controls',
      (tester) async {
    var fetches = 0;
    await tester.pumpWidget(MaterialApp(home: MobilePreferencesScreen(
      arabic: false,
      onLanguageChanged: (_) {},
      fetch: (ar) async {
        fetches++;
        if (fetches == 1) throw const FormatException('PRIVATE_SERVER_TRACE');
        return MobilePreferenceCapabilities.parse(capabilities(ar), ar);
      },
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('preferences-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_SERVER_TRACE'), findsNothing);
    expect(find.byKey(const Key('preferences-language-picker')), findsOneWidget);
    await tester.ensureVisible(find.byKey(const Key('preferences-retry')));
    await tester.tap(find.byKey(const Key('preferences-retry')));
    await tester.pumpAndSettle();
    expect(fetches, 2);
    expect(find.byKey(const Key('preferences-verified')), findsOneWidget);
  });

  testWidgets('cache deletion failure is truthful and never exposes exception',
      (tester) async {
    await tester.pumpWidget(MaterialApp(home: MobilePreferencesScreen(
      arabic: false,
      onLanguageChanged: (_) {},
      clearSignedCache: () async {
        throw const FormatException('PRIVATE_LOCAL_PATH');
      },
    )));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('preferences-clear-cache')));
    await tester.tap(find.byKey(const Key('preferences-clear-cache')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('preferences-clear-confirm')));
    await tester.pumpAndSettle();
    expect(find.text('Could not remove the download. Try again.'), findsOneWidget);
    expect(find.textContaining('PRIVATE_LOCAL_PATH'), findsNothing);
  });
}

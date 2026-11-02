import 'dart:async';
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
      expect(find.byKey(const Key('preferences-theme')), findsOneWidget);
      expect(find.byKey(const Key('preferences-change-theme')), findsOneWidget);
      await tester.ensureVisible(find.byKey(const Key('preferences-change-theme')));
      await tester.tap(find.byKey(const Key('preferences-change-theme')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('preferences-theme-unavailable')), findsOneWidget);
      expect(find.text(arabic ? 'المظهر الفاتح فقط' : 'Light theme only'), findsOneWidget);
      expect(find.textContaining(arabic ? 'لن يتم تغيير' : 'Nothing will be changed'), findsOneWidget);
      await tester.tap(find.byKey(const Key('preferences-theme-dismiss')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('preferences-theme-unavailable')), findsNothing);
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
      expect(find.textContaining(
          arabic ? 'تصدير الحساب وحذفه متاحان' : 'Account export and deletion are available'),
          findsWidgets);
      expect(selected, isEmpty);
    });
  }

  testWidgets('language switch changes in-memory root app locale and returns',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp(initiallyStarted: true));
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

  testWidgets('parent locale changes update mounted preferences direction and reverify',
      (tester) async {
    final requested = <bool>[];
    final userSelections = <bool>[];
    Future<MobilePreferenceCapabilities> fetch(bool arabic) async {
      requested.add(arabic);
      return MobilePreferenceCapabilities.parse(capabilities(arabic), arabic);
    }
    Widget screen(bool arabic) => MaterialApp(home: MobilePreferencesScreen(
      arabic: arabic,
      onLanguageChanged: userSelections.add,
      fetch: fetch,
    ));

    await tester.pumpWidget(screen(false));
    await tester.pumpAndSettle();
    expect(requested, [false]);
    expect(find.text('Preferences'), findsOneWidget);
    expect(find.byKey(const Key('preferences-verified')), findsOneWidget);

    // The same mounted State receives new widget props from the app root.
    await tester.pumpWidget(screen(true));
    await tester.pumpAndSettle();
    expect(requested, [false, true]);
    expect(userSelections, isEmpty,
        reason: 'A parent-driven change must not fire a user action');
    expect(find.text('التفضيلات'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('التفضيلات'))),
        TextDirection.rtl);
    expect(find.byKey(const Key('preferences-verified')), findsOneWidget);

    await tester.pumpWidget(screen(false));
    await tester.pumpAndSettle();
    expect(requested, [false, true, false]);
    expect(find.text('Preferences'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('Preferences'))),
        TextDirection.ltr);
    expect(userSelections, isEmpty);
  });

  testWidgets('replaced capability reader does not display stale verified source',
      (tester) async {
    final oldSource = Completer<MobilePreferenceCapabilities>();
    var initialCalls = 0;
    var replacementCalls = 0;
    Future<MobilePreferenceCapabilities> first(bool arabic) {
      initialCalls++;
      return oldSource.future;
    }
    Future<MobilePreferenceCapabilities> replacement(bool arabic) async {
      replacementCalls++;
      return MobilePreferenceCapabilities.parse(capabilities(arabic), arabic);
    }
    Widget screen(PreferenceFetch fetcher) => MaterialApp(
      home: MobilePreferencesScreen(
        arabic: false, onLanguageChanged: (_) {}, fetch: fetcher,
      ),
    );

    await tester.pumpWidget(screen(first));
    await tester.pump();
    expect(find.byKey(const Key('preferences-loading')), findsOneWidget);
    expect(initialCalls, 1);

    await tester.pumpWidget(screen(replacement));
    await tester.pumpAndSettle();
    expect(replacementCalls, 1);
    expect(find.byKey(const Key('preferences-verified')), findsOneWidget);
    oldSource.completeError(const FormatException('PRIVATE_OLD_PROVIDER_ERROR'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('preferences-verified')), findsOneWidget);
    expect(find.byKey(const Key('preferences-error')), findsNothing);
    expect(find.textContaining('PRIVATE_OLD_PROVIDER_ERROR'), findsNothing);
  });

  testWidgets('language picker parent echo invokes callback and fetch exactly once',
      (tester) async {
    var parentArabic = false;
    final userSelections = <bool>[];
    final requests = <bool>[];
    Future<MobilePreferenceCapabilities> fetch(bool arabic) async {
      requests.add(arabic);
      return MobilePreferenceCapabilities.parse(capabilities(arabic), arabic);
    }
    await tester.pumpWidget(MaterialApp(home: StatefulBuilder(
      builder: (context, setParent) => MobilePreferencesScreen(
        arabic: parentArabic,
        fetch: fetch,
        onLanguageChanged: (arabic) {
          userSelections.add(arabic);
          setParent(() => parentArabic = arabic);
        },
      ),
    )));
    await tester.pumpAndSettle();
    expect(requests, [false]);
    final arabicOption = find.descendant(
      of: find.byKey(const Key('preferences-language-picker')),
      matching: find.text('العربية'),
    );
    await tester.ensureVisible(arabicOption);
    await tester.tap(arabicOption);
    await tester.pumpAndSettle();
    expect(userSelections, [true]);
    expect(requests, [false, true],
        reason: 'Root echo must not send a duplicate preferences request');
    expect(find.text('التفضيلات'), findsOneWidget);
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

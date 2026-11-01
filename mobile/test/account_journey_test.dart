import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/account/account_journey.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';
import 'package:waleed_tech_companion/main.dart';

const articleId = '00000000-0000-4000-8000-000000000001';
const runbookId = '00000000-0000-4000-8000-000000000002';
const bearer = '1|test-only-token';
const email = 'reader@example.org';

Map<String, dynamic> article() => {
  'resource_type': 'article',
  'resource_id': articleId,
  'title': 'Source-verified Laravel guide',
  'detail_url': '/api/v1/public/articles/laravel-guide',
  'saved_at': '2026-11-01T10:00:00+00:00',
};

Map<String, dynamic> runbook() => {
  'resource_type': 'runbook',
  'resource_id': runbookId,
  'title': 'Reviewed recovery runbook',
  'detail_url': '/api/v1/mobile/solutions/$runbookId',
  'saved_at': '2026-11-01T09:00:00+00:00',
};

String page(int n, List<Map<String, dynamic>> items, {bool more = false}) =>
  jsonEncode({'data': items, 'meta': {
    'current_page': n, 'has_more_pages': more,
  }});

Future<void> login(WidgetTester tester) async {
  await tester.enterText(find.byKey(const Key('account-email')), email);
  await tester.enterText(find.byKey(const Key('account-password')), 'not-a-real-password');
  await tester.tap(find.byKey(const Key('account-login')));
  await tester.pumpAndSettle();
}

void main() {
  test('saved resource parser rejects untrusted fields, URLs and duplicates', () {
    final valid = MobileSavedPage.parse(page(1, [article(), runbook()]), 1);
    expect(valid.items.length, 2);
    expect(valid.items.first.articleSlug, 'laravel-guide');
    expect(valid.items.last.articleSlug, isNull);
    for (final bad in [
      article()..['detail_url'] = 'https://evil.example/steal',
      article()..['detail_url'] = '/api/v1/public/articles/laravel-guide?token=x',
      article()..['resource_id'] = 'not-a-uuid',
      article()..['secret'] = 'PRIVATE',
      article()..['title'] = '',
      article()..['saved_at'] = 'yesterday',
      runbook()..['detail_url'] =
          '/api/v1/mobile/solutions/00000000-0000-4000-8000-000000000099',
      runbook()..['resource_type'] = 'answer',
    ]) {
      expect(() => MobileSavedPage.parse(page(1, [bad]), 1), throwsFormatException);
    }
    expect(() => MobileSavedPage.parse(page(1, [article(), article()]), 1),
        throwsFormatException);
    expect(() => MobileSavedPage.parse(page(2, [article()]), 1),
        throwsFormatException);
    expect(() => MobileSavedPage.parse(page(1, [], more: true), 1),
        throwsFormatException);
    expect(() => MobileSavedPage.parse('x' * (512 * 1024 + 1), 1),
        throwsFormatException);
    expect(
      () => MobileSavedPage.parse(page(1, List.generate(21, (_) => article())), 1),
      throwsFormatException,
    );
    expect(() => MobileAccount.parse(jsonEncode({'data': {
      'id': articleId, 'email': email, 'password_hash': 'SECRET',
    }})), throwsFormatException);
  });

  for (final arabic in [false, true]) {
    testWidgets('authenticated profile, saved articles, removal and logout: ' +
        (arabic ? 'Arabic' : 'English'), (tester) async {
      var loginCalls = 0;
      var pageCalls = 0;
      var removals = 0;
      var revocations = 0;
      var removed = false;
      var articleCalls = 0;
      await tester.pumpWidget(MaterialApp(
        home: MobileAccountJourney(
          arabic: arabic,
          loginAction: (address, password) async {
            expect(address, email);
            expect(password, 'not-a-real-password');
            loginCalls++;
            return const MobileAiSession(token: bearer, expiresAt: null);
          },
          profileAction: (token) async {
            expect(token, bearer);
            return const MobileAccount(articleId, email);
          },
          savedAction: (token, locale, number) async {
            expect(token, bearer);
            expect(locale, arabic);
            expect(number, 1);
            pageCalls++;
            return MobileSavedPage.parse(page(1, removed ? [] : [article()]), 1);
          },
          removeAction: (token, item) async {
            expect(token, bearer);
            expect(item.id, articleId);
            removed = true;
            removals++;
          },
          logoutAction: (token) async {
            expect(token, bearer);
            revocations++;
          },
          articleAction: (item, locale) async {
            expect(item.id, articleId);
            expect(locale, arabic);
            articleCalls++;
            return const VerifiedArticle(
              slug: 'laravel-guide',
              title: 'Source-verified Laravel guide',
              body: 'Published approved citation text',
              citations: ['https://docs.example.org/guide'],
            );
          },
        ),
      ));

      await login(tester);
      expect(find.text('Source-verified Laravel guide'), findsOneWidget);
      expect(pageCalls, 1);
      expect(loginCalls, 1);
      await tester.tap(find.byKey(const Key('account-open-$articleId')));
      await tester.pumpAndSettle();
      expect(find.text('Published approved citation text'), findsOneWidget);
      expect(articleCalls, 1);
      await tester.pageBack();
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('account-profile-tab')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('account-profile-email')), findsOneWidget);
      expect(find.text(email), findsOneWidget);
      expect(find.textContaining('not supported'), arabic ? findsNothing : findsOneWidget);
      await tester.tap(find.byKey(const Key('account-saved-tab')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('account-remove-$articleId')));
      await tester.pumpAndSettle();
      expect(removals, 1);
      expect(pageCalls, 2);
      expect(find.byKey(const Key('account-saved-empty')), findsOneWidget);
      expect(find.text('Source-verified Laravel guide'), findsNothing);

      await tester.tap(find.byKey(const Key('account-profile-tab')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('account-signout')));
      await tester.pumpAndSettle();
      expect(revocations, 1);
      expect(find.byKey(const Key('account-login')), findsOneWidget);
      expect(find.text(bearer), findsNothing);
      expect(find.byKey(const Key('account-profile-email')), findsNothing);
    });
  }

  testWidgets('saved pagination is single-flight, rejects duplicate IDs and recovers',
      (tester) async {
    var attempts = 0;
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        profileAction: (_) async => const MobileAccount(articleId, email),
        savedAction: (_, __, number) async {
          if (number == 1) {
            return MobileSavedPage.parse(page(1, [article()], more: true), 1);
          }
          attempts++;
          if (attempts == 1) {
            return MobileSavedPage.parse(page(2, [article()]), 2);
          }
          return MobileSavedPage.parse(page(2, [runbook()]), 2);
        },
      ),
    ));
    await login(tester);
    await tester.tap(find.byKey(const Key('account-load-more')));
    await tester.pumpAndSettle();
    expect(find.text('Could not load more saved items.'), findsOneWidget);
    expect(find.text('Source-verified Laravel guide'), findsOneWidget);
    expect(find.text('Reviewed recovery runbook'), findsNothing);
    await tester.tap(find.byKey(const Key('account-load-more')));
    await tester.pumpAndSettle();
    expect(attempts, 2);
    expect(find.text('Reviewed recovery runbook'), findsOneWidget);
    expect(find.byKey(const Key('account-load-more')), findsNothing);
  });

  testWidgets('expired login is rejected before any private account fetch',
      (tester) async {
    var protectedCalls = 0;
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: true,
        loginAction: (_, __) async => MobileAiSession(
          token: bearer, expiresAt: DateTime.utc(2020),
        ),
        profileAction: (_) async {
          protectedCalls++;
          return const MobileAccount(articleId, email);
        },
      ),
    ));
    await login(tester);
    expect(protectedCalls, 0);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
    expect(find.byKey(const Key('account-profile-email')), findsNothing);
    expect(find.text(bearer), findsNothing);
  });

  testWidgets('failed remote revoke clears local session but does not claim success',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        profileAction: (_) async => const MobileAccount(articleId, email),
        savedAction: (_, __, n) async => MobileSavedPage.parse(page(n, []), n),
        logoutAction: (_) async => throw const FormatException('PRIVATE_BACKEND_ERROR'),
      ),
    ));
    await login(tester);
    await tester.tap(find.byKey(const Key('account-profile-tab')));
    await tester.tap(find.byKey(const Key('account-signout')));
    await tester.pumpAndSettle();
    expect(find.text('Local session cleared; server sign-out unconfirmed.'),
        findsOneWidget);
    expect(find.textContaining('PRIVATE_BACKEND_ERROR'), findsNothing);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
  });

  testWidgets('main app opens real account screen with truthful unavailable state',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.scrollUntilVisible(
      find.byKey(const Key('account-saved-profile-entry')), 145,
    );
    await tester.ensureVisible(
        find.byKey(const Key('account-saved-profile-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('account-saved-profile-entry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('account-unconfigured')), findsOneWidget);
    expect(find.text('Account services are not configured.'), findsOneWidget);
  });
}

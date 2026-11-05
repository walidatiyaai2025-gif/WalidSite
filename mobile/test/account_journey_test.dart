import 'dart:async';
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
  for (final arabic in [false, true]) {
    testWidgets('verified article session opens Saved without second login — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      var profileReads = 0;
      var savedReads = 0;
      var loginCalls = 0;
      await tester.pumpWidget(MaterialApp(
        home: MobileAccountJourney(
          arabic: arabic,
          initialSection: MobileAccountSection.saved,
          initialSession: const MobileAiSession(token: bearer, expiresAt: null),
          loginAction: (_, __) async {
            loginCalls++;
            return const MobileAiSession(token: 'unexpected', expiresAt: null);
          },
          profileAction: (token) async {
            expect(token, bearer);
            profileReads++;
            return const MobileAccount(articleId, email);
          },
          savedAction: (token, locale, number) async {
            expect(token, bearer);
            expect(locale, arabic);
            expect(number, 1);
            savedReads++;
            return MobileSavedPage.parse(page(1, [article()]), 1);
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(loginCalls, 0, reason: 'Handoff must not create a second bearer');
      expect(profileReads, 1);
      expect(savedReads, 1);
      expect(find.text('Source-verified Laravel guide'), findsOneWidget);
      expect(find.byKey(const Key('account-login')), findsNothing);
      expect(find.text(bearer), findsNothing);
    });

    testWidgets('revoked handoff never shows Saved or account identity — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      var savedReads = 0;
      await tester.pumpWidget(MaterialApp(
        home: MobileAccountJourney(
          arabic: arabic,
          initialSession: const MobileAiSession(token: bearer, expiresAt: null),
          loginAction: (_, __) async =>
              const MobileAiSession(token: 'fresh', expiresAt: null),
          profileAction: (_) async => throw const MobileAiHttpException(401),
          savedAction: (_, __, ___) async {
            savedReads++;
            return MobileSavedPage.parse(page(1, [article()]), 1);
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(savedReads, 0);
      expect(find.byKey(const Key('account-login')), findsOneWidget);
      expect(find.text('Source-verified Laravel guide'), findsNothing);
      expect(find.text(email), findsNothing);
      expect(find.text(bearer), findsNothing);
    });

    testWidgets('temporary Saved outage keeps bearer for read-only retry — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      var reads = 0;
      var logins = 0;
      await tester.pumpWidget(MaterialApp(
        home: MobileAccountJourney(
          arabic: arabic,
          initialSession: const MobileAiSession(token: bearer, expiresAt: null),
          loginAction: (_, __) async {
            logins++;
            return const MobileAiSession(token: 'unexpected', expiresAt: null);
          },
          profileAction: (token) async {
            expect(token, bearer);
            reads++;
            if (reads == 1) throw const MobileAiHttpException(503);
            return const MobileAccount(articleId, email);
          },
          savedAction: (token, _, __) async {
            expect(token, bearer);
            return MobileSavedPage.parse(page(1, [article()]), 1);
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('account-load-unavailable')), findsOneWidget);
      expect(find.text(email), findsNothing);
      expect(find.text(bearer), findsNothing);
      expect(find.byKey(const Key('account-retry-data')), findsOneWidget);
      await tester.tap(find.byKey(const Key('account-retry-data')));
      await tester.pumpAndSettle();
      expect(logins, 0);
      expect(reads, 2);
      expect(find.text('Source-verified Laravel guide'), findsOneWidget);
    });
  }

  testWidgets('expired account handoff is never fetched or displayed', (tester) async {
    var privateReads = 0;
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        initialSession: MobileAiSession(
          token: bearer,
          expiresAt: DateTime.utc(2020, 1, 1),
        ),
        loginAction: (_, __) async =>
            const MobileAiSession(token: 'fresh', expiresAt: null),
        profileAction: (_) async {
          privateReads++;
          return const MobileAccount(articleId, email);
        },
        savedAction: (_, __, ___) async {
          privateReads++;
          return MobileSavedPage.parse(page(1, [article()]), 1);
        },
      ),
    ));
    await tester.pumpAndSettle();
    expect(privateReads, 0);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
    expect(find.text(bearer), findsNothing);
    expect(find.text(email), findsNothing);
  });

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
      var profileUpdates = 0;
      var removed = false;
      var articleCalls = 0;
      final updatedEmail =
          arabic ? 'arabic.updated@example.org' : 'updated@example.org';
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
          profileUpdateAction: (token, address, currentPassword) async {
            expect(token, bearer);
            expect(address, updatedEmail);
            expect(currentPassword, 'current-account-password');
            profileUpdates++;
            return MobileAccount(articleId, address);
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
      expect(find.byKey(const Key('account-edit-profile')), findsOneWidget);
      await tester.tap(find.byKey(const Key('account-edit-profile')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('account-edit-email')),
        updatedEmail,
      );
      await tester.enterText(
        find.byKey(const Key('account-edit-password')),
        'current-account-password',
      );
      await tester.tap(find.byKey(const Key('account-edit-save')));
      await tester.pumpAndSettle();
      expect(profileUpdates, 1);
      expect(find.text(updatedEmail), findsOneWidget);
      expect(find.text('current-account-password'), findsNothing);
      expect(
        find.text(arabic
            ? 'تم تحديث بريد الحساب وإلغاء جلسات الأجهزة الأخرى.'
            : 'Account email updated. Other bearer sessions were revoked.'),
        findsOneWidget,
      );
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

  for (final arabic in [false, true]) {
    for (final failedStep in ['profile', 'saved']) {
      testWidgets('account $failedStep failure keeps single bearer for '
          'authenticated retry without showing private data: '
          '${arabic ? 'AR' : 'EN'}', (tester) async {
        var logins = 0;
        var profileReads = 0;
        var savedReads = 0;
        var revokes = 0;
        await tester.pumpWidget(MaterialApp(
          home: MobileAccountJourney(
            arabic: arabic,
            loginAction: (_, __) async {
              logins++;
              return const MobileAiSession(token: bearer, expiresAt: null);
            },
            profileAction: (token) async {
              expect(token, bearer);
              profileReads++;
              if (failedStep == 'profile' && profileReads == 1) {
                throw const FormatException('PRIVATE_PROFILE_BACKEND_TRACE');
              }
              return const MobileAccount(articleId, email);
            },
            savedAction: (token, locale, n) async {
              expect(token, bearer);
              expect(locale, arabic);
              savedReads++;
              if (failedStep == 'saved' && savedReads == 1) {
                throw const FormatException('PRIVATE_SAVED_BACKEND_TRACE');
              }
              return MobileSavedPage.parse(page(n, [article()]), n);
            },
            logoutAction: (token) async {
              expect(token, bearer);
              revokes++;
            },
          ),
        ));
        await login(tester);
        expect(logins, 1);
        expect(find.byKey(const Key('account-load-unavailable')), findsOneWidget);
        expect(find.byKey(const Key('account-retry-data')), findsOneWidget);
        expect(find.byKey(const Key('account-signout')), findsOneWidget);
        expect(find.byKey(const Key('account-login')), findsNothing);
        expect(find.byKey(const Key('account-saved-empty')), findsNothing);
        expect(find.byKey(const Key('account-profile-email')), findsNothing);
        expect(find.text('Source-verified Laravel guide'), findsNothing);
        expect(find.text(email), findsNothing);
        expect(find.text(bearer), findsNothing);
        expect(find.textContaining('PRIVATE_PROFILE_BACKEND_TRACE'), findsNothing);
        expect(find.textContaining('PRIVATE_SAVED_BACKEND_TRACE'), findsNothing);

        await tester.ensureVisible(find.byKey(const Key('account-retry-data')));
        await tester.tap(find.byKey(const Key('account-retry-data')));
        await tester.pumpAndSettle();
        expect(logins, 1);
        expect(profileReads, 2);
        expect(savedReads, failedStep == 'profile' ? 1 : 2);
        expect(find.byKey(const Key('account-load-unavailable')), findsNothing);
        expect(find.text('Source-verified Laravel guide'), findsOneWidget);
        expect(find.byKey(const Key('account-login')), findsNothing);
        await tester.tap(find.byKey(const Key('account-profile-tab')));
        await tester.pumpAndSettle();
        expect(find.text(email), findsOneWidget);
        await tester.ensureVisible(find.byKey(const Key('account-signout')));
        await tester.tap(find.byKey(const Key('account-signout')));
        await tester.pumpAndSettle();
        expect(revokes, 1);
        expect(find.byKey(const Key('account-login')), findsOneWidget);
        expect(find.text(email), findsNothing);
        expect(find.text(bearer), findsNothing);
      });
    }
  }

  // Once the server rejects a newly minted bearer, a retry using that same
  // revoked credential cannot recover. In both locales and at either read
  // boundary, return to the login form without displaying private account data.
  for (final arabic in [false, true]) {
    for (final failedRead in ['profile', 'saved']) {
      for (final status in [401, 403]) {
        testWidgets('initial $failedRead HTTP $status clears bearer and '
            'permits fresh login: ${arabic ? 'AR' : 'EN'}', (tester) async {
          var logins = 0;
          var revokes = 0;
          const freshBearer = '2|fresh-session-after-authorization-loss';
          await tester.pumpWidget(MaterialApp(
            home: MobileAccountJourney(
              arabic: arabic,
              loginAction: (_, __) async {
                logins++;
                return MobileAiSession(
                  token: logins == 1 ? bearer : freshBearer,
                  expiresAt: null,
                );
              },
              profileAction: (token) async {
                if (token == bearer && failedRead == 'profile') {
                  throw MobileAiHttpException(status);
                }
                return const MobileAccount(articleId, email);
              },
              savedAction: (token, locale, n) async {
                expect(locale, arabic);
                if (token == bearer && failedRead == 'saved') {
                  throw MobileAiHttpException(status);
                }
                return MobileSavedPage.parse(page(n, []), n);
              },
              logoutAction: (_) async { revokes++; },
            ),
          ));
          await login(tester);
          expect(logins, 1);
          expect(revokes, 0);
          expect(find.byKey(const Key('account-login')), findsOneWidget);
          expect(find.byKey(const Key('account-retry-data')), findsNothing);
          expect(find.byKey(const Key('account-signout')), findsNothing);
          expect(find.byKey(const Key('account-load-unavailable')), findsNothing);
          expect(find.byKey(const Key('account-profile-email')), findsNothing);
          expect(find.text(email), findsNothing);
          expect(find.text(bearer), findsNothing);
          expect(find.text(freshBearer), findsNothing);
          expect(find.text(arabic
              ? 'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.'
              : 'Session no longer authorized. Sign in again.'), findsOneWidget);
          expect(tester.widget<TextField>(find.byKey(const Key('account-email')))
              .controller!.text, isEmpty);
          expect(tester.widget<TextField>(find.byKey(const Key('account-password')))
              .controller!.text, isEmpty);

          await login(tester);
          expect(logins, 2, reason: 'A new login must be available after rejection');
          expect(revokes, 0, reason: 'Never claim to revoke a rejected bearer');
          expect(find.byKey(const Key('account-saved-empty')), findsOneWidget);
          expect(find.byKey(const Key('account-login')), findsNothing);
        });
      }
    }
  }

  // A transient outage MUST still keep the valid token for retry (#598).
  // However a later 401/403 on that retry means the token is no longer
  // authorized: it must be scrubbed rather than trapping users behind retry.
  for (final arabic in [false, true]) {
    for (final failedRead in ['profile', 'saved']) {
      for (final status in [401, 403]) {
        testWidgets('transient $failedRead outage followed by HTTP $status '
            'on retry clears recovery bearer: ${arabic ? 'AR' : 'EN'}',
            (tester) async {
          var logins = 0;
          var reads = 0;
          var revokes = 0;
          const freshBearer = '2|fresh-session-after-retry';
          await tester.pumpWidget(MaterialApp(
            home: MobileAccountJourney(
              arabic: arabic,
              loginAction: (_, __) async {
                logins++;
                return MobileAiSession(
                  token: logins == 1 ? bearer : freshBearer,
                  expiresAt: null,
                );
              },
              profileAction: (token) async {
                if (token == bearer && failedRead == 'profile') {
                  reads++;
                  if (reads == 1) {
                    throw const MobileAiHttpException(503);
                  }
                  throw MobileAiHttpException(status);
                }
                return const MobileAccount(articleId, email);
              },
              savedAction: (token, locale, n) async {
                expect(locale, arabic);
                if (token == bearer && failedRead == 'saved') {
                  reads++;
                  if (reads == 1) {
                    throw const FormatException('PRIVATE_TRANSIENT_TRACE');
                  }
                  throw MobileAiHttpException(status);
                }
                return MobileSavedPage.parse(page(n, []), n);
              },
              logoutAction: (_) async { revokes++; },
            ),
          ));
          await login(tester);
          expect(logins, 1);
          expect(reads, 1);
          expect(find.byKey(const Key('account-load-unavailable')), findsOneWidget);
          expect(find.byKey(const Key('account-retry-data')), findsOneWidget);
          expect(find.byKey(const Key('account-login')), findsNothing);
          expect(find.textContaining('PRIVATE_TRANSIENT_TRACE'), findsNothing);

          await tester.ensureVisible(find.byKey(const Key('account-retry-data')));
          await tester.tap(find.byKey(const Key('account-retry-data')));
          await tester.pumpAndSettle();
          expect(reads, 2);
          expect(logins, 1);
          expect(revokes, 0);
          expect(find.byKey(const Key('account-login')), findsOneWidget);
          expect(find.byKey(const Key('account-retry-data')), findsNothing);
          expect(find.byKey(const Key('account-signout')), findsNothing);
          expect(find.byKey(const Key('account-profile-email')), findsNothing);
          expect(find.text(email), findsNothing);
          expect(find.text(bearer), findsNothing);
          expect(find.text(arabic
              ? 'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.'
              : 'Session no longer authorized. Sign in again.'), findsOneWidget);

          await login(tester);
          expect(logins, 2);
          expect(find.byKey(const Key('account-saved-empty')), findsOneWidget);
          expect(revokes, 0);
        });
      }
    }
  }

  testWidgets('signout works after first saved read fails, scrubs local data '
      'even when token revocation is unconfirmed', (tester) async {
    var revokes = 0;
    var logins = 0;
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: true,
        loginAction: (_, __) async {
          logins++;
          return const MobileAiSession(token: bearer, expiresAt: null);
        },
        profileAction: (_) async => const MobileAccount(articleId, email),
        savedAction: (_, __, ___) async =>
            throw const FormatException('PRIVATE_DATA_PROVENANCE'),
        logoutAction: (token) async {
          expect(token, bearer);
          revokes++;
          throw const FormatException('PRIVATE_REVOKE_FAILURE');
        },
      ),
    ));
    await login(tester);
    expect(logins, 1);
    expect(find.byKey(const Key('account-load-unavailable')), findsOneWidget);
    expect(find.text(email), findsNothing);
    await tester.ensureVisible(find.byKey(const Key('account-signout')));
    await tester.tap(find.byKey(const Key('account-signout')));
    await tester.pumpAndSettle();
    expect(revokes, 1);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
    expect(find.byKey(const Key('account-load-unavailable')), findsNothing);
    expect(find.text(email), findsNothing);
    expect(find.text(bearer), findsNothing);
    expect(find.textContaining('PRIVATE_REVOKE_FAILURE'), findsNothing);
    expect(find.text('تم مسح الجلسة محليًا؛ لم يتم تأكيد إلغائها على الخادم.'),
        findsOneWidget);
  });

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

  test('saved page rejects UTF-8 payloads larger than its byte budget', () {
    const limit = 512 * 1024;
    // UTF-16 length can be below the limit while encoded bytes exceed it.
    final raw = page(1, []) + ('é' * (limit ~/ 2));
    expect(raw.length, lessThan(limit));
    expect(utf8.encode(raw).length, greaterThan(limit));
    expect(() => MobileSavedPage.parse(raw, 1), throwsFormatException);
    expect(MobileSavedPage.parse(page(1, [article()]), 1).items, hasLength(1));
  });

  testWidgets('saved pagination rejects wrong page and case-folded duplicate',
      (tester) async {
    var attempts = 0;
    const mixedId = 'abcdefab-1234-4000-8000-000000000001';
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        profileAction: (_) async => const MobileAccount(articleId, email),
        savedAction: (_, __, number) async {
          if (number == 1) {
            final first = article()..['resource_id'] = mixedId;
            return MobileSavedPage.parse(page(1, [first], more: true), 1);
          }
          attempts++;
          if (attempts == 1) {
            // An injected loader returning the wrong page must fail closed.
            return MobileSavedPage.parse(page(3, [runbook()]), 3);
          }
          if (attempts == 2) {
            // Same published UUID under a different text representation.
            final duplicate = article()..['resource_id'] = mixedId.toUpperCase();
            return MobileSavedPage.parse(page(2, [duplicate]), 2);
          }
          return MobileSavedPage.parse(page(2, [runbook()]), 2);
        },
      ),
    ));
    await login(tester);
    for (var failure = 1; failure <= 2; failure++) {
      await tester.tap(find.byKey(const Key('account-load-more')));
      await tester.pumpAndSettle();
      expect(find.text('Could not load more saved items.'), findsOneWidget);
      expect(find.text('Source-verified Laravel guide'), findsOneWidget);
      expect(find.text('Reviewed recovery runbook'), findsNothing);
    }
    await tester.tap(find.byKey(const Key('account-load-more')));
    await tester.pumpAndSettle();
    expect(attempts, 3);
    expect(find.text('Reviewed recovery runbook'), findsOneWidget);
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
    expect(tester.widget<TextField>(find.byKey(const Key('account-email')))
        .controller!.text, isEmpty);
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
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('account-signout')));
    await tester.pumpAndSettle();
    expect(find.text('Local session cleared; server sign-out unconfirmed.'),
        findsOneWidget);
    expect(find.textContaining('PRIVATE_BACKEND_ERROR'), findsNothing);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
  });

  testWidgets('profile edit handles validation failure then unauthorized session safely',
      (tester) async {
    var attempts = 0;
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        profileAction: (_) async => const MobileAccount(articleId, email),
        profileUpdateAction: (_, __, ___) async {
          attempts++;
          if (attempts == 1) throw const MobileAiHttpException(422);
          throw const MobileAiHttpException(403);
        },
        savedAction: (_, __, n) async =>
            MobileSavedPage.parse(page(n, []), n),
      ),
    ));
    await login(tester);
    await tester.tap(find.byKey(const Key('account-profile-tab')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('account-edit-profile')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('account-edit-email')),
      'candidate@example.org',
    );
    await tester.enterText(
      find.byKey(const Key('account-edit-password')),
      'wrong-current-password',
    );
    await tester.tap(find.byKey(const Key('account-edit-save')));
    await tester.pumpAndSettle();

    expect(attempts, 1);
    expect(find.text('Check the email address and current password.'),
        findsOneWidget);
    expect(find.byKey(const Key('account-edit-email')), findsOneWidget);
    expect(find.text('wrong-current-password'), findsNothing);
    expect(find.text(email), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('account-edit-password')),
      'current-password',
    );
    await tester.tap(find.byKey(const Key('account-edit-save')));
    await tester.pumpAndSettle();

    expect(attempts, 2);
    expect(find.text('Session no longer authorized. Sign in again.'),
        findsOneWidget);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
    expect(find.byKey(const Key('account-profile-email')), findsNothing);
    expect(find.text(bearer), findsNothing);
    expect(find.text('current-password'), findsNothing);
    expect(tester.widget<TextField>(find.byKey(const Key('account-email')))
        .controller!.text, isEmpty);
  });


  for (final arabic in [false, true]) {
    testWidgets('logout scrubs protected data before remote reply and isolates a second account: ' +
        (arabic ? 'Arabic' : 'English'), (tester) async {
      final remoteLogout = Completer<void>();
      const secondEmail = 'other-account@example.org';
      const secondBearer = '2|other-private-session';
      var loginCount = 0;
      var logoutCount = 0;

      await tester.pumpWidget(MaterialApp(
        home: Directionality(
          textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
          child: MobileAccountJourney(
            arabic: arabic,
            loginAction: (address, _) async {
              loginCount++;
              if (address == email) {
                return const MobileAiSession(token: bearer, expiresAt: null);
              }
              if (address == secondEmail) {
                return const MobileAiSession(token: secondBearer, expiresAt: null);
              }
              throw const FormatException('Unexpected account');
            },
            profileAction: (token) async => token == bearer
                ? const MobileAccount(articleId, email)
                : const MobileAccount(runbookId, secondEmail),
            savedAction: (token, _, n) async => MobileSavedPage.parse(
              page(n, token == bearer ? [article()] : [runbook()]), n,
            ),
            logoutAction: (token) async {
              expect(token, bearer);
              logoutCount++;
              await remoteLogout.future;
            },
          ),
        ),
      ));
      await login(tester);
      expect(find.text('Source-verified Laravel guide'), findsOneWidget);
      await tester.tap(find.byKey(const Key('account-profile-tab')));
      await tester.pumpAndSettle();
      expect(find.text(email), findsOneWidget);
      await tester.ensureVisible(find.byKey(const Key('account-signout')));
      await tester.tap(find.byKey(const Key('account-signout')));
      await tester.pump();

      // A network-stalled logout must not leave the prior account's data
      // visible, even for one frame, and cannot admit another login yet.
      expect(logoutCount, 1);
      expect(find.byKey(const Key('account-profile-email')), findsNothing);
      expect(find.text('Source-verified Laravel guide'), findsNothing);
      expect(find.text(email), findsNothing);
      final emailField = tester.widget<TextField>(
        find.byKey(const Key('account-email')),
      );
      expect(emailField.controller!.text, isEmpty);
      expect(tester.widget<TextField>(
        find.byKey(const Key('account-password')),
      ).controller!.text, isEmpty);
      expect(tester.widget<FilledButton>(
        find.byKey(const Key('account-login')),
      ).onPressed, isNull);
      expect(find.text(arabic
          ? 'جارٍ إكمال تسجيل الخروج...'
          : 'Finishing sign-out...'), findsOneWidget);
      expect(find.text(bearer), findsNothing);

      remoteLogout.complete();
      await tester.pumpAndSettle();
      expect(find.text(arabic
          ? 'تم تسجيل الخروج من الجهاز.'
          : 'Signed out of this device.'), findsOneWidget);
      expect(tester.widget<TextField>(
        find.byKey(const Key('account-email')),
      ).controller!.text, isEmpty);

      await tester.enterText(
        find.byKey(const Key('account-email')), secondEmail,
      );
      await tester.enterText(
        find.byKey(const Key('account-password')), 'other-fixture-password',
      );
      await tester.tap(find.byKey(const Key('account-login')));
      await tester.pumpAndSettle();
      expect(loginCount, 2);
      expect(find.text('Reviewed recovery runbook'), findsOneWidget);
      expect(find.text('Source-verified Laravel guide'), findsNothing);
      await tester.tap(find.byKey(const Key('account-profile-tab')));
      await tester.pumpAndSettle();
      expect(find.text(secondEmail), findsOneWidget);
      expect(find.text(email), findsNothing);
      expect(find.text(secondBearer), findsNothing);
      expect(Directionality.of(tester.element(find.byKey(
        const Key('account-profile-email'),
      ))), arabic ? TextDirection.rtl : TextDirection.ltr);
    });
  }

  testWidgets('failed delayed server revocation never restores prior email, profile or saved items',
      (tester) async {
    final pending = Completer<void>();
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        profileAction: (_) async => const MobileAccount(articleId, email),
        savedAction: (_, __, n) async =>
            MobileSavedPage.parse(page(n, [article()]), n),
        logoutAction: (_) => pending.future,
      ),
    ));
    await login(tester);
    await tester.tap(find.byKey(const Key('account-profile-tab')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('account-signout')));
    await tester.tap(find.byKey(const Key('account-signout')));
    await tester.pump();
    expect(find.text(email), findsNothing);
    expect(find.byKey(const Key('account-profile-email')), findsNothing);
    pending.completeError(const FormatException('PRIVATE_SMTP_OR_TOKEN'));
    await tester.pumpAndSettle();
    expect(find.text('Local session cleared; server sign-out unconfirmed.'),
        findsOneWidget);
    expect(find.textContaining('PRIVATE_SMTP_OR_TOKEN'), findsNothing);
    expect(find.text(email), findsNothing);
    expect(tester.widget<TextField>(
      find.byKey(const Key('account-email')),
    ).controller!.text, isEmpty);
  });

  testWidgets('saved pagination authorization loss also scrubs account email',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: MobileAccountJourney(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        profileAction: (_) async => const MobileAccount(articleId, email),
        savedAction: (_, __, pageNumber) async {
          if (pageNumber == 1) {
            return MobileSavedPage.parse(page(1, [article()], more: true), 1);
          }
          throw const MobileAiHttpException(401);
        },
      ),
    ));
    await login(tester);
    await tester.tap(find.byKey(const Key('account-load-more')));
    await tester.pumpAndSettle();
    expect(find.text(email), findsNothing);
    expect(find.text('Source-verified Laravel guide'), findsNothing);
    expect(tester.widget<TextField>(
      find.byKey(const Key('account-email')),
    ).controller!.text, isEmpty);
    expect(find.byKey(const Key('account-login')), findsOneWidget);
  });

  testWidgets('main app exposes distinct saved and profile account surfaces',
      (tester) async {
    Future<void> openSurface(Key entry, String title, String rootKey) async {
      await tester.pumpWidget(WaleedTechApp(
        initiallyStarted: true,
        key: ValueKey<String>(rootKey),
      ));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(find.byKey(entry), 145);
      await tester.ensureVisible(find.byKey(entry));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(entry));
      await tester.pumpAndSettle();
      expect(find.text(title), findsOneWidget);
      expect(find.byKey(const Key('account-unconfigured')), findsOneWidget);
      expect(find.text('Account services are not configured.'), findsOneWidget);
    }

    await openSurface(
      const Key('account-saved-entry'), 'Saved resources', 'saved-surface',
    );
    await openSurface(
      const Key('account-profile-entry'), 'Personal profile', 'profile-surface',
    );
  });
}

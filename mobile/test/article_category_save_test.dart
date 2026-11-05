import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/account/account_journey.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';

const articleId = '00000000-0000-4000-8000-000000000077';
const articleSlug = 'database-restore';

const categories = [
  VerifiedArticleCategory(slug: 'database', name: 'Databases'),
  VerifiedArticleCategory(slug: 'security', name: 'Security'),
];

VerifiedArticle article(String slug, {String? id = articleId}) => VerifiedArticle(
      id: id,
      slug: slug,
      title: 'Published: $slug',
      body: '# Approved publication $slug',
      citations: const ['https://docs.example.org/recovery'],
      categories: categories,
    );

String pageJson({
  int page = 1,
  bool hasMore = false,
  List<Map<String, dynamic>>? rows,
  List<Map<String, String>>? options,
}) =>
    jsonEncode({
      'data': rows ??
          [
            {
              'id': articleId,
              'slug': articleSlug,
              'title': 'Database recovery',
              'body_markdown': '# Restore a verified backup',
              'citations': ['https://docs.example.org/recovery'],
              'tested_status': 'documented',
              'published_at': '2026-11-01T10:00:00+00:00',
              'categories': [
                {'slug': 'database', 'name': 'Databases'}
              ],
            }
          ],
      'meta': {
        'current_page': page,
        'has_more_pages': hasMore,
        'categories': options ??
            [
              {'slug': 'database', 'name': 'Databases'},
              {'slug': 'security', 'name': 'Security'},
            ],
      },
    });

void main() {
  for (final arabic in [false, true]) {
    testWidgets('only acknowledged Save unlocks one in-memory Saved handoff — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      final serverAcknowledgement = Completer<void>();
      var saves = 0;
      var destinations = 0;
      String? handedOffBearer;
      await tester.pumpWidget(MaterialApp(
        home: VerifiedArticleFreshDetail(
          slug: articleSlug,
          arabic: arabic,
          loader: (_) async => article(articleSlug),
          loginAction: (_, __) async => 'session-from-real-login-fixture',
          saveAction: (token, id) async {
            expect(token, 'session-from-real-login-fixture');
            expect(id, articleId);
            saves++;
            await serverAcknowledgement.future;
          },
          onViewSaved: (context, token) async {
            destinations++;
            handedOffBearer = token;
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('verified-article-view-saved')), findsNothing);
      await tester.tap(find.byKey(const Key('verified-article-save')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('article-save-login-email')),
          'reader@example.org');
      await tester.enterText(find.byKey(const Key('article-save-login-password')),
          'fixture-only-password');
      await tester.tap(find.byKey(const Key('article-save-login-submit')));
      await tester.pump();
      expect(saves, 1);
      expect(destinations, 0);
      expect(find.byKey(const Key('verified-article-view-saved')), findsNothing);
      serverAcknowledgement.complete();
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('verified-article-view-saved')), findsOneWidget);
      await tester.tap(find.byKey(const Key('verified-article-view-saved')));
      await tester.pumpAndSettle();
      expect(destinations, 1);
      expect(handedOffBearer, 'session-from-real-login-fixture');
      expect(find.byKey(const Key('verified-article-view-saved')), findsNothing,
          reason: 'Handed-off bearer is erased from article detail after use');
      expect(find.text(handedOffBearer!), findsNothing);
    });

    testWidgets('ambiguous Save never unlocks Saved session handoff — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      var destinations = 0;
      await tester.pumpWidget(MaterialApp(
        home: VerifiedArticleFreshDetail(
          slug: articleSlug,
          arabic: arabic,
          loader: (_) async => article(articleSlug),
          loginAction: (_, __) async => 'session-fixture',
          saveAction: (_, __) async =>
              throw TimeoutException('private-server-trace'),
          onViewSaved: (_, __) async { destinations++; },
        ),
      ));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('verified-article-save')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('article-save-login-email')),
          'reader@example.org');
      await tester.enterText(find.byKey(const Key('article-save-login-password')),
          'fixture-only-password');
      await tester.tap(find.byKey(const Key('article-save-login-submit')));
      await tester.pumpAndSettle();
      expect(destinations, 0);
      expect(find.byKey(const Key('verified-article-view-saved')), findsNothing);
      expect(find.textContaining('private-server-trace'), findsNothing);
      expect(find.byKey(const Key('verified-article-save-status')), findsOneWidget);
    });
  }

  test('only real validated server IDs/categories can unlock article Save', () {
    final page = VerifiedArticlePage.parse(pageJson(), 1);
    expect(page.articles.single.id, articleId);
    expect(page.articles.single.categories.single.slug, 'database');
    expect(page.categories.map((c) => c.slug), ['database', 'security']);
    expect(page.categories.map((c) => c.name), ['Databases', 'Security']);

    final decoded = jsonDecode(pageJson()) as Map<String, dynamic>;
    final rows = decoded['data'] as List<dynamic>;
    final entry = rows.single as Map<String, dynamic>;
    for (final wrongId in ['', 'database-restore', '../admin', '000000000000000000000000000000000000']) {
      entry['id'] = wrongId;
      expect(() => VerifiedArticlePage.parse(jsonEncode(decoded), 1),
          throwsFormatException, reason: 'unverified article UUID');
    }
    entry['id'] = articleId;
    for (final bad in <Object?>[
      'unverified',
      [
        {'slug': 'private', 'name': 'Hidden', 'token': 'SECRET'}
      ],
      [
        {'slug': 'Not A Slug', 'name': 'Invalid'}
      ],
      [
        {'slug': 'database', 'name': 'Databases'},
        {'slug': 'database', 'name': 'Duplicate'}
      ],
      [
        {'slug': 'database', 'name': ''}
      ],
    ]) {
      entry['categories'] = bad;
      expect(() => VerifiedArticlePage.parse(jsonEncode(decoded), 1),
          throwsFormatException, reason: 'unsafe article categories');
    }

    // A malicious taxonomy in the page metadata also fails closed.
    entry['categories'] = [];
    (decoded['meta'] as Map<String, dynamic>)['categories'] = [
      {'slug': 'database', 'name': 'Good'},
      {'slug': 'database', 'name': 'Duplicate'}
    ];
    expect(() => VerifiedArticlePage.parse(jsonEncode(decoded), 1),
        throwsFormatException);
  });

  test('category URL is validated before any client is created', () async {
    for (final wrong in ['../admin', 'database?token=1', 'UPPER', 'x' * 61]) {
      await expectLater(
        const VerifiedArticleReader().loadPage(false, 1, category: wrong),
        throwsA(isA<FormatException>()),
      );
    }
  });


  test('ambiguous Save reconciliation only accepts the same authenticated UUID', () async {
    final first = MobileSavedResource(
      type: 'runbook',
      id: articleId,
      title: 'A different resource',
      detailPath: '/api/v1/mobile/solutions/$articleId',
      savedAt: DateTime.utc(2026, 11, 1),
    );
    final target = MobileSavedResource(
      type: 'article',
      id: articleId,
      title: 'Verified article',
      detailPath: '/api/v1/public/articles/$articleSlug',
      savedAt: DateTime.utc(2026, 11, 1),
    );
    final seen = <int>[];
    final matched = await MobileAccountClient.confirmArticleInSavedList(
      'owner-bearer-only', articleId, (bearer, arabic, page) async {
        expect(bearer, 'owner-bearer-only');
        expect(arabic, isFalse);
        seen.add(page);
        return page == 1
            ? MobileSavedPage([first], page, true)
            : MobileSavedPage([target], page, false);
      },
    );
    expect(matched, isTrue);
    expect(seen, [1, 2]);

    var attempts = 0;
    final missing = await MobileAccountClient.confirmArticleInSavedList(
      'owner-bearer-only', articleId, (_, __, page) async {
        attempts++;
        return MobileSavedPage([first], page, false);
      },
    );
    expect(missing, isFalse, reason: 'Runbook with matching UUID is not an article Save');
    expect(attempts, 1);
  });

  test('unverified pagination cannot authorize an ambiguous Save', () async {
    await expectLater(
      MobileAccountClient.confirmArticleInSavedList(
        'bearer', articleId, (_, __, page) async =>
            MobileSavedPage(const [], page + 1, false),
      ),
      throwsFormatException,
    );
    var reads = 0;
    final missing = await MobileAccountClient.confirmArticleInSavedList(
      'bearer', articleId, (_, __, page) async {
        reads++;
        return MobileSavedPage(const [], page, true);
      },
    );
    expect(missing, isFalse);
    expect(reads, 20, reason: 'Do not scan unbounded private Saved pages');
    await expectLater(
      MobileAccountClient.confirmArticleInSavedList(
        '', articleId, (_, __, ___) async => fail('No network on bad identity'),
      ),
      throwsFormatException,
    );
  });


  test('ambiguous article Save confirmation has one total deadline', () async {
    final neverReplies = Completer<MobileSavedPage>();
    var queries = 0;
    await expectLater(
      MobileAccountClient.confirmArticleInSavedList(
        'same-account-bearer',
        articleId,
        (_, __, ___) {
          queries++;
          return neverReplies.future;
        },
        totalDeadline: const Duration(milliseconds: 5),
      ),
      throwsA(isA<TimeoutException>()),
    );
    expect(queries, 1, reason: 'Never issue another POST or unbounded page read');
    await expectLater(
      MobileAccountClient.confirmArticleInSavedList(
        'same-account-bearer',
        articleId,
        (_, __, ___) async => fail('Invalid deadline must not fetch Saved'),
        totalDeadline: Duration.zero,
      ),
      throwsFormatException,
    );
  });

  test('bookmarks need exact positive HTTP 200 body, UUID and resource type', () {
    final receipt = jsonEncode({
      'data': {
        'resource_type': 'article',
        'resource_id': articleId,
        'saved': true,
      },
    });
    MobileAccountClient.verifyArticleSaved(receipt, articleId);
    for (final bad in [
      '{"data":{"saved":true}}',
      '{"data":{"resource_type":"runbook","resource_id":"$articleId","saved":true}}',
      '{"data":{"resource_type":"article","resource_id":"$articleId","saved":false}}',
      '{"data":{"resource_type":"article","resource_id":"other","saved":true}}',
      '{"data":{"resource_type":"article","resource_id":"$articleId","saved":true,"secret":"x"}}',
      '{"data":{"resource_type":"article","resource_id":"$articleId","saved":true},"meta":{}}',
    ]) {
      expect(() => MobileAccountClient.verifyArticleSaved(bad, articleId),
          throwsFormatException);
    }
  });

  for (final arabic in [false, true]) {
    testWidgets('category selection and next-page remain server-scoped — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      final calls = <String>[];
      Future<VerifiedArticlePage> load(int page, String? category) {
        calls.add('$page:${category ?? 'all'}');
        if (category == null) {
          return SynchronousFuture(VerifiedArticlePage(
            articles: [article('security-first')],
            currentPage: page,
            hasMorePages: false,
            categories: categories,
          ));
        }
        return SynchronousFuture(VerifiedArticlePage(
          articles: [article(page == 1 ? articleSlug : 'database-backup')],
          currentPage: page,
          hasMorePages: page == 1,
          categories: categories,
        ));
      }

      await tester.pumpWidget(MaterialApp(
        home: Directionality(
          textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
          child: VerifiedArticleLibrary(
            arabic: arabic,
            categoryPageLoader: load,
            detailLoader: (_) async => article(articleSlug),
          ),
        ),
      ));
      await tester.pumpAndSettle();
      expect(calls, ['1:all']);
      expect(find.byKey(const Key('article-security-first')), findsOneWidget);
      expect(find.byKey(const Key('article-category-filter')), findsOneWidget);
      await tester.tap(find.byKey(const Key('article-category-filter')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Databases').last);
      await tester.pumpAndSettle();
      expect(calls, ['1:all', '1:database']);
      expect(find.byKey(const Key('article-security-first')), findsNothing);
      expect(find.byKey(const Key('article-$articleSlug')), findsOneWidget);
      await tester.tap(find.byKey(const Key('article-load-more')));
      await tester.pumpAndSettle();
      expect(calls, ['1:all', '1:database', '2:database']);
      expect(find.byKey(const Key('article-database-backup')), findsOneWidget);
      expect(find.byKey(const Key('article-load-more')), findsNothing);
    });
  }

  for (final arabic in [false, true]) {
    testWidgets('guest explicitly logs in before one verified article Save — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      var logins = 0;
      var saves = 0;
      await tester.pumpWidget(MaterialApp(
        home: VerifiedArticleFreshDetail(
          slug: articleSlug,
          arabic: arabic,
          loader: (_) async => article(articleSlug),
          loginAction: (email, password) async {
            expect(email, 'reader@example.org');
            expect(password, 'only-test-password');
            logins++;
            return 'one-time-fixture-token';
          },
          saveAction: (bearer, id) async {
            expect(bearer, 'one-time-fixture-token');
            expect(id, articleId);
            saves++;
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('verified-article-save')), findsOneWidget);
      expect(saves, 0);
      await tester.tap(find.byKey(const Key('verified-article-save')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('article-save-login-email')), findsOneWidget);
      expect(saves, 0, reason: 'Guest Save cannot post without a real login');
      await tester.enterText(find.byKey(const Key('article-save-login-email')),
          'reader@example.org');
      await tester.enterText(find.byKey(const Key('article-save-login-password')),
          'only-test-password');
      await tester.tap(find.byKey(const Key('article-save-login-submit')));
      await tester.pumpAndSettle();
      expect(logins, 1);
      expect(saves, 1);
      expect(tester.widget<FilledButton>(find.byKey(const Key('verified-article-save')))
          .onPressed, isNull, reason: 'Confirmed Save may not issue another POST');
      expect(find.text(arabic
          ? 'تم حفظ المقال الموثق في حسابك.'
          : 'Verified article saved to your account.'), findsOneWidget);
      expect(find.text('one-time-fixture-token'), findsNothing);
      expect(find.text('only-test-password'), findsNothing);
    });

    testWidgets('cancel and unclear Save acknowledgement do not claim success — ${arabic ? 'AR' : 'EN'}',
        (tester) async {
      var attempts = 0;
      await tester.pumpWidget(MaterialApp(
        home: VerifiedArticleFreshDetail(
          slug: articleSlug,
          arabic: arabic,
          loader: (_) async => article(articleSlug),
          loginAction: (_, __) async => 'one-time-fixture-token',
          saveAction: (_, __) async {
            attempts++;
            throw TimeoutException('SECRET_BACKEND_INTERNAL');
          },
        ),
      ));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('verified-article-save')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('article-save-login-cancel')));
      await tester.pumpAndSettle();
      expect(attempts, 0);
      await tester.tap(find.byKey(const Key('verified-article-save')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('article-save-login-email')),
          'reader@example.org');
      await tester.enterText(find.byKey(const Key('article-save-login-password')),
          'not-a-real-password');
      await tester.tap(find.byKey(const Key('article-save-login-submit')));
      await tester.pumpAndSettle();
      expect(attempts, 1);
      final status = find.byKey(const Key('verified-article-save-status'));
      expect(status, findsOneWidget);
      expect(tester.widget<Text>(status).data,
          contains(arabic ? 'محفوظات حسابك' : 'Check your account Saved list'));
      expect(find.textContaining('SECRET_BACKEND_INTERNAL'), findsNothing);
      expect(find.text(arabic ? 'محفوظ' : 'Saved'), findsNothing);
    });
  }

  testWidgets('missing publication UUID cannot trigger a Save or login', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleFreshDetail(
        slug: articleSlug,
        arabic: false,
        loader: (_) async => article(articleSlug, id: null),
        loginAction: (_, __) async => 'unused',
        saveAction: (_, __) async => fail('No UUID must never POST'),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
    expect(find.byKey(const Key('verified-article-save')), findsNothing);
  });

  testWidgets('revoked Save bearer shows safe sign-in recovery', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleFreshDetail(
        slug: articleSlug,
        arabic: false,
        loader: (_) async => article(articleSlug),
        loginAction: (_, __) async => 'fixture-token-secret',
        saveAction: (_, __) async =>
            throw const ArticleSaveAuthorizationException(),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('verified-article-save')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('article-save-login-email')),
        'reader@example.org');
    await tester.enterText(find.byKey(const Key('article-save-login-password')),
        'password');
    await tester.tap(find.byKey(const Key('article-save-login-submit')));
    await tester.pumpAndSettle();
    expect(find.text('Session no longer authorized. Sign in again to save.'),
        findsOneWidget);
    expect(find.text('fixture-token-secret'), findsNothing);
    expect(tester.widget<FilledButton>(find.byKey(const Key('verified-article-save')))
        .onPressed, isNotNull);
  });
}

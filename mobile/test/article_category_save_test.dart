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

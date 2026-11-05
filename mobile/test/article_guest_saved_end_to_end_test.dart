import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/account/account_journey.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';

const articleId = '12345678-1234-4234-8234-123456789abc';
const bearer = 'fixture-only-article-session';
const reviewed = VerifiedArticle(
  id: articleId,
  slug: 'verified-backup',
  title: 'Reviewed backup guide',
  body: 'Published source-verified instructions',
  citations: ['https://docs.example.org/backup'],
);

MobileSavedPage publishedSavedPage() => MobileSavedPage.parse(jsonEncode({
  'data': [
    {
      'resource_type': 'article',
      'resource_id': articleId,
      'title': reviewed.title,
      'detail_url': '/api/v1/public/articles/verified-backup',
      'saved_at': '2026-11-05T10:00:00+00:00',
    },
  ],
  'meta': {'current_page': 1, 'has_more_pages': false},
}), 1);

void main() {
  for (final arabic in [false, true]) {
    testWidgets(
        'MOBILE-07 guest category -> freshly reviewed article -> login -> Save -> account -> reopen (${arabic ? 'AR' : 'EN'})',
        (tester) async {
      var logins = 0;
      var savedPosts = 0;
      var profileReads = 0;
      var savedReads = 0;
      var reopened = 0;
      final categories = [
        const VerifiedArticleCategory(slug: 'backend', name: 'Backend'),
      ];
      final pageCalls = <String?>[];

      await tester.pumpWidget(MaterialApp(
        home: VerifiedArticleLibrary(
          arabic: arabic,
          categoryPageLoader: (page, category) async {
            pageCalls.add(category);
            expect(page, 1);
            return VerifiedArticlePage(
              articles: category == 'backend'
                  ? const [reviewed]
                  : const [VerifiedArticle(
                      slug: 'other-category',
                      title: 'Different published topic',
                      body: 'Not a backup guide',
                      citations: ['https://docs.example.org/other'],
                    )],
              currentPage: page,
              hasMorePages: false,
              categories: categories,
            );
          },
          detailLoader: (slug) async {
            expect(slug, reviewed.slug);
            return reviewed;
          },
          loginAction: (email, password) async {
            logins++;
            expect(email, 'reader@example.org');
            expect(password, 'local-fixture-password');
            return bearer;
          },
          saveAction: (token, id) async {
            savedPosts++;
            expect(token, bearer);
            expect(id, articleId);
          },
          onViewSaved: (context, token) async {
            expect(token, bearer);
            await Navigator.of(context).push<void>(MaterialPageRoute<void>(
              builder: (_) => MobileAccountJourney(
                arabic: arabic,
                initialSession:
                    const MobileAiSession(token: bearer, expiresAt: null),
                profileAction: (token) async {
                  profileReads++;
                  expect(token, bearer);
                  return const MobileAccount(articleId, 'reader@example.org');
                },
                savedAction: (token, locale, page) async {
                  savedReads++;
                  expect(token, bearer);
                  expect(locale, arabic);
                  expect(page, 1);
                  return publishedSavedPage();
                },
                articleAction: (item, locale) async {
                  reopened++;
                  expect(item.id, articleId);
                  expect(item.articleSlug, reviewed.slug);
                  expect(locale, arabic);
                  return reviewed;
                },
              ),
            ));
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('article-verified-backup')), findsNothing);
      expect(find.byKey(const Key('article-other-category')), findsOneWidget);
      await tester.tap(find.byKey(const Key('article-category-filter')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Backend').last);
      await tester.pumpAndSettle();
      expect(pageCalls, [null, 'backend']);
      expect(find.byKey(const Key('article-other-category')), findsNothing);

      await tester.tap(find.byKey(const Key('article-verified-backup')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
      expect(savedPosts, 0, reason: 'Guest reading never sends a private POST');

      await tester.tap(find.byKey(const Key('verified-article-save')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('article-save-login-email')), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('article-save-login-email')),
        'reader@example.org',
      );
      await tester.enterText(
        find.byKey(const Key('article-save-login-password')),
        'local-fixture-password',
      );
      await tester.tap(find.byKey(const Key('article-save-login-submit')));
      await tester.pumpAndSettle();
      expect(logins, 1);
      expect(savedPosts, 1);
      expect(find.byKey(const Key('verified-article-view-saved')),
          findsOneWidget);
      expect(find.text(bearer), findsNothing);

      await tester.tap(find.byKey(const Key('verified-article-view-saved')));
      await tester.pumpAndSettle();
      expect(profileReads, 1);
      expect(savedReads, 1);
      expect(find.byKey(const Key('account-login')), findsNothing);
      expect(find.text(reviewed.title), findsOneWidget);
      expect(find.text(bearer), findsNothing);
      await tester.tap(find.byKey(const Key('account-open-$articleId')));
      await tester.pumpAndSettle();
      expect(reopened, 1);
      expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
      expect(find.textContaining('Published source-verified instructions'),
          findsOneWidget);
      expect(savedPosts, 1, reason: 'Reopen must never repeat the Save POST');
    });
  }

  testWidgets('MOBILE-07 ambiguous Save never claims success or opens Saved',
      (tester) async {
    var posts = 0;
    var navigations = 0;
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleFreshDetail(
        slug: reviewed.slug,
        arabic: false,
        loader: (_) async => reviewed,
        loginAction: (_, __) async => bearer,
        saveAction: (_, __) async {
          posts++;
          throw TimeoutException('fixture-only uncertain network response');
        },
        onViewSaved: (_, __) async { navigations++; },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('verified-article-save')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('article-save-login-email')),
      'reader@example.org',
    );
    await tester.enterText(
      find.byKey(const Key('article-save-login-password')),
      'fixture-password',
    );
    await tester.tap(find.byKey(const Key('article-save-login-submit')));
    await tester.pumpAndSettle();
    expect(posts, 1);
    expect(navigations, 0);
    expect(find.byKey(const Key('verified-article-view-saved')), findsNothing);
    expect(find.textContaining('Save confirmation was not received'),
        findsOneWidget);
    expect(find.text(bearer), findsNothing);
  });
}

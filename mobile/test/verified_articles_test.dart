import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';
import 'package:waleed_tech_companion/main.dart';

void main() {
  const safeJson = '''
  {"data":[{"slug":"database-recovery",
  "title":"Database recovery",
  "body_markdown":"## Verified recovery guide",
  "citations":["https://docs.example.org/recovery"],
  "tested_status":"documented",
  "published_at":"2026-10-31T18:00:00Z"}]}
  ''';

  test('published article parser rejects unsupported or unsafe evidence', () {
    final items = VerifiedArticle.parseList(safeJson);
    expect(items, hasLength(1));
    expect(items.single.slug, 'database-recovery');
    expect(items.single.citations.single, 'https://docs.example.org/recovery');

    expect(() => VerifiedArticle.parseList('{"data":"invalid"}'), throwsFormatException);
    expect(
      () => VerifiedArticle.parseList(
        safeJson.replaceFirst('"documented"', '"unverified"'),
      ),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticle.parseList(
        safeJson.replaceFirst('https://docs.example.org/recovery', 'http://localhost/admin'),
      ),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticle.parseList(
        safeJson.replaceFirst('https://docs.example.org/recovery', 'https://127.0.0.1/internal'),
      ),
      throwsFormatException,
    );

    // No private infrastructure, signed links, credential-bearing URLs, or
    // unusual ports may be shown as public citations, even if the API regresses.
    for (final unsafe in <String>[
      'https://localhost.example.internal/guide',
      'https://docs.private.local/guide',
      'https://docs.example.test/guide',
      'https://docs.example.invalid/guide',
      'https://docs.example.org:8443/guide',
      'https://user:password@docs.example.org/guide',
      'https://docs.example.org/guide?api_key=private',
      'https://docs.example.org/guide?X-Amz-Signature=private',
      'https://docs.example.org/guide?lang=en',
      'https://docs.example.org/guide#private-token',
      'https://[::1]/guide',
    ]) {
      expect(
        () => VerifiedArticle.parseList(
          safeJson.replaceFirst('https://docs.example.org/recovery', unsafe),
        ),
        throwsFormatException,
        reason: 'Unsafe citation must be rejected: $unsafe',
      );
    }

    expect(
      VerifiedArticle.parseList(
        safeJson.replaceFirst(
          'https://docs.example.org/recovery',
          'https://docs.example.org/guide/recovery',
        ),
      ).single.citations.single,
      'https://docs.example.org/guide/recovery',
    );
  });

  testWidgets('unconfigured article server has an honest offline state', (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.scrollUntilVisible(
      find.byKey(const Key('verified-articles-entry')),
      140,
    );
    await tester.ensureVisible(find.byKey(const Key('verified-articles-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('verified-articles-entry')));
    await tester.pumpAndSettle();

    expect(find.text('Technical articles'), findsOneWidget);
    expect(find.textContaining('Article server is not configured'), findsOneWidget);
    expect(find.byKey(const Key('verified-article-body')), findsNothing);
  });

  testWidgets('verified articles open readable body and source citations', (tester) async {
    final items = VerifiedArticle.parseList(safeJson);
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        loader: () => SynchronousFuture<List<VerifiedArticle>>(items),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Database recovery'), findsOneWidget);

    await tester.tap(find.byKey(const Key('article-database-recovery')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
    expect(find.textContaining('Verified recovery guide'), findsOneWidget);
    expect(find.text('https://docs.example.org/recovery'), findsOneWidget);
  });

  testWidgets('Arabic library renders genuine data with RTL layout', (tester) async {
    final items = VerifiedArticle.parseList(safeJson);
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: VerifiedArticleLibrary(
          arabic: true,
          loader: () => SynchronousFuture<List<VerifiedArticle>>(items),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('المقالات التقنية'), findsOneWidget);
    expect(find.text('Database recovery'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('المقالات التقنية'))),
        TextDirection.rtl);
  });

  testWidgets('local article search filters title and body without another API load', (tester) async {
    final items = <VerifiedArticle>[
      ...VerifiedArticle.parseList(safeJson),
      const VerifiedArticle(
        slug: 'network-routing',
        title: 'Network routing',
        body: 'Firewall troubleshooting with published references',
        citations: ['https://docs.example.org/network'],
      ),
    ];
    var loadCount = 0;
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        loader: () {
          loadCount++;
          return SynchronousFuture<List<VerifiedArticle>>(items);
        },
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-database-recovery')), findsOneWidget);
    expect(find.byKey(const Key('article-network-routing')), findsOneWidget);

    final search = find.byKey(const Key('article-search-input'));
    await tester.enterText(search, '  FIREWALL  ');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-network-routing')), findsOneWidget);
    expect(find.byKey(const Key('article-database-recovery')), findsNothing);

    await tester.enterText(search, 'no documented match');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-search-empty')), findsOneWidget);
    expect(find.text('No verified articles match your search.'), findsOneWidget);
    expect(find.byKey(const Key('article-network-routing')), findsNothing);

    await tester.enterText(search, '');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-database-recovery')), findsOneWidget);
    expect(find.byKey(const Key('article-network-routing')), findsOneWidget);
    expect(loadCount, 1, reason: 'Filtering never reloads or uploads search text');
  });

  testWidgets('Arabic article search preserves RTL and localized empty state', (tester) async {
    const items = <VerifiedArticle>[
      VerifiedArticle(
        slug: 'arabic-recovery',
        title: 'دليل الاستعادة',
        body: 'خطوات استعادة قواعد البيانات',
        citations: ['https://docs.example.org/recovery'],
      ),
    ];
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: VerifiedArticleLibrary(
          arabic: true,
          loader: () => SynchronousFuture<List<VerifiedArticle>>(items),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    final search = find.byKey(const Key('article-search-input'));
    expect(find.text('البحث في المقالات الموثقة'), findsOneWidget);
    await tester.enterText(search, 'قواعد');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-arabic-recovery')), findsOneWidget);

    await tester.enterText(search, 'غير موجود');
    await tester.pumpAndSettle();
    expect(find.text('لا توجد مقالات موثقة تطابق البحث.'), findsOneWidget);
    expect(Directionality.of(tester.element(search)), TextDirection.rtl);
  });
}

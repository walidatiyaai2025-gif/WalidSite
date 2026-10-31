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
  });

  testWidgets('unconfigured article server has an honest offline state', (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
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
}

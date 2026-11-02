import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/home/mobile_home_feed.dart';
import 'package:waleed_tech_companion/main.dart';

Map<String, dynamic> runbook(String id, String title) => {
  'id': id,
  'title': title,
  'markdown': '# Reviewed instructions',
  'version': 1,
  'validated_at': '2026-11-02T10:00:00Z',
};

String feed({
  String lang = 'en',
  List<Map<String, dynamic>>? projects,
  List<Map<String, dynamic>>? articles,
  List<Map<String, dynamic>>? runbookItems,
  List<dynamic>? tools,
  bool savedRequiresAuth = true,
}) => jsonEncode({
  'data': {
    'profile': null,
    'featured_projects': projects ?? [
      {
        'slug': 'verified-project',
        'title': 'Verified project',
        'summary': 'Evidence-backed project summary',
        'role': 'Engineering',
        'evidence_urls': ['https://docs.example.org/project'],
        'url': 'https://portfolio.example.org/projects/verified-project',
      },
    ],
    'reading_feed': articles ?? [
      {
        'slug': 'reviewed-article',
        'title': 'Reviewed article',
        'url': 'https://portfolio.example.org/blog/reviewed-article',
        'published_at': '2026-11-02T10:00:00Z',
      },
    ],
    'offline_runbooks': runbookItems ?? [
      runbook('00000000-0000-4000-8000-000000000001', 'Reviewed runbook'),
    ],
    'tools': tools ?? [],
    'saved_requires_auth': savedRequiresAuth,
  },
  'meta': {'lang': lang},
});

void main() {
  test('MOBILE-02 parser accepts bounded reviewed home resources', () {
    final parsed = MobileHomeFeed.parse(feed(), false);
    expect(parsed.projects.single.title, 'Verified project');
    expect(parsed.articles.single.title, 'Reviewed article');
    expect(parsed.runbooks.single.title, 'Reviewed runbook');

    final arabic = MobileHomeFeed.parse(
      feed(
        lang: 'ar',
        projects: const [],
        articles: const [],
        runbookItems: const [],
      ),
      true,
    );
    expect(arabic.projects, isEmpty);
    expect(arabic.articles, isEmpty);
    expect(arabic.runbooks, isEmpty);
  });

  test('MOBILE-02 parser fails closed on unapproved or inconsistent data', () {
    final invalid = <String>[
      feed(lang: 'ar'),
      feed(tools: [{'name': 'UNREVIEWED TOOL'}]),
      feed(savedRequiresAuth: false),
      feed(articles: [
        {
          'slug': 'same',
          'title': 'One',
          'url': 'https://portfolio.example.org/blog/same',
          'published_at': '2026-11-02T10:00:00Z',
        },
        {
          'slug': 'same',
          'title': 'Two',
          'url': 'https://portfolio.example.org/blog/same',
          'published_at': '2026-11-02T10:00:00Z',
        },
      ]),
      jsonEncode({
        'data': {
          'profile': null,
          'featured_projects': [],
          'reading_feed': [],
          'offline_runbooks': [],
          'tools': [],
          'saved_requires_auth': true,
          'private_saved_count': 3,
        },
        'meta': {'lang': 'en'},
      }),
      'x' * (1024 * 1024 + 1),
    ];
    for (final body in invalid) {
      expect(() => MobileHomeFeed.parse(body, false), throwsFormatException);
    }
  });

  testWidgets('unconfigured live feed is explicit and keeps real Home navigation',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp(initiallyStarted: true));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('mobile-home-unconfigured')), findsOneWidget);
    expect(find.textContaining('not configured'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.byKey(const Key('offline-runbooks-entry')),
      120,
    );
    expect(find.byKey(const Key('offline-runbooks-entry')), findsOneWidget);
    await tester.scrollUntilVisible(
      find.byKey(const Key('verified-articles-entry')),
      120,
    );
    expect(find.byKey(const Key('verified-articles-entry')), findsOneWidget);
  });

  testWidgets('verified feed renders server-reviewed titles and real destinations',
      (tester) async {
    var articles = 0;
    var runbooks = 0;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileHomeFeedPanel(
          arabic: false,
          fetch: (_) async => MobileHomeFeed.parse(feed(), false),
          onOpenArticles: () => articles++,
          onOpenRunbooks: () => runbooks++,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('mobile-home-feed')), findsOneWidget);
    expect(find.text('Verified project'), findsOneWidget);
    expect(find.text('Reviewed article'), findsOneWidget);
    expect(find.text('Reviewed runbook'), findsOneWidget);

    await tester.tap(find.byKey(const Key('mobile-home-open-articles')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('mobile-home-open-runbooks')));
    await tester.pump();
    expect(articles, 1);
    expect(runbooks, 1);
  });

  testWidgets('empty reviewed response is truthful and does not invent cards',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileHomeFeedPanel(
          arabic: true,
          fetch: (_) async => MobileHomeFeed.parse(
            feed(
              lang: 'ar',
              projects: const [],
              articles: const [],
              runbookItems: const [],
            ),
            true,
          ),
          onOpenArticles: () {},
          onOpenRunbooks: () {},
        ),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('mobile-home-empty')), findsOneWidget);
    expect(find.text('لا توجد عناصر عامة مراجعة متاحة حاليًا.'), findsOneWidget);
    expect(find.text('Verified project'), findsNothing);
  });

  testWidgets('feed failure is redacted and retry uses the same requested locale',
      (tester) async {
    var calls = 0;
    final locales = <bool>[];
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: MobileHomeFeedPanel(
          arabic: false,
          fetch: (arabic) async {
            calls++;
            locales.add(arabic);
            if (calls == 1) {
              throw const FormatException('PRIVATE_SERVER_TRACE');
            }
            return MobileHomeFeed.parse(feed(), false);
          },
          onOpenArticles: () {},
          onOpenRunbooks: () {},
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('mobile-home-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_SERVER_TRACE'), findsNothing);
    await tester.tap(find.byKey(const Key('mobile-home-retry')));
    await tester.pumpAndSettle();

    expect(calls, 2);
    expect(locales, [false, false]);
    expect(find.byKey(const Key('mobile-home-feed')), findsOneWidget);
  });
}

import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/home/mobile_home_feed.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';
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

  test('MOBILE-02 rejects title controls and bidi identity spoofing', () {
    final normalProject = {
      'slug': 'verified-project',
      'title': 'مشروع موثق',
      'summary': 'Evidence-backed project summary',
      'role': 'Engineering',
      'evidence_urls': ['https://docs.example.org/project'],
      'url': 'https://portfolio.example.org/projects/verified-project',
    };
    final normalArticle = {
      'slug': 'reviewed-article',
      'title': 'مقال موثق',
      'url': 'https://portfolio.example.org/blog/reviewed-article',
      'published_at': '2026-11-02T10:00:00Z',
    };
    for (final malicious in [
      'Approved\\nFake',
      'Approved\\rFake',
      'Approved\\u007fFake',
      'Approved\\u202eFake',
      'Approved\\u2066Fake\\u2069',
    ]) {
      final project = {...normalProject, 'title': malicious};
      final article = {...normalArticle, 'title': malicious};
      expect(() => MobileHomeProject.fromJson(project), throwsFormatException,
          reason: malicious);
      expect(() => MobileHomeArticle.fromJson(article), throwsFormatException,
          reason: malicious);
      expect(() => MobileHomeFeed.parse(
          feed(projects: [project], articles: []), false),
          throwsFormatException);
      expect(() => MobileHomeFeed.parse(
          feed(projects: [], articles: [article]), false),
          throwsFormatException);
    }
    expect(MobileHomeProject.fromJson(normalProject).title, 'مشروع موثق');
    expect(MobileHomeArticle.fromJson(normalArticle).title, 'مقال موثق');
  });

  test('MOBILE-02 Home feed caps actual UTF-8 bytes before JSON parsing', () {
    const limit = 1024 * 1024;
    final baseline = feed();
    // Additional metadata would otherwise be rejected only after decoding.
    // Its UTF-16 length fits the cap but its encoded UTF-8 bytes do not.
    final oversized = baseline.substring(0, baseline.length - 1) +
        ',"padding":"' + ('é' * (limit ~/ 2)) + '"}';
    expect(oversized.length, lessThan(limit));
    expect(utf8.encode(oversized).length, greaterThan(limit));
    expect(() => MobileHomeFeed.parse(oversized, false),
        throwsFormatException);
    expect(MobileHomeFeed.parse(baseline, false).articles, hasLength(1));
  });

  test('MOBILE-02 published article dates require real bounded UTC evidence',
      () {
    final base = {
      'slug': 'reviewed-article',
      'title': 'Reviewed article',
      'url': 'https://portfolio.example.org/blog/reviewed-article',
      'published_at': '2026-11-02T10:00:00Z',
    };
    for (final invalid in [
      '2099-01-01T00:00:00Z',
      DateTime.now().toUtc().add(const Duration(days: 30))
          .toIso8601String(),
      '2026-11-02T10:00:00',
      '2026-11-02',
      '2026-11-02T10:00:00Z' + ('x' * 60),
    ]) {
      final record = {...base, 'published_at': invalid};
      expect(() => MobileHomeArticle.fromJson(record), throwsFormatException,
          reason: invalid);
      expect(
        () => MobileHomeFeed.parse(feed(articles: [record]), false),
        throwsFormatException,
        reason: invalid,
      );
    }
    final nearNow = DateTime.now().toUtc()
        .add(const Duration(minutes: 1)).toIso8601String();
    final valid = {...base, 'published_at': nearNow};
    expect(MobileHomeArticle.fromJson(valid).slug, 'reviewed-article');
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

    await tester.ensureVisible(find.byKey(const Key('mobile-home-open-articles')));
    await tester.tap(find.byKey(const Key('mobile-home-open-articles')));
    await tester.pump();
    await tester.ensureVisible(find.byKey(const Key('mobile-home-open-runbooks')));
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

  test('MOBILE-02 deadline covers a never-completing HTTP body', () async {
    final hung = Completer<void>();
    await expectLater(
      MobileHomeReader.withinDeadline(
        () async => hung.future,
        timeout: const Duration(milliseconds: 10),
      ),
      throwsA(isA<TimeoutException>()),
    );
  });

  test('MOBILE-02 article detail rejects revoked or mismatched evidence', () {
    final published = jsonEncode({
      'data': {
        'slug': 'reviewed-article',
        'title': 'Reviewed article',
        'body_markdown': '# Evidence',
        'citations': ['https://docs.example.org/guide'],
        'tested_status': 'documented',
        'published_at': '2026-11-02T10:00:00Z',
      },
    });
    final ok = VerifiedArticle.parseDetail(published, 'reviewed-article');
    expect(ok.slug, 'reviewed-article');
    expect(ok.body, '# Evidence');

    expect(
      () => VerifiedArticle.parseDetail(published, 'wrong-article'),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticle.parseDetail(
        published.replaceFirst('"documented"', '"revoked"'),
        'reviewed-article',
      ),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticle.parseDetail(
        published.replaceFirst('https://docs.example.org/guide', 'http://127.0.0.1/private'),
        'reviewed-article',
      ),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticle.parseDetail('{"data":{},"private":true}', 'reviewed-article'),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticle.parseDetail(published, '../reviewed-article'),
      throwsFormatException,
    );
  });

  testWidgets('MOBILE-02 reviewed resource rows dispatch exact slugs and ids', (tester) async {
    String? slug;
    String? id;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ListView(children: [
          MobileHomeFeedPanel(
            arabic: false,
            fetch: (_) async => MobileHomeFeed.parse(feed(), false),
            onOpenArticles: () {},
            onOpenRunbooks: () {},
            onOpenArticle: (value) => slug = value,
            onOpenRunbook: (value) => id = value,
          ),
        ]),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('mobile-home-article-reviewed-article')));
    await tester.tap(find.byKey(const Key('mobile-home-article-reviewed-article')));
    await tester.pump();
    expect(slug, 'reviewed-article');

    const runbookId = '00000000-0000-4000-8000-000000000001';
    await tester.ensureVisible(find.byKey(const Key('mobile-home-runbook-$runbookId')));
    await tester.tap(find.byKey(const Key('mobile-home-runbook-$runbookId')));
    await tester.pump();
    expect(id, runbookId);
  });

  testWidgets('MOBILE-02 detail rechecks evidence and retries without leaking exceptions',
      (tester) async {
    var calls = 0;
    Future<VerifiedArticle> load(bool ar, String slug) async {
      calls++;
      if (calls == 1) throw const FormatException('PRIVATE_FAILURE');
      return const VerifiedArticle(
        slug: 'reviewed-article',
        title: 'Reviewed article',
        body: 'Evidence rechecked',
        citations: ['https://docs.example.org/guide'],
      );
    }
    await tester.pumpWidget(MaterialApp(
      home: MobileHomeArticleRoute(
        arabic: false,
        slug: 'reviewed-article',
        fetch: load,
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('mobile-home-article-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_FAILURE'), findsNothing);
    await tester.tap(find.byKey(const Key('mobile-home-article-retry')));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
    expect(find.text('Evidence rechecked'), findsOneWidget);
  });

}

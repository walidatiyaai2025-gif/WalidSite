import 'dart:async';
import 'dart:convert';
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

  test('article API origin accepts only canonical public HTTPS destinations', () {
    for (final trusted in <String>[
      'https://articles.example.org',
      'https://articles.example.org/',
      'https://articles.example.org:443',
      'https://PUBLIC.example.org',
      'https://myhome.example.org',
      'https://lantern.example.org',
      'https://svc-tools.example.org',
    ]) {
      final uri = VerifiedArticleReader.validatedOrigin(trusted);
      expect(uri, isNotNull, reason: 'Public HTTPS origin refused: $trusted');
      expect(uri!.scheme, 'https');
    }

    // The configured server is a build-time value, but even a broken or
    // modified build must not probe local addresses or private service hosts.
    for (final untrusted in <String>[
      '',
      'http://articles.example.org',
      'https://localhost',
      'https://localhost:443',
      'https://127.0.0.1',
      'https://10.42.0.1',
      'https://192.168.1.1',
      'https://[::1]',
      'https://[fc00::1234]',
      'https://database.internal',
      'https://articles.example.local',
      'https://articles.example.test',
      'https://articles.example.invalid',
      'https://articles.example.onion',
      'https://api.internal.example.org.internal',
      'https://resolver.localdomain',
      'https://service.svc',
      'https://tenant.svc.cluster.localdomain',
      'https://router.lan',
      'https://console.home',
      'https://gateway.home.arpa',
      'https://metadata.arpa',
      'https://portal.corp',
      'https://service.intranet',
      'https://database.private',
      'https://PRIVATE.LAN:443',
      'https://articles.example.org:8443',
      'https://articles.example.org:80',
      'https://writer:secret@articles.example.org',
      'https://articles.example.org/api',
      'https://articles.example.org/?token=secret',
      'https://articles.example.org/#secret',
      'https://articles.example.org.',
      'https://nodot',
      'https:///no-host',
    ]) {
      expect(
        VerifiedArticleReader.validatedOrigin(untrusted),
        isNull,
        reason: 'Unsafe article API origin must fail closed: $untrusted',
      );
    }
  });

  test('article feed enforces a total deadline even when bytes keep arriving', () async {
    var delivered = 0;
    final slowStream = Stream<int>.periodic(
      const Duration(milliseconds: 40), (index) => index,
    ).take(6);
    final request = VerifiedArticleReader.withinWholeExchange(() async {
      await for (final _ in slowStream) {
        delivered++;
      }
      return delivered;
    }, deadline: const Duration(milliseconds: 105));

    await expectLater(request, throwsA(isA<TimeoutException>()));
    expect(delivered, lessThan(6),
        reason: 'A stream that yields before every idle timeout must still end');
  });

  test('article list and pages reject oversized JSON before decoding', () {
    const limit = 2 * 1024 * 1024;
    // A valid-looking envelope must not reach jsonDecode if it exceeds
    // the trusted transport size, even when invoked outside HTTP.
    final oversized = '{"data":[],"meta":{"current_page":1,'
        '"has_more_pages":false},"padding":"' + ('a' * limit) + '"}';
    expect(() => VerifiedArticle.parseList(oversized), throwsFormatException);
    expect(() => VerifiedArticlePage.parse(oversized, 1),
        throwsFormatException);

    // UTF-8 may exceed the limit even when Dart's UTF-16 length does not.
    final unicode = '{"data":[],"meta":{"current_page":1,'
        '"has_more_pages":false},"padding":"' + ('é' * (limit ~/ 2)) + '"}';
    expect(unicode.length, lessThan(limit + 256));
    expect(() => VerifiedArticle.parseList(unicode), throwsFormatException);
    expect(() => VerifiedArticlePage.parse(unicode, 1),
        throwsFormatException);

    // Normal bounded legacy lists and metadata still decode.
    expect(VerifiedArticle.parseList(safeJson), hasLength(1));
    final page = jsonEncode({
      'data': (jsonDecode(safeJson) as Map<String, dynamic>)['data'],
      'meta': {'current_page': 1, 'has_more_pages': false},
    });
    expect(VerifiedArticlePage.parse(page, 1).articles, hasLength(1));
  });

  test('standalone article detail rejects UTF-8 oversized JSON before decoding', () {
    const limit = 2 * 1024 * 1024;
    final multibyte = '{"data":{"slug":"database-recovery","padding":"' +
        ('é' * (limit ~/ 2)) + '"}}';
    expect(multibyte.length, lessThan(limit),
        reason: 'The UTF-16 length alone would accept this payload');
    expect(utf8.encode(multibyte).length, greaterThan(limit));
    expect(
      () => VerifiedArticle.parseDetail(multibyte, 'database-recovery'),
      throwsA(isA<FormatException>().having(
          (exception) => exception.message, 'message',
          'Invalid article detail')),
    );

    final valid = jsonEncode({
      'data': (jsonDecode(safeJson) as Map<String, dynamic>)['data'][0],
    });
    expect(VerifiedArticle.parseDetail(valid, 'database-recovery').slug,
        'database-recovery');
  });

  test('article feed rejects duplicate published UUID across different slugs', () {
    const id = '12345678-1234-1234-1234-123456789abc';
    final original = jsonDecode(safeJson) as Map<String, dynamic>;
    final first = Map<String, dynamic>.from(
        (original['data'] as List<dynamic>).single as Map<String, dynamic>);
    first['id'] = id;
    final second = Map<String, dynamic>.from(first)
      ..['slug'] = 'different-published-article'
      ..['id'] = id.toUpperCase();
    final conflicting = jsonEncode({'data': [first, second]});
    expect(() => VerifiedArticle.parseList(conflicting), throwsFormatException,
        reason: 'A Save UUID cannot identify two different visible articles');

    second['id'] = '87654321-4321-4321-4321-cba987654321';
    final unique = VerifiedArticle.parseList(
        jsonEncode({'data': [first, second]}));
    expect(unique, hasLength(2));
    expect(unique.first.id, id);
    expect(unique.last.slug, 'different-published-article');

    // Existing older reading-only fixtures without IDs remain supported.
    expect(VerifiedArticle.parseList(safeJson).single.id, isNull);
  });

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
      'https://service.svc/guide',
      'https://node.localdomain/guide',
      'https://printer.lan/guide',
      'https://dashboard.home/guide',
      'https://gateway.home.arpa/guide',
      'https://resolver.arpa/guide',
      'https://cluster.corp/guide',
      'https://dashboard.intranet/guide',
      'https://datastore.private/guide',
      'https://SYSTEM.SVC:443/private',
      'https://docs.example.test/guide',
      'https://docs.example.invalid/guide',
      'https://docs.example.org:8443/guide',
      'https://user:password@docs.example.org/guide',
      'https://docs.example.org/guide?api_key=private',
      'https://docs.example.org/guide?X-Amz-Signature=private',
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

    // Public pages can use benign, non-credential query parameters.
    for (final allowed in <String>[
      'https://docs.example.org/guide?lang=en',
      'https://docs.example.org/guide?page=2&lang=ar',
      'https://docs.example.org/guide?q=cache%20miss',
      'https://docs.example.org/guide?q=50%25',
      'https://docs.example.org/guide?filter[lang]=ar',
      'https://docs.example.org/guide?source[page]=2',
      'https://docs.example.org/guide?filter%5Blang%5D=ar',
      'https://myhome.example.org/guide?lang=ar',
      'https://lantern.example.org/guide?page=2',
      'https://svc-tools.example.org/guide?q=cache',
    ]) {
      expect(
        VerifiedArticle.parseList(safeJson.replaceFirst(
          'https://docs.example.org/recovery', allowed,
        )).single.citations.single,
        allowed,
        reason: 'Harmless public citation query rejected: $allowed',
      );
    }

    // Encoding a credential key or using a signed URL must never bypass.
    for (final forbidden in <String>[
      'https://docs.example.org/guide?token[0]=private',
      'https://docs.example.org/guide?token[0][part]=private',
      'https://docs.example.org/guide?source[api_key]=private',
      'https://docs.example.org/guide?source[auth][0]=private',
      'https://docs.example.org/guide?source[settings][client_secret]=private',
      'https://docs.example.org/guide?token%5B0%5D=private',
      'https://docs.example.org/guide?source%5Baccess_token%5D=private',
      'https://docs.example.org/guide?source%255Baccess_token%255D=private',
      'https://docs.example.org/guide?X-Amz-Signature[0]=private',
      'https://docs.example.org/guide?meta[X-Goog-Credential]=private',
      'https://docs.example.org/guide?token=private',
      'https://docs.example.org/guide?api_key=private',
      'https://docs.example.org/guide?X-Goog-Signature=private',
      'https://docs.example.org/guide?X-Amz-Signature=private',
      'https://docs.example.org/guide?lang=en%26token%3Dprivate',
      'https://docs.example.org/guide?access%255Ftoken=private',
      'https://docs.example.org/guide?%252525252574oken=private',
      'https://docs.example.org/guide?sessionid=private',
      'https://docs.example.org/guide?lang=en#untrusted',
    ]) {
      expect(
        () => VerifiedArticle.parseList(safeJson.replaceFirst(
          'https://docs.example.org/recovery', forbidden,
        )),
        throwsFormatException,
        reason: 'Unsafe nested query must be rejected: $forbidden',
      );
    }
  });

  testWidgets('unconfigured article server has an honest offline state', (tester) async {
    await tester.pumpWidget(const WaleedTechApp(initiallyStarted: true));
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
        detailLoader: (slug) =>
            SynchronousFuture<VerifiedArticle>(
                items.singleWhere((article) => article.slug == slug)),
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

  testWidgets('withdrawn article is never opened from a stale verified list', (tester) async {
    final cached = VerifiedArticle.parseList(safeJson);
    var reads = 0;
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        loader: () => SynchronousFuture<List<VerifiedArticle>>(cached),
        detailLoader: (slug) async {
          reads++;
          // A revoked server publication returns HTTP 404. The earlier
          // verified list is not authorization to show its former body.
          throw StateError('publication withdrawn');
        },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('article-database-recovery')));
    await tester.pumpAndSettle();

    expect(reads, 1);
    expect(find.byKey(const Key('verified-article-unavailable')), findsOneWidget);
    expect(find.byKey(const Key('verified-article-body')), findsNothing);
    expect(find.textContaining('Verified recovery guide'), findsNothing);
    expect(find.text('https://docs.example.org/recovery'), findsNothing);

    await tester.tap(find.byKey(const Key('verified-article-retry')));
    await tester.pumpAndSettle();
    expect(reads, 2, reason: 'Only the explicit retry may recheck a withdrawn article');
    expect(find.byKey(const Key('verified-article-body')), findsNothing);
  });

  testWidgets('fresh article detail is awaited and rejects wrong publication slug', (tester) async {
    final cached = VerifiedArticle.parseList(safeJson);
    final first = Completer<VerifiedArticle>();
    var reads = 0;
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        loader: () => SynchronousFuture<List<VerifiedArticle>>(cached),
        detailLoader: (slug) {
          reads++;
          if (reads == 1) return first.future;
          return SynchronousFuture<VerifiedArticle>(cached.single);
        },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('article-database-recovery')));
    await tester.pump(const Duration(milliseconds: 100));
    // Route transition timing is platform-dependent. The security assertion
    // is that no stale body appears before a fresh detail receipt, not a
    // specific animation frame or spinner count.
    expect(find.byKey(const Key('verified-article-body')), findsNothing);
    first.complete(const VerifiedArticle(
      slug: 'different-publication',
      title: 'Unrelated title',
      body: 'Untrusted stale content',
      citations: ['https://docs.example.org/other'],
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('verified-article-unavailable')), findsOneWidget);
    expect(find.text('Untrusted stale content'), findsNothing);

    await tester.tap(find.byKey(const Key('verified-article-retry')));
    await tester.pumpAndSettle();
    expect(reads, 2);
    expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
    expect(find.textContaining('Verified recovery guide'), findsOneWidget);
    expect(find.text('https://docs.example.org/recovery'), findsOneWidget);
  });

  testWidgets('in-flight old article cannot overwrite a different RTL detail', (tester) async {
    final previous = Completer<VerifiedArticle>();
    const next = VerifiedArticle(
      slug: 'next-verified',
      title: 'دليل منشور',
      body: 'محتوى موثق جديد',
      citations: ['https://docs.example.org/new'],
    );
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleFreshDetail(
        slug: 'previous-verified',
        arabic: false,
        loader: (_) => previous.future,
      ),
    ));
    await tester.pump(const Duration(milliseconds: 1));
    expect(find.byKey(const Key('verified-article-body')), findsNothing);

    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleFreshDetail(
        slug: 'next-verified',
        arabic: true,
        loader: (_) => SynchronousFuture<VerifiedArticle>(next),
      ),
    ));
    await tester.pumpAndSettle();
    previous.complete(const VerifiedArticle(
      slug: 'previous-verified',
      title: 'Earlier revoked',
      body: 'Old private revision',
      citations: ['https://docs.example.org/old'],
    ));
    await tester.pumpAndSettle();
    expect(find.text('محتوى موثق جديد'), findsOneWidget);
    expect(find.text('Old private revision'), findsNothing);
    expect(find.text('https://docs.example.org/old'), findsNothing);
    expect(Directionality.of(tester.element(
      find.byKey(const Key('verified-article-body')),
    )), TextDirection.rtl);
  });

  testWidgets('Arabic withdrawn article gives safe RTL recovery', (tester) async {
    final cached = VerifiedArticle.parseList(safeJson);
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: VerifiedArticleLibrary(
          arabic: true,
          loader: () => SynchronousFuture<List<VerifiedArticle>>(cached),
          detailLoader: (_) async => throw StateError('revoked'),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('article-database-recovery')));
    await tester.pumpAndSettle();
    final notice = find.byKey(const Key('verified-article-unavailable'));
    expect(notice, findsOneWidget);
    expect(Directionality.of(tester.element(notice)), TextDirection.rtl);
    expect(find.text('إعادة التحقق'), findsOneWidget);
    expect(find.byKey(const Key('verified-article-body')), findsNothing);
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

    // Multi-term search spans a visible title and a visible body; terms
    // must all match, and no additional API request is permitted.
    await tester.enterText(search, 'network firewall');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-network-routing')), findsOneWidget);
    expect(find.byKey(const Key('article-database-recovery')), findsNothing);
    expect(loadCount, 1);

    await tester.enterText(search, 'network nonexistent');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-search-empty')), findsOneWidget);

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

  testWidgets('Arabic article search tolerates marks and unaccented Alef and Ya',
      (tester) async {
    const items = <VerifiedArticle>[
      VerifiedArticle(
        slug: 'arabic-admin-guide',
        title: 'إِدارةُ خوادمِ قواعد البيانات',
        body: 'خُطَّةُ التعافِي موثقة بمراجع رسمية',
        citations: ['https://docs.example.org/admin'],
      ),
      VerifiedArticle(
        slug: 'arabic-network-guide',
        title: 'دليل الشبكة',
        body: 'خطوات تشخيص الاتصال',
        citations: ['https://docs.example.org/network'],
      ),
    ];
    var reads = 0;
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: VerifiedArticleLibrary(
          arabic: true,
          loader: () {
            reads++;
            return SynchronousFuture<List<VerifiedArticle>>(items);
          },
        ),
      ),
    ));
    await tester.pumpAndSettle();
    final search = find.byKey(const Key('article-search-input'));

    await tester.enterText(search, 'ادارة التعافي');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-arabic-admin-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-arabic-network-guide')), findsNothing);
    expect(reads, 1, reason: 'Search must remain strictly on device');

    await tester.enterText(search, 'خوادم موثقة');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-arabic-admin-guide')), findsOneWidget);

    await tester.enterText(search, 'اداره غائبة');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-search-empty')), findsOneWidget);

    await tester.enterText(search, 'database');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-search-empty')), findsOneWidget);

    await tester.enterText(search, '   ');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-arabic-admin-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-arabic-network-guide')), findsOneWidget);
    expect(reads, 1);
    expect(Directionality.of(tester.element(search)), TextDirection.rtl);
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
  test('verified article pages reject forged or inconsistent pagination metadata', () {
    final pageJson = safeJson.replaceFirst(
      '"data":',
      '"meta":{"current_page":1,"has_more_pages":true},"data":',
    );
    final page = VerifiedArticlePage.parse(pageJson, 1);
    expect(page.currentPage, 1);
    expect(page.hasMorePages, isTrue);
    expect(page.articles.single.slug, 'database-recovery');

    expect(() => VerifiedArticlePage.parse(pageJson, 2), throwsFormatException);
    expect(
      () => VerifiedArticlePage.parse(
        pageJson.replaceFirst('"has_more_pages":true',
            '"has_more_pages":"yes"'), 1),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticlePage.parse(
        pageJson.replaceFirst('"data":[{', '"data":[{')
            .replaceFirst('"meta":', '"other":'),
        1,
      ),
      throwsFormatException,
    );
    expect(() => VerifiedArticlePage.parse(pageJson, 0), throwsFormatException);
    expect(() => VerifiedArticlePage.parse(pageJson, 10001), throwsFormatException);
    expect(
      () => VerifiedArticlePage.parse(
        '{"meta":{"current_page":1,"has_more_pages":true},"data":[]}', 1),
      throwsFormatException,
    );
    expect(
      () => VerifiedArticlePage.parse(
        pageJson.replaceFirst(
          'https://docs.example.org/recovery',
          'https://docs.example.org/recovery?token=secret',
        ), 1,
      ),
      throwsFormatException,
    );

    final tooMany = List.generate(13, (index) => {
      'slug': 'guide-$index',
      'title': 'Verified $index',
      'body_markdown': 'Published with safe sources',
      'citations': ['https://docs.example.org/verified'],
      'tested_status': 'documented',
      'published_at': '2026-10-31T18:00:00Z',
    });
    expect(
      () => VerifiedArticlePage.parse(
        jsonEncode({'meta': {'current_page': 1, 'has_more_pages': false},
            'data': tooMany}), 1),
      throwsFormatException,
    );
  });

  testWidgets('load more reveals the next verified publications only on demand',
      (tester) async {
    const first = VerifiedArticle(
      slug: 'first-guide',
      title: 'First verified guide',
      body: 'Safe published first page',
      citations: ['https://docs.example.org/first'],
    );
    const second = VerifiedArticle(
      slug: 'later-guide',
      title: 'Later verified guide',
      body: 'Safe published second page',
      citations: ['https://docs.example.org/later'],
    );
    final calls = <int>[];
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        pageLoader: (page) {
          calls.add(page);
          return SynchronousFuture(VerifiedArticlePage(
            articles: page == 1 ? [first] : [second],
            currentPage: page,
            hasMorePages: page == 1,
          ));
        },
      ),
    ));
    await tester.pumpAndSettle();
    expect(calls, [1], reason: 'No speculative or background page downloads');
    expect(find.byKey(const Key('article-first-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-later-guide')), findsNothing);
    expect(find.text('Searches loaded articles on this device only'),
        findsOneWidget);

    await tester.tap(find.byKey(const Key('article-load-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('article-first-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-later-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-load-more')), findsNothing);
    await tester.enterText(
        find.byKey(const Key('article-search-input')), 'LATER');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-later-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-first-guide')), findsNothing);
    expect(calls, [1, 2],
        reason: 'The user search is local, never sent to the server');
  });

  testWidgets('failed next page retains trusted items and retries same page',
      (tester) async {
    const first = VerifiedArticle(
      slug: 'safe-guide',
      title: 'Safe guide',
      body: 'Readable after a network failure',
      citations: ['https://docs.example.org/guide'],
    );
    const second = VerifiedArticle(
      slug: 'restored-guide',
      title: 'Restored guide',
      body: 'New content after retry',
      citations: ['https://docs.example.org/restored'],
    );
    final requests = <int>[];
    var fail = true;
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: true,
        pageLoader: (page) {
          requests.add(page);
          if (page == 1) {
            return SynchronousFuture(const VerifiedArticlePage(
              articles: [first], currentPage: 1, hasMorePages: true,
            ));
          }
          if (fail) {
            fail = false;
            return Future.error(const FormatException('Do not leak transport URL'));
          }
          return SynchronousFuture(const VerifiedArticlePage(
            articles: [second], currentPage: 2, hasMorePages: false,
          ));
        },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('article-load-more')));
    await tester.pumpAndSettle();
    expect(requests, [1, 2]);
    expect(find.byKey(const Key('article-safe-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-restored-guide')), findsNothing);
    expect(find.byKey(const Key('article-load-more-error')), findsOneWidget);
    expect(find.textContaining('Do not leak transport URL'), findsNothing);

    await tester.tap(find.byKey(const Key('article-load-more')));
    await tester.pumpAndSettle();
    expect(requests, [1, 2, 2],
        reason: 'Retry must not skip a failed page');
    expect(find.byKey(const Key('article-safe-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-restored-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-load-more-error')), findsNothing);
  });

  testWidgets('a later page cannot reuse a published Save UUID with a new slug',
      (tester) async {
    const first = VerifiedArticle(
      id: '12345678-1234-1234-1234-123456789abc',
      slug: 'original-guide',
      title: 'Original published guide',
      body: 'Published first page',
      citations: ['https://docs.example.org/first'],
    );
    const conflicting = VerifiedArticle(
      id: '12345678-1234-1234-1234-123456789ABC',
      slug: 'different-guide',
      title: 'Conflicting identity',
      body: 'Unsafe duplicate Save destination',
      citations: ['https://docs.example.org/second'],
    );
    final calls = <int>[];
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        pageLoader: (page) {
          calls.add(page);
          return SynchronousFuture(VerifiedArticlePage(
            articles: page == 1 ? [first] : [conflicting],
            currentPage: page,
            hasMorePages: page == 1,
          ));
        },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('article-load-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('article-load-more-error')), findsOneWidget);
    expect(find.byKey(const Key('article-original-guide')), findsOneWidget);
    expect(find.byKey(const Key('article-different-guide')), findsNothing);
    expect(find.text('Conflicting identity'), findsNothing);
    // No skipped page: the conflicting response can be safely retried.
    await tester.tap(find.byKey(const Key('article-load-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2, 2]);
  });

  testWidgets('duplicate next-page article cannot overwrite verified content',
      (tester) async {
    const article = VerifiedArticle(
      slug: 'same-guide',
      title: 'One approved guide',
      body: 'Approved original content',
      citations: ['https://docs.example.org/approved'],
    );
    await tester.pumpWidget(MaterialApp(
      home: VerifiedArticleLibrary(
        arabic: false,
        pageLoader: (page) => SynchronousFuture(VerifiedArticlePage(
          articles: [article],
          currentPage: page,
          hasMorePages: page == 1,
        )),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('article-load-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('article-load-more-error')), findsOneWidget);
    expect(find.byKey(const Key('article-same-guide')), findsOneWidget);
    expect(find.text('One approved guide'), findsOneWidget);
  });

}

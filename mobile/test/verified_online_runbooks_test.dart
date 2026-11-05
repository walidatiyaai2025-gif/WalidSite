import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

const firstId = '00000000-0000-4000-8000-000000000001';
const secondId = '00000000-0000-4000-8000-000000000002';

Map<String, dynamic> guide({
  String id = firstId,
  String title = 'Reviewed database restoration',
  String body = '# Verify backup before restoring',
  int version = 2,
}) => {
  'id': id,
  'title': title,
  'markdown': body,
  'version': version,
  'validated_at': '2026-10-31T12:30:00+00:00',
};

String response(
  int page,
  List<Map<String, dynamic>> guides, {
  bool more = false,
  List<Map<String, dynamic>>? categories,
}) => jsonEncode({
  'data': guides,
  'meta': {
    'current_page': page,
    'has_more_pages': more,
    if (categories != null) 'categories': categories,
  },
});

void main() {
  test('MOBILE-04 reviewed solution stream has one total timeout', () async {
    final neverEndingBody = Completer<void>();
    await expectLater(
      ReviewedRunbookReader.withinDeadline(
        () async {
          await neverEndingBody.future;
          return 'not reached';
        },
        timeout: const Duration(milliseconds: 10),
      ),
      throwsA(isA<TimeoutException>()),
    );
    expect(
      await ReviewedRunbookReader.withinDeadline(
        () async => '# published',
        timeout: const Duration(seconds: 1),
      ),
      '# published',
    );
  });

  test('MOBILE-04 real HTTP slow-drip response cannot extend total deadline', () async {
    final previous = HttpOverrides.current;
    HttpOverrides.global = null;
    HttpServer? server;
    final stop = Completer<void>();
    var requests = 0;
    try {
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      server.listen((request) async {
        requests++;
        try {
          request.response.headers.contentType = ContentType.json;
          request.response.write('{"data":');
          await request.response.flush();
          while (!stop.isCompleted) {
            await Future<void>.delayed(const Duration(milliseconds: 20));
            if (stop.isCompleted) break;
            request.response.write(' ');
            await request.response.flush();
          }
        } catch (_) {
          // The expected forced socket close ends this response.
        }
      });
      await expectLater(
        ReviewedRunbookReader.fetchHttp(
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/solutions/$firstId'),
          timeout: const Duration(milliseconds: 350),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1, reason: 'A stalled download must not be retried');
    } finally {
      stop.complete();
      await server?.close(force: true);
      HttpOverrides.global = previous;
    }
  });

  test('MOBILE-04 real HTTP stalled headers obey whole-request deadline', () async {
    final previous = HttpOverrides.current;
    HttpOverrides.global = null;
    HttpServer? server;
    final release = Completer<void>();
    var requests = 0;
    try {
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      server.listen((request) async {
        requests++;
        await release.future;
        try {
          await request.response.close();
        } catch (_) {
          // Expected: the client aborted the timed-out connection.
        }
      });
      await expectLater(
        ReviewedRunbookReader.fetchHttp(
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/solutions/$firstId'),
          timeout: const Duration(milliseconds: 350),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
    } finally {
      release.complete();
      await server?.close(force: true);
      HttpOverrides.global = previous;
    }
  });

  test('MOBILE-04 real HTTP quick reviewed response preserves UTF-8', () async {
    final previous = HttpOverrides.current;
    HttpOverrides.global = null;
    HttpServer? server;
    try {
      server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
      server.listen((request) async {
        request.response.headers.contentType = ContentType.json;
        request.response.write('{"data":"حل تمت مراجعته"}');
        await request.response.close();
      });
      final body = await ReviewedRunbookReader.fetchHttp(
        Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/solutions/$firstId'),
        timeout: const Duration(seconds: 2),
      );
      expect(body, '{"data":"حل تمت مراجعته"}');
    } finally {
      await server?.close(force: true);
      HttpOverrides.global = previous;
    }
  });

  test('parses reviewed UUID guide, version, UTC timestamp and bounded pages', () {
    final page = ReviewedRunbookPage.parse(response(1, [
      guide(title: 'إدارة النسخ الاحتياطية', body: '# مراجعة نسخة موثقة'),
    ], more: true), 1);
    expect(page.currentPage, 1);
    expect(page.hasMorePages, true);
    expect(page.items.single.id, firstId);
    expect(page.items.single.title, 'إدارة النسخ الاحتياطية');
    expect(page.items.single.markdown, '# مراجعة نسخة موثقة');
    expect(page.items.single.version, 2);
    expect(page.items.single.validatedAt.isUtc, isTrue);
    expect(page.items.single.validatedAt.year, 2026);
  });

  test('rejects UTF-8 oversized runbook page before JSON decode', () {
    const maxBytes = 1024 * 1024;
    // Multibyte content can fit below the Dart string-length cap while
    // exceeding the transport's actual 1 MiB byte limit. Unknown metadata
    // would otherwise be ignored after decoding.
    final raw = response(1, [guide()]);
    final oversized = raw.substring(0, raw.length - 1) +
        ',"padding":"' + ('é' * (maxBytes ~/ 2)) + '"}';
    expect(oversized.length, lessThan(maxBytes));
    expect(utf8.encode(oversized).length, greaterThan(maxBytes));
    expect(() => ReviewedRunbookPage.parse(oversized, 1),
        throwsFormatException);
    expect(ReviewedRunbookPage.parse(raw, 1).items, hasLength(1));
  });

  test('reviewed Markdown is bounded by encoded UTF-8 bytes', () {
    const maxBytes = 65536;
    final multibyte = 'é' * 33000;
    expect(multibyte.length, lessThan(maxBytes));
    expect(utf8.encode(multibyte).length, greaterThan(maxBytes));
    // A code-unit-only check would accept this as a published runbook.
    expect(
      () => ReviewedRunbook.fromJson(guide(body: multibyte)),
      throwsFormatException,
    );
    expect(
      () => ReviewedRunbookPage.parse(response(1, [guide(body: multibyte)]), 1),
      throwsFormatException,
    );
    final valid = 'دليل ' + ('é' * 16000);
    expect(utf8.encode(valid).length, lessThan(maxBytes));
    expect(ReviewedRunbook.fromJson(guide(body: valid)).markdown, valid);
  });

  test('rejects untrusted, malformed and oversized runbook data', () {
    final invalid = <String>[
      '{}',
      jsonEncode({'data': 'not-list', 'meta': {}}),
      response(2, [guide()]),
      response(1, [], more: true),
      response(1, [guide(), guide()]),
      response(1, [guide()..['id'] = 'not-a-uuid']),
      response(1, [guide()..['title'] = '']),
      response(1, [guide()..['title'] = 'x' * 221]),
      response(1, [guide()..['markdown'] = '  ']),
      response(1, [guide()..['markdown'] = 'X' * 65537]),
      response(1, [guide()..['version'] = 0]),
      response(1, [guide()..['validated_at'] = '2026-10-31']),
      response(1, [guide()..['validated_at'] = 'nonsense']),
      response(1, [guide()..['private_token'] = 'SECRET']),
      response(1, [guide()..['source_url'] = 'https://private.internal']),
      response(1, [
        for (var i = 0; i < 21; i++)
          guide(id: '00000000-0000-4000-8000-' +
              (i + 1).toString().padLeft(12, '0')),
      ]),
      'x' * (1024 * 1024 + 1),
    ];
    for (final raw in invalid) {
      expect(() => ReviewedRunbookPage.parse(raw, 1),
          throwsFormatException, reason: raw.substring(0, raw.length > 80 ? 80 : raw.length));
    }
    expect(() => ReviewedRunbookPage.parse(response(1, []), 0),
        throwsFormatException);
    expect(() => ReviewedRunbookPage.parse(response(1, []), 10001),
        throwsFormatException);
    expect(() => ReviewedRunbookPage.parse(response(10000, [guide()], more: true), 10000),
        throwsFormatException);
  });

  test('refuses unsafe API origins before opening any network connection', () {
    for (final bad in [
      '', 'http://docs.example.org', 'https://localhost',
      'https://127.0.0.1', 'https://192.168.0.1',
      'https://service.internal', 'https://server.svc',
      'https://router.home', 'https://gateway.home.arpa',
      'https://node.lan', 'https://docs.example.test',
      'https://docs.example.org:8443',
      'https://user:secret@docs.example.org',
      'https://docs.example.org/private',
      'https://docs.example.org?token=secret',
    ]) {
      expect(ReviewedRunbookReader.validatedOrigin(bad), isNull,
          reason: bad);
    }
    expect(ReviewedRunbookReader.validatedOrigin('https://docs.example.org')
        ?.host, 'docs.example.org');
    expect(ReviewedRunbookReader.validatedOrigin('https://docs.example.org:443')
        ?.scheme, 'https');
  });

  testWidgets('home entry opens honest unconfigured online state separately from offline pack',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp(initiallyStarted: true));
    await tester.scrollUntilVisible(
      find.byKey(const Key('online-runbooks-entry')), 140,
    );
    await tester.ensureVisible(find.byKey(const Key('online-runbooks-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('online-runbooks-entry')));
    await tester.pumpAndSettle();
    expect(find.text('Verified runbooks'), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-unconfigured')), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-body')), findsNothing);
  });

  testWidgets('paginated verified server catalog supports retry-free loading and local search',
      (tester) async {
    final calls = <int>[];
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: false,
      pageLoader: (page) {
        calls.add(page);
        if (page == 1) {
          return SynchronousFuture(ReviewedRunbookPage.parse(response(1, [
            guide(),
          ], more: true), 1));
        }
        return SynchronousFuture(ReviewedRunbookPage.parse(response(2, [
          guide(id: secondId, title: 'Network recovery checklist',
              body: '# Resolve firewall issue'),
        ]), 2));
      },
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-' + firstId)), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-more')), findsOneWidget);
    expect(calls, [1]);
    await tester.tap(find.byKey(const Key('online-runbook-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('online-runbook-' + secondId)), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-more')), findsNothing);

    await tester.enterText(find.byKey(const Key('online-runbook-search')),
        'NETWORK FIREWALL');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-' + firstId)), findsNothing);
    expect(find.byKey(const Key('online-runbook-' + secondId)), findsOneWidget);
    await tester.enterText(find.byKey(const Key('online-runbook-search')),
        'missing archived tutorial');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-search-empty')), findsOneWidget);
  });

  testWidgets('Arabic search accepts unvowelled Alef variants and opens fresh current detail',
      (tester) async {
    final saved = ReviewedRunbook.fromJson(guide(
      title: 'إِدارةُ النسخ', body: '# مراجعة قاعدة البيانات',
    ));
    final current = ReviewedRunbook.fromJson(guide(
      title: 'إِدارةُ النسخ', body: '# نسخة عامة جديدة بعد التحقق',
      version: 3,
    ));
    var detailCalls = 0;
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(textDirection: TextDirection.rtl,
        child: ReviewedRunbookLibrary(
          arabic: true,
          pageLoader: (_) => SynchronousFuture(ReviewedRunbookPage(
            items: [saved], currentPage: 1, hasMorePages: false,
          )),
          detailLoader: (id) async {
            detailCalls++;
            expect(id, firstId);
            return current;
          },
        ),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('الأدلة المنشورة'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('online-runbook-search')),
        'ادارة قاعدة');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-' + firstId)), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('الأدلة المنشورة'))),
        TextDirection.rtl);
    await tester.tap(find.byKey(const Key('online-runbook-' + firstId)));
    await tester.pumpAndSettle();
    expect(detailCalls, 1);
    expect(find.byKey(const Key('online-runbook-body')), findsOneWidget);
    expect(find.textContaining('نسخة عامة جديدة'), findsOneWidget);
    expect(find.textContaining('مراجعة قاعدة البيانات'), findsNothing);
    expect(find.text('إصدار تمت مراجعته: 3'), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-copy')), findsOneWidget);
    await tester.tap(find.byKey(const Key('online-runbook-copy')));
    await tester.pumpAndSettle();
  });

  testWidgets('withdrawn details stay hidden even when previously present in listing',
      (tester) async {
    var calls = 0;
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: false,
      pageLoader: (_) => SynchronousFuture(ReviewedRunbookPage(
        items: [ReviewedRunbook.fromJson(guide(body: '# Stale listed body'))],
        currentPage: 1, hasMorePages: false,
      )),
      detailLoader: (_) async {
        calls++;
        if (calls == 1) {
          throw const HttpException('PRIVATE_API_TOKEN_NOT_FOR_USER');
        }
        return ReviewedRunbook.fromJson(guide(body: '# Fresh reviewed content'));
      },
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('online-runbook-' + firstId)));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-detail-unavailable')), findsOneWidget);
    expect(find.textContaining('Stale listed body'), findsNothing);
    expect(find.textContaining('PRIVATE_API_TOKEN_NOT_FOR_USER'), findsNothing);
    await tester.tap(find.byKey(const Key('online-runbook-detail-retry')));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.textContaining('Fresh reviewed content'), findsOneWidget);
  });

  testWidgets('load-more failure and cross-page replay fail closed with honest retry',
      (tester) async {
    var page2 = 0;
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: false,
      pageLoader: (page) async {
        if (page == 1) {
          return ReviewedRunbookPage.parse(
            response(1, [guide()], more: true), 1);
        }
        page2++;
        if (page2 == 1) throw const HttpException('PRIVATE_SERVER_DETAIL');
        if (page2 == 2) {
          // Replayed first-page ID must not append stale data or advance.
          return ReviewedRunbookPage.parse(response(2, [guide()]), 2);
        }
        return ReviewedRunbookPage.parse(
          response(2, [guide(id: secondId)]), 2);
      },
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('online-runbook-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-more-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_SERVER_DETAIL'), findsNothing);
    await tester.tap(find.byKey(const Key('online-runbook-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-' + secondId)), findsNothing);
    expect(find.byKey(const Key('online-runbook-more-error')), findsOneWidget);
    await tester.tap(find.byKey(const Key('online-runbook-more')));
    await tester.pumpAndSettle();
    expect(page2, 3);
    expect(find.byKey(const Key('online-runbook-' + secondId)), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-more')), findsNothing);
  });

  test('reviewed category labels are validated and legacy remains uncategorized', () {
    const options = [
      {'slug': 'database', 'name': 'Databases'},
      {'slug': 'security', 'name': 'Security'},
    ];
    final page = ReviewedRunbookPage.parse(
      response(1, [guide()], categories: options), 1);
    expect(page.categories.map((item) => item.slug), ['database', 'security']);
    expect(page.categories.map((item) => item.name), ['Databases', 'Security']);
    expect(ReviewedRunbookPage.parse(response(1, [guide()]), 1).categories, isEmpty);

    for (final invalid in <List<Map<String, dynamic>>>[
      [{}],
      [{'slug': 'database', 'name': ''}],
      [{'slug': 'database', 'name': 'Valid', 'secret': 'x'}],
      [{'slug': 'database/../../secret', 'name': 'Wrong'}],
      [{'slug': 'database--backup', 'name': 'Wrong'}],
      [{'slug': 'database', 'name': 'Valid'}, {'slug': 'database', 'name': 'Duplicate'}],
      [{'slug': 'private', 'name': 'line\nbreak'}],
    ]) {
      expect(() => ReviewedRunbookPage.parse(
        response(1, [guide()], categories: invalid), 1,
      ), throwsFormatException);
    }
    expect(() => ReviewedRunbookCategory.parse(
      List.filled(201, {'slug': 'database', 'name': 'Databases'}),
    ), throwsFormatException);
  });

  test('runbook category path and array injection are blocked before network',
      () async {
    for (final bad in [
      'DATABASE', '../drafts', 'security/../../private',
      'database--backup', 'not safe', 'a' * 61,
    ]) {
      await expectLater(
        const ReviewedRunbookReader().loadPage(1, category: bad),
        throwsFormatException,
      );
    }
  });

  for (final arabic in [false, true]) {
    testWidgets('MOBILE-05 server category pagination ' +
        (arabic ? 'Arabic' : 'English'), (tester) async {
      const optionsEn = <Map<String, dynamic>>[
        {'slug': 'database', 'name': 'Databases'},
        {'slug': 'security', 'name': 'Security'},
      ];
      const optionsAr = <Map<String, dynamic>>[
        {'slug': 'database', 'name': 'قواعد البيانات'},
        {'slug': 'security', 'name': 'الأمان'},
      ];
      final options = arabic ? optionsAr : optionsEn;
      final seen = <String>[];
      Future<ReviewedRunbookPage> load(int page, String? selected) {
        seen.add(page.toString() + ':' + (selected ?? 'all'));
        final raw = selected == 'database'
            ? response(page, page == 1
                ? [guide(title: 'Reviewed DB guide')]
                : [guide(id: secondId, title: 'Second DB guide')],
                more: page == 1, categories: options)
            : selected == 'security'
                ? response(page, [], categories: options)
                : response(page, [guide()], categories: options);
        return SynchronousFuture(ReviewedRunbookPage.parse(raw, page));
      }
      await tester.pumpWidget(MaterialApp(
        locale: Locale(arabic ? 'ar' : 'en'),
        home: Directionality(
          textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
          child: ReviewedRunbookLibrary(
            arabic: arabic,
            categoryPageLoader: load,
          ),
        ),
      ));
      await tester.pumpAndSettle();
      expect(seen, ['1:all']);
      const filter = Key('online-runbook-category-filter');
      expect(find.byKey(filter), findsOneWidget);
      await tester.tap(find.byKey(filter));
      await tester.pumpAndSettle();
      await tester.tap(find.text(arabic ? 'قواعد البيانات' : 'Databases').last);
      await tester.pumpAndSettle();
      expect(seen.last, '1:database');
      expect(find.byKey(const Key('online-runbook-more')), findsOneWidget);
      await tester.tap(find.byKey(const Key('online-runbook-more')));
      await tester.pumpAndSettle();
      expect(seen.last, '2:database');
      expect(find.byKey(const Key('online-runbook-' + secondId)), findsOneWidget);
      expect(find.byKey(const Key('online-runbook-more')), findsNothing);

      await tester.ensureVisible(find.byKey(filter));
      await tester.tap(find.byKey(filter));
      await tester.pumpAndSettle();
      await tester.tap(find.text(arabic ? 'الأمان' : 'Security').last);
      await tester.pumpAndSettle();
      expect(seen.last, '1:security');
      expect(find.byKey(const Key('online-runbook-' + firstId)), findsNothing);
      expect(find.byKey(const Key('online-runbook-' + secondId)), findsNothing);
      expect(find.text(arabic
          ? 'لا توجد أدلة معتمدة ضمن هذا التصنيف.'
          : 'No reviewed runbooks in this category.'), findsOneWidget);
      expect(find.byKey(filter), findsOneWidget,
          reason: 'Category choices remain visible for an empty filter');
    });
  }


}

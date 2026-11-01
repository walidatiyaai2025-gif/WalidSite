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
}) => jsonEncode({
  'data': guides,
  'meta': {'current_page': page, 'has_more_pages': more},
});

void main() {
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
    await tester.pumpWidget(const WaleedTechApp());
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
}

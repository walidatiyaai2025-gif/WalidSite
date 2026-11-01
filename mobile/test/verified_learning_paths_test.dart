import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/learning/verified_learning_paths.dart';
import 'package:waleed_tech_companion/main.dart';

const idOne = '00000000-0000-4000-8000-000000000001';
const idTwo = '00000000-0000-4000-8000-000000000002';

Map<String, dynamic> track({
  String id = idOne,
  String slug = 'database-recovery',
  String title = 'Verified database recovery',
}) => {
  'id': id,
  'slug': slug,
  'title': title,
  'outline': [
    {'title': 'Backup verification', 'description': 'Check documented backups'},
    {'title': 'استعادة البيانات', 'description': 'تجربة الاستعادة'},
  ],
};

String response(int page, List<Map<String, dynamic>> tracks, {bool more = false}) =>
    jsonEncode({
      'data': tracks,
      'meta': {'current_page': page, 'has_more_pages': more},
    });

void main() {
  test('public origin validation rejects internal DNS and unsafe schemes', () {
    for (final candidate in [
      '', 'http://docs.example.org', 'https://localhost',
      'https://127.0.0.1', 'https://internal.lan',
      'https://node.svc', 'https://router.home', 'https://host.arpa',
      'https://host.localdomain', 'https://docs.example.org:8443',
      'https://user:secret@docs.example.org',
      'https://docs.example.org/private',
      'https://docs.example.org?token=private',
    ]) {
      expect(VerifiedLearningReader.validatedOrigin(candidate), isNull,
          reason: candidate);
    }
    expect(
      VerifiedLearningReader.validatedOrigin('https://docs.example.org:443')
          ?.host,
      'docs.example.org',
    );
  });

  test('parser accepts bounded first page with original Arabic content', () {
    final parsed = VerifiedLearningPage.parse(response(1, [track()], more: true), 1);
    expect(parsed.currentPage, 1);
    expect(parsed.hasMorePages, isTrue);
    expect(parsed.tracks.single.slug, 'database-recovery');
    expect(parsed.tracks.single.lessons.length, 2);
    expect(parsed.tracks.single.lessons.last.title, 'استعادة البيانات');
    expect(parsed.tracks.single.lessons.first.description,
        'Check documented backups');
  });

  test('parser rejects repeated slug even when track IDs differ', () {
    final duplicate = response(1, [
      track(id: idOne, slug: 'database-recovery'),
      track(id: idTwo, slug: 'database-recovery'),
    ]);
    expect(() => VerifiedLearningPage.parse(duplicate, 1),
        throwsFormatException);

    final valid = VerifiedLearningPage.parse(response(1, [
      track(id: idOne, slug: 'database-recovery'),
      track(id: idTwo, slug: 'verified-devops'),
    ]), 1);
    expect(valid.tracks.map((item) => item.slug).toList(),
        ['database-recovery', 'verified-devops']);
  });

  testWidgets('malformed first page shows generic bilingual error and retries',
      (tester) async {
    for (final arabic in [false, true]) {
      var attempts = 0;
      await tester.pumpWidget(MaterialApp(
        key: ValueKey(arabic),
        home: VerifiedLearningLibrary(
          arabic: arabic,
          pageLoader: (_) async {
            attempts++;
            return VerifiedLearningPage.parse(response(1, attempts == 1
                ? [
                    track(id: idOne, slug: 'duplicate'),
                    track(id: idTwo, slug: 'duplicate'),
                  ]
                : [track()]), 1);
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('learning-retry')), findsOneWidget);
      expect(find.textContaining('duplicate'), findsNothing);
      expect(find.text(arabic ? 'تعذر تحميل المسارات.' :
          'Could not load learning paths.'), findsOneWidget);
      await tester.tap(find.byKey(const Key('learning-retry')));
      await tester.pumpAndSettle();
      expect(attempts, 2);
      expect(find.byKey(const Key('learning-track-database-recovery')),
          findsOneWidget);
    }
  });

  test('parser fails closed on malformed, oversized and private records', () {
    final cases = <String>[
      '{}',
      jsonEncode({'data': [], 'meta': {'current_page': 2, 'has_more_pages': false}}),
      response(1, [], more: true),
      response(1, [track(), track()]),
      response(1, [track()..['progress'] = {'user_id': 'private'}]),
      response(1, [track()..['id'] = 'bad-id']),
      response(1, [track()..['title'] = '']),
      response(1, [track()..['outline'] = []]),
      response(1, [track()..['outline'] = [
        {'title': 'Safe', 'description': 'Fine', 'api_key': 'must-not-leak'},
      ]]),
      response(1, [track()..['outline'] = [
        {'title': 'Safe', 'description': 'x' * 1501},
      ]]),
      response(1, List.generate(21, (i) => track(
        id: '00000000-0000-4000-8000-${(i + 1).toString().padLeft(12, '0')}',
        slug: 'lesson-${i + 1}',
      ))),
      'x' * (2 * 1024 * 1024 + 1),
    ];
    for (final candidate in cases) {
      expect(
        () => VerifiedLearningPage.parse(candidate, 1),
        throwsFormatException,
      );
    }
    expect(
      () => VerifiedLearningPage.parse(
        response(10000, [track()], more: true), 10000,
      ),
      throwsFormatException,
    );
    expect(
      () => VerifiedLearningPage.parse(response(1, [track()]), 0),
      throwsFormatException,
    );
  });

  testWidgets('real published learning pages and lesson navigation', (tester) async {
    final calls = <int>[];
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: false,
      pageLoader: (page) async {
        calls.add(page);
        if (page == 1) {
          return VerifiedLearningPage.parse(
            response(1, [track()], more: true), 1,
          );
        }
        return VerifiedLearningPage.parse(response(2, [
          track(id: idTwo, slug: 'verified-devops', title: 'Verified DevOps path'),
        ]), 2);
      },
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-track-database-recovery')), findsOneWidget);
    expect(find.byKey(const Key('learning-more')), findsOneWidget);
    expect(calls, [1]);

    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('learning-track-verified-devops')), findsOneWidget);
    expect(find.byKey(const Key('learning-more')), findsNothing);
    await tester.tap(find.byKey(const Key('learning-track-verified-devops')));
    await tester.pumpAndSettle();
    expect(find.text('Published lessons'), findsOneWidget);
    expect(find.text('Backup verification'), findsOneWidget);
    expect(find.text('Check documented backups'), findsOneWidget);
    expect(find.text('استعادة البيانات'), findsOneWidget);
  });

  testWidgets('rebuild preserves terminal pagination and never reloads page two',
      (tester) async {
    final calls = <int>[];
    final loader = (int page) async {
      calls.add(page);
      return VerifiedLearningPage.parse(response(page, [
        track(id: page == 1 ? idOne : idTwo,
            slug: page == 1 ? 'database-recovery' : 'verified-devops'),
      ], more: page == 1), page);
    };
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: false, pageLoader: loader,
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-more')), findsOneWidget);
    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('learning-more')), findsNothing);
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: false, pageLoader: loader,
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-more')), findsNothing);
    expect(calls, [1, 2]);
  });

  testWidgets('Arabic content and genuinely empty published catalog', (tester) async {
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: true,
      pageLoader: (_) async => VerifiedLearningPage.parse(
        response(1, [track(title: 'مسار توثيق النسخ الاحتياطية')]), 1,
      ),
    )));
    await tester.pumpAndSettle();
    expect(find.text('مسارات التعلّم'), findsOneWidget);
    expect(find.text('مسار توثيق النسخ الاحتياطية'), findsOneWidget);
    await tester.tap(find.byKey(const Key('learning-track-database-recovery')));
    await tester.pumpAndSettle();
    expect(find.text('الدروس المنشورة'), findsOneWidget);
    expect(find.text('استعادة البيانات'), findsOneWidget);
    // Return to the original Navigator route before replacing the library.
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: true,
      pageLoader: (_) async => VerifiedLearningPage.parse(response(1, []), 1),
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-empty')), findsOneWidget);
    expect(find.text('لا توجد مسارات تعلّم منشورة حاليًا.'), findsOneWidget);
  });

  testWidgets('network failure is generic, retry recovers safely', (tester) async {
    var attempts = 0;
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: false,
      pageLoader: (_) async {
        attempts++;
        if (attempts == 1) throw const FormatException('PRIVATE_TOKEN_ERROR');
        return VerifiedLearningPage.parse(response(1, [track()]), 1);
      },
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-retry')), findsOneWidget);
    expect(find.textContaining('PRIVATE_TOKEN_ERROR'), findsNothing);
    await tester.tap(find.byKey(const Key('learning-retry')));
    await tester.pumpAndSettle();
    expect(attempts, 2);
    expect(find.byKey(const Key('learning-track-database-recovery')), findsOneWidget);
  });

  testWidgets('home has working learning link and no guessed production origin', (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    // Position the lazy ListTile well inside the 800x600 test viewport.
    // ensureVisible may leave the center outside the tappable region.
    await tester.scrollUntilVisible(
      find.byKey(const Key('verified-learning-entry')), 150,
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('verified-learning-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('verified-learning-entry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-unconfigured')), findsOneWidget);
    expect(find.textContaining('not connected to a trusted server'), findsOneWidget);
  });

  testWidgets('cross-page duplicate learning ID fails closed and retries same page',
      (tester) async {
    final calls = <int>[];
    var attempts = 0;
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: false,
      pageLoader: (page) async {
        calls.add(page);
        if (page == 1) {
          return VerifiedLearningPage.parse(
            response(1, [track()], more: true), 1,
          );
        }
        attempts++;
        if (attempts == 1) {
          // A replay from page one must never become a second card.
          return VerifiedLearningPage.parse(response(2, [track()]), 2);
        }
        return VerifiedLearningPage.parse(response(2, [
          track(id: idTwo, slug: 'new-path', title: 'New verified path'),
        ]), 2);
      },
    )));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('learning-more-error')), findsOneWidget);
    expect(find.byKey(const Key('learning-track-database-recovery')), findsOneWidget);
    expect(find.text('Verified database recovery'), findsOneWidget);
    expect(find.byKey(const Key('learning-track-new-path')), findsNothing);
    expect(find.textContaining('Repeated learning track'), findsNothing);
    expect(find.byKey(const Key('learning-more')), findsOneWidget);

    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2, 2], reason: 'Failure must not advance the page cursor');
    expect(find.byKey(const Key('learning-track-database-recovery')), findsOneWidget);
    expect(find.byKey(const Key('learning-track-new-path')), findsOneWidget);
    expect(find.byKey(const Key('learning-more-error')), findsNothing);
    expect(find.byKey(const Key('learning-more')), findsNothing);
  });

  testWidgets('fresh ID with a previously displayed slug is not appended',
      (tester) async {
    final calls = <int>[];
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: true,
      pageLoader: (page) async {
        calls.add(page);
        return VerifiedLearningPage.parse(
          response(page, [
            page == 1
                ? track(title: 'مسار موثق')
                : track(id: idTwo, title: 'مسار مكرر'),
          ], more: page == 1), page,
        );
      },
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2]);
    expect(find.byKey(const Key('learning-track-database-recovery')), findsOneWidget);
    expect(find.text('مسار موثق'), findsOneWidget);
    expect(find.text('مسار مكرر'), findsNothing);
    expect(find.byKey(const Key('learning-more-error')), findsOneWidget);
    expect(find.text('تعذر تحميل المزيد. حاول مرة أخرى.'), findsOneWidget);
    expect(find.byKey(const Key('learning-more')), findsOneWidget);
  });

  testWidgets('replays of an already appended page cannot advance pagination',
      (tester) async {
    const idThree = '00000000-0000-4000-8000-000000000003';
    final calls = <int>[];
    var pageThreeAttempts = 0;
    await tester.pumpWidget(MaterialApp(home: VerifiedLearningLibrary(
      arabic: false,
      pageLoader: (page) async {
        calls.add(page);
        if (page == 1) {
          return VerifiedLearningPage.parse(
            response(1, [track()], more: true), 1,
          );
        }
        if (page == 2) {
          return VerifiedLearningPage.parse(response(2, [
            track(id: idTwo, slug: 'second-path'),
          ], more: true), 2);
        }
        pageThreeAttempts++;
        return VerifiedLearningPage.parse(response(3, [
          pageThreeAttempts == 1
              ? track(id: idTwo, slug: 'replayed-second')
              : track(id: idThree, slug: 'third-path'),
        ]), 3);
      },
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('learning-track-second-path')), findsOneWidget);

    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2, 3]);
    expect(find.byKey(const Key('learning-more-error')), findsOneWidget);
    expect(find.byKey(const Key('learning-track-replayed-second')), findsNothing);
    expect(find.byKey(const Key('learning-track-second-path')), findsOneWidget);

    await tester.tap(find.byKey(const Key('learning-more')));
    await tester.pumpAndSettle();
    expect(calls, [1, 2, 3, 3]);
    expect(find.byKey(const Key('learning-track-third-path')), findsOneWidget);
    expect(find.byKey(const Key('learning-more-error')), findsNothing);
  });

}

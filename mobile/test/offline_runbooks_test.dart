import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbook_progress.dart';


class MemoryProgressStore implements RunbookProgressStore {
  final entries = <String, Set<int>>{};
  String key(String scope, String version, String id) =>
      [scope, version, id].join(':');

  @override
  Future<Set<int>> read({
    required String scope,
    required String packVersion,
    required String runbookId,
  }) async => Set<int>.of(entries[key(scope, packVersion, runbookId)] ?? {});

  @override
  Future<void> write({
    required String scope,
    required String packVersion,
    required String runbookId,
    required Set<int> completed,
  }) async {
    entries[key(scope, packVersion, runbookId)] = Set<int>.of(completed);
  }
}

void main() {
  test('offline bundle parser rejects malformed or falsely verified data', () {
    expect(() => OfflineRunbookPack.parse('{}'), throwsFormatException);
    expect(
      () => OfflineRunbookPack.parse('{"schema":"walidsite.offline-runbook-pack.v1","version":"0.1.0","source":"draft","items":[{"id":"unsafe","title_en":"Test","title_ar":"تجربة","steps_en":["a"],"steps_ar":[]}]}'),
      throwsFormatException,
    );
  });

  test('offline parser bounds oversized and malformed bundled content', () {
    final item = <String, dynamic>{
      'id': 'safe-checklist',
      'title_en': 'Safe checklist',
      'title_ar': 'قائمة آمنة',
      'steps_en': ['Verify a backup'],
      'steps_ar': ['تحقق من النسخة'],
    };
    final valid = <String, dynamic>{
      'schema': 'walidsite.offline-runbook-pack.v1',
      'version': '0.1.0',
      'source': 'Bundled educational content',
      'items': [item],
    };

    OfflineRunbookPack read(Map<String, dynamic> pack) =>
        OfflineRunbookPack.parse(jsonEncode(pack));

    expect(read(valid).items.single.id, 'safe-checklist');
    expect(() => read({...valid, 'version': '   '}), throwsFormatException);
    expect(
      () => read({...valid, 'version': List.filled(65, 'x').join()}),
      throwsFormatException,
    );
    expect(
      () => read({...valid, 'source': List.filled(257, 'x').join()}),
      throwsFormatException,
    );
    expect(
      () => read({
        ...valid,
        'items': List.generate(201, (i) => {...item, 'id': 'entry-$i'}),
      }),
      throwsFormatException,
    );
    expect(
      () => read({
        ...valid,
        'items': [
          {...item, 'title_ar': List.filled(251, 'x').join()},
        ],
      }),
      throwsFormatException,
    );
    expect(
      () => read({
        ...valid,
        'items': [
          {...item, 'steps_en': [List.filled(4001, 'x').join()]},
        ],
      }),
      throwsFormatException,
    );
    expect(
      () => read({
        ...valid,
        'items': [
          {
            ...item,
            'steps_en': List.filled(51, 'Step'),
            'steps_ar': List.filled(51, 'خطوة'),
          },
        ],
      }),
      throwsFormatException,
    );
    expect(
      () => read({...valid, 'source': List.filled(512 * 1024, 'x').join()}),
      throwsFormatException,
    );
  });

  test('offline parser rejects multibyte JSON beyond 512 KiB before decoding', () {
    const limit = 512 * 1024;
    // Dart String.length counts UTF-16 units, not serialized UTF-8 bytes.
    // The oversized, untrusted envelope must fail at the transport boundary
    // even though its code-unit count remains below the nominal limit.
    final raw = jsonEncode({
      'schema': 'walidsite.offline-runbook-pack.v1',
      'version': 'fixture',
      'source': 'educational',
      'items': [],
      'ignored_padding': 'é' * (limit ~/ 2),
    });
    expect(raw.length, lessThan(limit));
    expect(utf8.encode(raw).length, greaterThan(limit));
    expect(() => OfflineRunbookPack.parse(raw), throwsA(isA<FormatException>()
        .having((error) => error.message, 'message',
            'Offline runbook package is too large')));

    final bounded = jsonEncode({
      'schema': 'walidsite.offline-runbook-pack.v1',
      'version': 'fixture',
      'source': 'تعليمات موثقة',
      'items': [],
    });
    expect(OfflineRunbookPack.parse(bounded).version, 'fixture');
  });

  test('future verification timestamps remain visibly unverified', () {
    final pack = OfflineRunbookPack(
      version: '0.1.0',
      source: 'test bundle',
      lastSynced: null,
      verifiedAt: DateTime.now().toUtc().add(const Duration(days: 1)),
      items: const [],
    );

    expect(pack.isStale, isTrue);
  });

  testWidgets('offline pack opens with version and unverified warning', (tester) async {
    await tester.pumpWidget(const WaleedTechApp(initiallyStarted: true));
    await tester.scrollUntilVisible(
      find.byKey(const Key('offline-runbooks-entry')),
      120,
      scrollable: find.descendant(of: find.byKey(const Key('mobile-home-scroll-feed')), matching: find.byType(Scrollable)),
    );
    await tester.ensureVisible(find.byKey(const Key('offline-runbooks-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('offline-runbooks-entry')));
    await tester.pumpAndSettle();

    expect(find.text('Offline runbook library'), findsOneWidget);
    expect(find.text('Offline pack version: 0.1.0'), findsOneWidget);
    expect(find.text('Last sync: Never synced'), findsOneWidget);
    expect(find.byKey(const Key('runbook-stale-warning')), findsOneWidget);

    await tester.tap(find.byKey(const Key('runbook-safe-change-checklist')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('offline-runbook-detail')), findsOneWidget);
    expect(find.textContaining('Record the current state'), findsOneWidget);
    expect(find.text('Offline pack version: 0.1.0'), findsOneWidget);
  });

  testWidgets('offline search matches steps in the selected language only', (tester) async {
    const pack = OfflineRunbookPack(
      version: '0.1.0',
      source: 'Bundled educational draft',
      lastSynced: null,
      verifiedAt: null,
      items: [
        OfflineRunbook(
          id: 'recovery',
          titleEn: 'Recovery plan',
          titleAr: 'خطة التعافي',
          stepsEn: ['Perform a safe rollback'],
          stepsAr: ['استرجع النسخة بأمان'],
        ),
        OfflineRunbook(
          id: 'backup',
          titleEn: 'Backup checklist',
          titleAr: 'قائمة النسخ',
          stepsEn: ['Verify snapshots'],
          stepsAr: ['تحقق من اللقطات'],
        ),
      ],
    );

    await tester.pumpWidget(MaterialApp(
      home: OfflineRunbookLibrary(
        arabic: false,
        bundledPack: SynchronousFuture<OfflineRunbookPack>(pack),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('runbook-search')), 'rollback');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('runbook-recovery')), findsOneWidget);
    expect(find.byKey(const Key('runbook-backup')), findsNothing);

    await tester.pumpWidget(MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: OfflineRunbookLibrary(
          arabic: true,
          bundledPack: SynchronousFuture<OfflineRunbookPack>(pack),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('runbook-search')), 'استرجع');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('runbook-recovery')), findsOneWidget);
    expect(find.byKey(const Key('runbook-backup')), findsNothing);

    // The English-only word must not match Arabic-only guidance.
    await tester.enterText(find.byKey(const Key('runbook-search')), 'rollback');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-recovery')), findsNothing);
    expect(find.text('لا توجد أدلة مطابقة.'), findsOneWidget);
  });

  testWidgets('offline library normalizes Arabic terms across title and steps',
      (tester) async {
    const guide = OfflineRunbook(
      id: 'arabic-storage',
      titleEn: 'Storage operations',
      titleAr: 'إِدارةُ التَّخزين',
      stepsEn: ['Check a safe backup'],
      stepsAr: ['افحص نسخَ الاحتياط', 'راجع فحصَ الأداء'],
    );
    const pack = OfflineRunbookPack(
      version: '1.0.0', source: 'Local fixture',
      lastSynced: null, verifiedAt: null, items: [guide],
    );
    await tester.pumpWidget(MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: OfflineRunbookLibrary(
          arabic: true,
          bundledPack: SynchronousFuture<OfflineRunbookPack>(pack),
        ),
      ),
    ));
    await tester.pumpAndSettle();

    // No query is browse-all in the library (unlike the solution finder).
    expect(find.byKey(const Key('runbook-arabic-storage')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('runbook-search')), 'ادارة الاداء',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-arabic-storage')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('runbook-search')), 'storage',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-arabic-storage')), findsNothing);
    expect(find.text('لا توجد أدلة مطابقة.'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('runbook-search')), '   ');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-arabic-storage')), findsOneWidget);
  });

  testWidgets('offline runbook steps can be marked and unmarked with visible state', (tester) async {
    const item = OfflineRunbook(
      id: 'safe-progress',
      titleEn: 'Safe progress checklist',
      titleAr: 'قائمة التقدم الآمنة',
      stepsEn: ['Verify backup', 'Check recovery'],
      stepsAr: ['تحقق من النسخة', 'افحص التعافي'],
    );
    const pack = OfflineRunbookPack(
      version: '0.1.0',
      source: 'Bundled educational draft',
      lastSynced: null,
      verifiedAt: null,
      items: [item],
    );
    // Real path_provider I/O cannot run inside FakeAsync widget frames.
    // Inject a deterministic store; disk/restart semantics have a separate
    // real-async filesystem test below.
    await tester.pumpWidget(MaterialApp(
      home: OfflineRunbookDetail(
        arabic: true, item: item, pack: pack,
        progressStore: MemoryProgressStore(),
      ),
    ));
    final first = find.byKey(const Key('runbook-step-1-complete'));
    final second = find.byKey(const Key('runbook-step-2-complete'));
    expect(tester.widget<CheckboxListTile>(first).value, false);
    expect(tester.widget<CheckboxListTile>(second).value, false);

    // SelectableText consumes a tile-center tap, but the actual checkbox
    // is a separate accessible control. Exercise the real user target.
    final firstCheckbox = find.descendant(
      of: first,
      matching: find.byType(Checkbox),
    );
    await tester.tap(firstCheckbox);
    await tester.pump();
    expect(tester.widget<CheckboxListTile>(first).value, true);
    expect(tester.widget<CheckboxListTile>(second).value, false);

    await tester.tap(firstCheckbox);
    await tester.pump();
    expect(tester.widget<CheckboxListTile>(first).value, false);
    expect(find.text('تحقق من النسخة'), findsOneWidget);
  });

  testWidgets('Arabic runbooks use local RTL strings', (tester) async {
    // The English end-to-end test exercises the real bundled asset. For
    // locale-specific widget coverage, inject a deterministic immutable
    // snapshot so no AssetBundle I/O can stall a FakeAsync widget frame.
    const item = OfflineRunbook(
      id: 'safe-change-checklist',
      titleEn: 'Change safety checklist',
      titleAr: 'قائمة الأمان قبل التغيير',
      stepsEn: ['Record the current state'],
      stepsAr: ['سجّل الحالة الحالية'],
    );
    final pack = const OfflineRunbookPack(
      version: '0.1.0',
      source: 'Bundled educational draft',
      lastSynced: null,
      verifiedAt: null,
      items: const [item],
    );

    await tester.pumpWidget(MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: OfflineRunbookLibrary(arabic: true, bundledPack: SynchronousFuture<OfflineRunbookPack>(pack)),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('مكتبة الأدلة المحلية'), findsOneWidget);
    expect(find.text('الإصدار المحلي: 0.1.0'), findsOneWidget);
    expect(find.byKey(const Key('runbook-stale-warning')), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('مكتبة الأدلة المحلية'))), TextDirection.rtl);
    await tester.tap(find.byKey(const Key('runbook-safe-change-checklist')));
    await tester.pumpAndSettle();
    expect(find.textContaining('سجّل الحالة الحالية'), findsOneWidget);
  });

  testWidgets('durable progress survives restart and isolates guest, accounts, versions',
      (tester) async {
    await tester.runAsync(() async {
      // Real I/O is isolated from FakeAsync; every await has its own timeout
      // so a stalled plugin/filesystem cannot strand the entire CI job.
      Future<T> bounded<T>(Future<T> future, String stage) =>
          future.timeout(const Duration(seconds: 4),
              onTimeout: () => throw StateError('Progress I/O stalled at: $stage'));
      final directory = await bounded(
        Directory.systemTemp.createTemp('walidsite-progress-'), 'temp directory');
      try {
        AppSupportRunbookProgressStore store() =>
            AppSupportRunbookProgressStore(directory: () async => directory);

        await bounded(store().write(
          scope: 'account:alice', packVersion: '2.0.0',
          runbookId: 'safe-progress', completed: {0, 2},
        ), 'initial write');
        expect(await bounded(store().read(
          scope: 'account:alice', packVersion: '2.0.0',
          runbookId: 'safe-progress',
        ), 'restart read'), {0, 2});
        expect(await bounded(store().read(
          scope: 'account:bob', packVersion: '2.0.0',
          runbookId: 'safe-progress',
        ), 'other account'), isEmpty);
        expect(await bounded(store().read(
          scope: 'guest', packVersion: '2.0.0',
          runbookId: 'safe-progress',
        ), 'guest scope'), isEmpty);
        expect(await bounded(store().read(
          scope: 'account:alice', packVersion: '2.1.0',
          runbookId: 'safe-progress',
        ), 'new version'), isEmpty);
        await bounded(store().write(
          scope: 'account:alice', packVersion: '2.0.0',
          runbookId: 'safe-progress', completed: {2},
        ), 'updated write');
        expect(await bounded(store().read(
          scope: 'account:alice', packVersion: '2.0.0',
          runbookId: 'safe-progress',
        ), 'updated read'), {2});
        await expectLater(bounded(store().write(
          scope: 'account:alice@example.org', packVersion: '2.0.0',
          runbookId: 'safe-progress', completed: {1},
        ), 'invalid scope'), throwsFormatException);
      } finally {
        await bounded(directory.delete(recursive: true), 'cleanup');
      }
    });
  });

  testWidgets('marked step survives widget recreation with isolated scope', (tester) async {
    const item = OfflineRunbook(
      id: 'safe-progress', titleEn: 'Safe checklist', titleAr: 'قائمة آمنة',
      stepsEn: ['Backup', 'Rollback'], stepsAr: ['نسخة', 'استعادة'],
    );
    const pack = OfflineRunbookPack(
      version: '2.0.0', source: 'Signed fixture',
      lastSynced: null, verifiedAt: null, items: [item],
    );
    final store = MemoryProgressStore();
    Widget screen(String scope) => MaterialApp(
      home: OfflineRunbookDetail(
        item: item, pack: pack, arabic: false,
        progressStore: store, progressScope: scope,
      ),
    );
    final first = find.byKey(const Key('runbook-step-1-complete'));
    await tester.pumpWidget(screen('account:alice'));
    await tester.pumpAndSettle();
    await tester.tap(find.descendant(of: first, matching: find.byType(Checkbox)));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(first).value, true);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpWidget(screen('account:alice'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(first).value, true);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpWidget(screen('account:bob'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(first).value, false);
  });

  testWidgets('signed publisher source date is distinct from sync in EN and AR',
      (tester) async {
    final pack = OfflineRunbookPack(
      version: '2.0.0', source: 'Publisher',
      lastSynced: DateTime.utc(2026, 2, 2),
      verifiedAt: DateTime.utc(2026, 1, 1),
      signedVerified: true,
      items: const [OfflineRunbook(
        id: 'dated', titleEn: 'Dated', titleAr: 'مؤرخ',
        stepsEn: ['Safe'], stepsAr: ['آمن'],
      )],
    );
    await tester.pumpWidget(MaterialApp(
      home: OfflineRunbookDetail(
        item: pack.items.single, pack: pack, arabic: false,
        progressStore: MemoryProgressStore(),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.textContaining('Last sync: 2026-02-02'), findsOneWidget);
    expect(find.textContaining('Signed source verification date: 2026-01-01'),
        findsOneWidget);
    await tester.pumpWidget(MaterialApp(
      home: OfflineRunbookDetail(
        item: pack.items.single, pack: pack, arabic: true,
        progressStore: MemoryProgressStore(),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.textContaining('آخر مزامنة: 2026-02-02'), findsOneWidget);
    expect(find.textContaining('تاريخ تحقق المصدر الموقّع: 2026-01-01'),
        findsOneWidget);
  });


}

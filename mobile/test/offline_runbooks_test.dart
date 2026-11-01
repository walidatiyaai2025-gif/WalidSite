import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';

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
    await tester.pumpWidget(const WaleedTechApp());
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
}

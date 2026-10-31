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
        child: OfflineRunbookLibrary(arabic: true, bundledPack: Future.value(pack)),
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

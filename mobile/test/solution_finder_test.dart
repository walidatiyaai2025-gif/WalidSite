import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/solutions/offline_solution_finder.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';

void main() {
  const runbook = OfflineRunbook(
    id: 'network-restore',
    titleEn: 'Network recovery checklist',
    titleAr: 'قائمة استعادة الشبكة',
    stepsEn: ['Record current state', 'Review connection latency'],
    stepsAr: ['سجّل الحالة الحالية', 'راجع زمن تأخير الاتصال'],
  );
  const another = OfflineRunbook(
    id: 'database-backup',
    titleEn: 'Database backup checklist',
    titleAr: 'قائمة النسخ الاحتياطي',
    stepsEn: ['Verify backup access'],
    stepsAr: ['تحقق من الوصول للنسخة'],
  );
  const pack = OfflineRunbookPack(
    version: '0.1.0',
    source: 'Bundled educational draft',
    lastSynced: null,
    verifiedAt: null,
    items: const [runbook, another],
  );

  test('offline finder matches source steps in both languages without inference', () {
    expect(OfflineSolutionSearch.find(pack, ' LATENCY ', arabic: false), [runbook]);
    expect(OfflineSolutionSearch.find(pack, 'استعادة', arabic: true), [runbook]);
    expect(OfflineSolutionSearch.find(pack, 'backup', arabic: false), [another]);
    expect(OfflineSolutionSearch.find(pack, 'anything unlisted', arabic: false), isEmpty);
    expect(OfflineSolutionSearch.find(pack, ' ', arabic: false), isEmpty);

    // The search must not reveal content that the active locale does not show.
    expect(OfflineSolutionSearch.find(pack, 'latency', arabic: true), isEmpty);
    expect(OfflineSolutionSearch.find(pack, 'استعادة', arabic: false), isEmpty);
    expect(OfflineSolutionSearch.find(pack, 'راجع', arabic: true), [runbook]);
  });

  testWidgets('finder opens real local steps and exposes stale pack provenance',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: OfflineSolutionFinder(
        arabic: false,
        bundledPack: SynchronousFuture<OfflineRunbookPack>(pack),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Offline solution finder'), findsOneWidget);
    expect(find.text('Pack version: 0.1.0'), findsOneWidget);
    expect(find.text('Source: Bundled educational draft'), findsOneWidget);
    expect(find.byKey(const Key('solution-stale-warning')), findsOneWidget);
    expect(find.byKey(const Key('solution-search-hint')), findsOneWidget);
    expect(find.byKey(const Key('solution-network-restore')), findsNothing);

    await tester.enterText(
      find.byKey(const Key('solution-search-input')),
      'latency',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('solution-network-restore')), findsOneWidget);
    expect(find.byKey(const Key('solution-database-backup')), findsNothing);

    await tester.ensureVisible(find.byKey(const Key('solution-network-restore')));
    await tester.tap(find.byKey(const Key('solution-network-restore')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('offline-runbook-detail')), findsOneWidget);
    expect(find.text('Review connection latency'), findsOneWidget);
    expect(find.byKey(const Key('runbook-stale-warning')), findsOneWidget);
  });

  testWidgets('Arabic search keeps RTL and clearly signals no result',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: OfflineSolutionFinder(
          arabic: true,
          bundledPack: SynchronousFuture<OfflineRunbookPack>(pack),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('البحث عن حلول محلية'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('البحث عن حلول محلية'))),
      TextDirection.rtl,
    );
    await tester.enterText(
      find.byKey(const Key('solution-search-input')),
      'لا توجد كلمة',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('solution-search-empty')), findsOneWidget);
    expect(find.byKey(const Key('solution-network-restore')), findsNothing);

    await tester.enterText(
      find.byKey(const Key('solution-search-input')),
      'latency',
    );
    await tester.pumpAndSettle();
    // English-only hidden steps cannot produce Arabic result cards.
    expect(find.byKey(const Key('solution-search-empty')), findsOneWidget);

    await tester.enterText(
      find.byKey(const Key('solution-search-input')),
      'تأخير',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('solution-network-restore')), findsOneWidget);
    expect(find.byKey(const Key('solution-search-empty')), findsNothing);
  });
}

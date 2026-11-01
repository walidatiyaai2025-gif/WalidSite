import 'dart:convert';

import 'package:cryptography/cryptography.dart';
import 'package:waleed_tech_companion/runbooks/signed_runbook_sync.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/solutions/offline_solution_finder.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';

class FinderMemorySnapshotStore implements RunbookSnapshotStore {
  String? snapshot;
  int writes = 0;

  @override
  Future<String?> read() async => snapshot;

  @override
  Future<void> write(String envelope) async {
    snapshot = envelope;
    writes++;
  }
}

Future<({String envelope, String publicKey})> signedFinderFixture() async {
  // Ephemeral test signing identity. Never shipped in the mobile app.
  final pair = await Ed25519().newKeyPair();
  final key = base64Encode((await pair.extractPublicKey()).bytes);
  final at = DateTime.now().toUtc().subtract(const Duration(minutes: 2));
  final payload = utf8.encode(jsonEncode({
    'schema': 'walidsite.offline-runbook-pack.v1',
    'version': '2.0.0',
    'source': 'Verified training publisher',
    'last_synced_at': at.toIso8601String(),
    'verified_at': at.toIso8601String(),
    'items': [
      {
        'id': 'new-approved',
        'title_en': 'Signed safe rollback',
        'title_ar': 'خطوات العودة الموقعة',
        'steps_en': ['Test safe rollback'],
        'steps_ar': ['اختبر العودة بأمان'],
      }
    ],
  }));
  final signature = await Ed25519().sign(payload, keyPair: pair);
  return (
    envelope: jsonEncode({
      'schema': 'walidsite.signed-runbook-pack.v1',
      'payload_base64': base64Encode(payload),
      'signature_base64': base64Encode(signature.bytes),
    }),
    publicKey: key,
  );
}

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

  test('Arabic marks, Alef and Ya variants match visible signed or bundled text', () {
    const guide = OfflineRunbook(
      id: 'arabic-database',
      titleEn: 'Database administration guide',
      titleAr: 'إِدارةُ قواعدِ البيانات',
      stepsEn: ['Inspect network latency', 'Restore safe snapshots'],
      stepsAr: ['افحص الاتصال بالشّبكة', 'راجع خُطَى الاسترجاع'],
    );
    const arabicPack = OfflineRunbookPack(
      version: '1.0', source: 'Test reviewed guidance',
      lastSynced: null, verifiedAt: null, items: [guide],
    );

    // An Arabic keyboard without diacritics must find the real title,
    // including Alef variants, and combine keywords from separate fields.
    expect(OfflineSolutionSearch.find(
      arabicPack, 'ادارة قواعد', arabic: true,
    ), [guide]);
    expect(OfflineSolutionSearch.find(
      arabicPack, 'البيانات الشبكة', arabic: true,
    ), [guide]);
    expect(OfflineSolutionSearch.find(
      arabicPack, 'الاسترجاع ادارة', arabic: true,
    ), [guide]);
    expect(OfflineSolutionSearch.find(
      arabicPack, 'database latency', arabic: false,
    ), [guide]);

    // An English-only translation never leaks into Arabic results.
    expect(OfflineSolutionSearch.find(
      arabicPack, 'database', arabic: true,
    ), isEmpty);
    expect(OfflineSolutionSearch.find(
      arabicPack, 'الشبكة', arabic: false,
    ), isEmpty);
    expect(OfflineSolutionSearch.find(
      arabicPack, 'ادارة missing', arabic: true,
    ), isEmpty);
    expect(OfflineSolutionSearch.find(
      arabicPack, 'ـ   ّ', arabic: true,
    ), isEmpty);
  });

  test('offline solution finder retains the 20-result limit for broad keywords', () {
    final many = OfflineRunbookPack(
      version: '1.0', source: 'Local fixture',
      lastSynced: null, verifiedAt: null,
      items: List.generate(25, (index) => OfflineRunbook(
        id: 'guide-$index',
        titleEn: 'Recovery guide $index',
        titleAr: 'دليلُ التّعافي $index',
        stepsEn: const ['Check backup'],
        stepsAr: const ['تحقق من النسخة'],
      )),
    );
    final result = OfflineSolutionSearch.find(
      many, 'دليل التعافي', arabic: true,
    );
    expect(result.length, 20);
    expect(result.first.id, 'guide-0');
    expect(result.last.id, 'guide-19');
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
  testWidgets('finder reads reverified saved signed guidance after runbook sync without network',
      (tester) async {
    final fixture = await signedFinderFixture();
    final store = FinderMemorySnapshotStore();
    var fetches = 0;
    final source = SignedRunbookSource(
      url: 'https://docs.example.org/signed/pack.json',
      publicKeyBase64: fixture.publicKey,
      transport: (_) async {
        fetches++;
        return fixture.envelope;
      },
    );
    final writer = OfflineRunbookRepository(
      source: source,
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(pack),
    );
    // A user explicitly synced through the runbook library in an earlier
    // navigation session. The finder must use that persisted signed snapshot.
    final synced = await writer.sync();
    expect(synced.signedVerified, isTrue);
    expect(store.writes, 1);
    expect(fetches, 1);

    final reopened = OfflineRunbookRepository(
      source: source,
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(pack),
    );
    await tester.pumpWidget(MaterialApp(
      home: OfflineSolutionFinder(
        arabic: false,
        repository: reopened,
      ),
    ));
    await tester.pumpAndSettle();

    expect(fetches, 1, reason: 'Opening/searching must never fetch the internet');
    expect(find.text('Pack version: 2.0.0'), findsOneWidget);
    expect(find.text('Verified signed offline snapshot'), findsOneWidget);
    expect(find.byKey(const Key('solution-stale-warning')), findsNothing);
    expect(find.textContaining('Bundled educational draft'), findsNothing);

    await tester.enterText(
      find.byKey(const Key('solution-search-input')), 'rollback',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('solution-new-approved')), findsOneWidget);
    expect(find.byKey(const Key('solution-network-restore')), findsNothing);
    expect(fetches, 1, reason: 'Entered issue keywords are never uploaded');

    await tester.ensureVisible(find.byKey(const Key('solution-new-approved')));
    await tester.tap(find.byKey(const Key('solution-new-approved')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('offline-runbook-detail')), findsOneWidget);
    expect(find.text('Test safe rollback'), findsOneWidget);
  });

  testWidgets('tampered cached snapshot falls back to bundled guidance and warns in Arabic',
      (tester) async {
    final fixture = await signedFinderFixture();
    final decoded = jsonDecode(fixture.envelope) as Map<String, dynamic>;
    final payload = utf8.decode(base64Decode(decoded['payload_base64'] as String));
    decoded['payload_base64'] = base64Encode(utf8.encode(
      payload.replaceFirst('Signed safe rollback', 'Forged unsafe instructions'),
    ));
    final store = FinderMemorySnapshotStore()..snapshot = jsonEncode(decoded);
    var fetches = 0;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/signed/pack.json',
        publicKeyBase64: fixture.publicKey,
        transport: (_) async {
          fetches++;
          return fixture.envelope;
        },
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(pack),
    );

    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: OfflineSolutionFinder(arabic: true, repository: repo),
      ),
    ));
    await tester.pumpAndSettle();
    expect(fetches, 0, reason: 'Tampered cache never triggers a remote fetch');
    expect(store.writes, 0, reason: 'The corrupted stored snapshot stays untouched');
    expect(find.text('إصدار الحزمة: 0.1.0'), findsOneWidget);
    expect(find.text('حزمة تعليمية مرفقة غير معتمدة'), findsOneWidget);
    expect(find.byKey(const Key('solution-stale-warning')), findsOneWidget);
    expect(find.textContaining('Forged unsafe instructions'), findsNothing);

    await tester.enterText(
      find.byKey(const Key('solution-search-input')), 'تأخير',
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('solution-network-restore')), findsOneWidget);
    expect(find.byKey(const Key('solution-new-approved')), findsNothing);
    expect(fetches, 0);
  });

  testWidgets('unconfigured signatures keep offline search available with no network',
      (tester) async {
    var networkCalls = 0;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'http://localhost/unsigned.json',
        publicKeyBase64: '',
        transport: (_) async {
          networkCalls++;
          return '{}';
        },
      ),
      store: FinderMemorySnapshotStore(),
      bundled: () => SynchronousFuture<OfflineRunbookPack>(pack),
    );
    await tester.pumpWidget(MaterialApp(
      home: OfflineSolutionFinder(arabic: false, repository: repo),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Bundled educational copy — unverified'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('solution-search-input')), 'latency');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('solution-network-restore')), findsOneWidget);
    expect(networkCalls, 0);
  });

}

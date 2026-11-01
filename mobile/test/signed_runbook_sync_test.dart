import 'dart:convert';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';
import 'package:waleed_tech_companion/runbooks/signed_runbook_sync.dart';

class MemorySnapshotStore implements RunbookSnapshotStore {
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

Future<String> signedEnvelope({
  required SimpleKeyPair key,
  DateTime? verifiedAt,
  String version = '2.0.0',
}) async {
  final at = verifiedAt ?? DateTime.now().toUtc().subtract(const Duration(minutes: 2));
  final data = utf8.encode(jsonEncode({
    'schema': 'walidsite.offline-runbook-pack.v1',
    'version': version,
    'source': 'Reviewed educational runbook publisher',
    'last_synced_at': at.toIso8601String(),
    'verified_at': at.toIso8601String(),
    'items': [
      {
        'id': 'safe-checklist',
        'title_en': 'Verified change checklist',
        'title_ar': 'قائمة تغيير معتمدة',
        'steps_en': ['Check verified backup', 'Test safe rollback'],
        'steps_ar': ['تحقق من النسخة', 'اختبر الرجوع بأمان'],
      },
    ],
  }));
  final signature = await Ed25519().sign(data, keyPair: key);
  return jsonEncode({
    'schema': 'walidsite.signed-runbook-pack.v1',
    'payload_base64': base64Encode(data),
    'signature_base64': base64Encode(signature.bytes),
  });
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<({SimpleKeyPair pair, String key})> signer() async {
    final pair = await Ed25519().newKeyPair();
    final key = base64Encode((await pair.extractPublicKey()).bytes);
    return (pair: pair, key: key);
  }

  const fallback = OfflineRunbookPack(
    version: '0.1.0',
    source: 'Bundled educational draft',
    lastSynced: null,
    verifiedAt: null,
    items: [
      OfflineRunbook(
        id: 'offline',
        titleEn: 'Bundled backup',
        titleAr: 'نسخة احتياطية مرفقة',
        stepsEn: ['Stay offline'],
        stepsAr: ['اعمل بدون إنترنت'],
      ),
    ],
  );

  test('unconfigured endpoint never invokes network and loads fallback', () async {
    var requests = 0;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'http://localhost/unsafe.json',
        publicKeyBase64: '',
        transport: (uri) async {
          requests++;
          return '{}';
        },
      ),
      store: MemorySnapshotStore(),
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    expect(repo.source.configured, isFalse);
    expect((await repo.load()).version, '0.1.0');
    await expectLater(repo.sync(), throwsFormatException);
    expect(requests, 0);
  });

  test('verified Ed25519 envelope persists and re-verifies after restart', () async {
    final keys = await signer();
    final body = await signedEnvelope(key: keys.pair);
    final store = MemorySnapshotStore();
    var requests = 0;
    SignedRunbookSource source() => SignedRunbookSource(
      url: 'https://docs.example.org/packs/signed.json',
      publicKeyBase64: keys.key,
      transport: (uri) async {
        requests++;
        expect(uri.scheme, 'https');
        return body;
      },
    );
    final first = OfflineRunbookRepository(
      source: source(),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    final before = await first.load();
    expect(before.version, '0.1.0');
    expect(before.signedVerified, isFalse);
    final synced = await first.sync();
    expect(synced.version, '2.0.0');
    expect(synced.signedVerified, isTrue);
    expect(synced.items.single.steps(true).first, 'تحقق من النسخة');
    expect(store.writes, 1);
    expect(requests, 1);

    final restarted = OfflineRunbookRepository(
      source: source(),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    final offlineCopy = await restarted.load();
    expect(offlineCopy.version, '2.0.0');
    expect(offlineCopy.signedVerified, isTrue);
    expect(requests, 1, reason: 'Offline cold start must not fetch network');
  });

  test('tampering with stored signed pack falls back without trusting it', () async {
    final keys = await signer();
    final valid = await signedEnvelope(key: keys.pair);
    final store = MemorySnapshotStore()..snapshot = valid;
    final source = SignedRunbookSource(
      url: 'https://docs.example.org/pack.json',
      publicKeyBase64: keys.key,
      transport: (_) async => valid,
    );
    final repo = OfflineRunbookRepository(
      source: source, store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    expect((await repo.load()).version, '2.0.0');

    final decoded = jsonDecode(valid) as Map<String, dynamic>;
    final payload = utf8.decode(base64Decode(decoded['payload_base64'] as String));
    decoded['payload_base64'] = base64Encode(utf8.encode(
      payload.replaceAll('Verified change checklist', 'UNVERIFIED tamper'),
    ));
    store.snapshot = jsonEncode(decoded);
    expect((await repo.load()).version, '0.1.0');
    await expectLater(repo.sync(), completes);
    expect(store.writes, 1);
  });

  test('bad signatures and wrong publisher key never overwrite last good pack', () async {
    final approved = await signer();
    final attacker = await signer();
    final good = await signedEnvelope(key: approved.pair);
    final bad = await signedEnvelope(key: attacker.pair);
    final store = MemorySnapshotStore()..snapshot = good;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/pack.json',
        publicKeyBase64: approved.key,
        transport: (_) async => bad,
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    await expectLater(repo.sync(), throwsFormatException);
    expect((await repo.load()).version, '2.0.0');
    expect(store.snapshot, good);
    expect(store.writes, 0);
  });

  test('replayed signed old snapshot is rejected without replacing cache', () async {
    final keys = await signer();
    final old = await signedEnvelope(
      key: keys.pair,
      verifiedAt: DateTime.now().toUtc().subtract(const Duration(days: 4)),
      version: '1.0.0',
    );
    final recent = await signedEnvelope(
      key: keys.pair,
      verifiedAt: DateTime.now().toUtc().subtract(const Duration(hours: 1)),
    );
    final store = MemorySnapshotStore()..snapshot = recent;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/pack.json',
        publicKeyBase64: keys.key,
        transport: (_) async => old,
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    await expectLater(repo.sync(), throwsFormatException);
    expect(store.snapshot, recent);
    expect(store.writes, 0);
  });

  test('unsafe endpoints and oversized signed envelope fail closed', () async {
    final keys = await signer();
    for (final url in [
      'http://docs.example.org/pack.json',
      'https://localhost/pack.json',
      'https://127.0.0.1/pack.json',
      'https://user:pass@docs.example.org/pack.json',
      'https://docs.example.org/pack.json?token=not-allowed',
      'https://docs.example.org/pack.json#fragment',
    ]) {
      expect(SignedRunbookSource(url: url, publicKeyBase64: keys.key).configured, isFalse);
    }
    final source = SignedRunbookSource(
      url: 'https://docs.example.org/pack.json',
      publicKeyBase64: keys.key,
      transport: (_) async => 'x' * (810 * 1024),
    );
    await expectLater(source.fetch(), throwsFormatException);
  });

  testWidgets('bilingual manual sync replaces bundled guidance only after verification', (tester) async {
    final keys = await signer();
    final envelope = await signedEnvelope(key: keys.pair);
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/pack.json',
        publicKeyBase64: keys.key,
        transport: (_) async => envelope,
      ),
      store: MemorySnapshotStore(),
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );

    await tester.pumpWidget(MaterialApp(
      home: OfflineRunbookLibrary(arabic: true, repository: repo),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-offline')), findsOneWidget);
    expect(find.textContaining('حزمة تعليمية مرفقة'), findsOneWidget);
    await tester.tap(find.byKey(const Key('runbook-sync')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-safe-checklist')), findsOneWidget);
    expect(find.textContaining('نسخة موقّعة تم التحقق منها'), findsOneWidget);
    expect(find.textContaining('حزمة تعليمية مرفقة'), findsNothing);
    expect(find.text('قائمة تغيير معتمدة'), findsOneWidget);
    expect(find.byKey(const Key('runbook-offline')), findsNothing);
  });

  testWidgets('failed signed update keeps previously displayed offline content', (tester) async {
    final keys = await signer();
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/pack.json',
        publicKeyBase64: keys.key,
        transport: (_) async => 'corrupt',
      ),
      store: MemorySnapshotStore(),
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    await tester.pumpWidget(MaterialApp(
      home: OfflineRunbookLibrary(arabic: false, repository: repo),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-offline')), findsOneWidget);
    await tester.tap(find.byKey(const Key('runbook-sync')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('runbook-sync-error')), findsOneWidget);
    expect(find.byKey(const Key('runbook-offline')), findsOneWidget);
    expect(find.textContaining('Previous offline content'), findsOneWidget);
  });
}

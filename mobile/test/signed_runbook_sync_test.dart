import 'dart:async';
import 'dart:convert';
import 'dart:io';

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

/// Simulates a slow atomic storage write and catches overlapping writes.
class GatedSnapshotStore extends MemorySnapshotStore {
  final firstStarted = Completer<void>();
  final releaseFirst = Completer<void>();
  int activeWrites = 0;
  int maxActiveWrites = 0;

  @override
  Future<void> write(String envelope) async {
    activeWrites++;
    if (activeWrites > maxActiveWrites) maxActiveWrites = activeWrites;
    try {
      if (writes == 0 && !firstStarted.isCompleted) {
        firstStarted.complete();
        await releaseFirst.future;
      }
      await super.write(envelope);
    } finally {
      activeWrites--;
    }
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

  test('app-support global signed cache rejects symlink and oversized writes',
      () async {
    final root = await Directory.systemTemp.createTemp('walidsite-signed-');
    final store = AppSupportRunbookStore(supportDirectory: root);
    final snapshot = File('${root.path}/signed-runbook-pack-v1.json');
    final temporary = File('${snapshot.path}.part');
    final outside = File('${root.path}/protected-test-data');
    try {
      await outside.writeAsString('keep original');
      await store.write('signed-v1');
      expect(await store.read(), 'signed-v1');

      await expectLater(store.write('x' * (800 * 1024 + 1)),
          throwsFormatException);
      expect(await store.read(), 'signed-v1');

      await temporary.createSymbolicLink(outside.path);
      await expectLater(store.write('signed-v2'), throwsFormatException);
      expect(await outside.readAsString(), 'keep original');
      expect(await store.read(), 'signed-v1');
      expect(await FileSystemEntity.type(temporary.path, followLinks: false),
          FileSystemEntityType.link);
      await temporary.delete();

      await snapshot.delete();
      await snapshot.createSymbolicLink(outside.path);
      await expectLater(store.read(), throwsFormatException);
      await expectLater(store.write('signed-v3'), throwsFormatException);
      expect(await outside.readAsString(), 'keep original');
    } finally {
      await root.delete(recursive: true);
    }
  });

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

  test('equal-UTC-timestamp conflicting signed packs cannot roll back trusted guidance',
      () async {
    final keys = await signer();
    final at = DateTime.now().toUtc().subtract(const Duration(minutes: 10));
    final trusted = await signedEnvelope(
      key: keys.pair, verifiedAt: at, version: '2.0.0',
    );
    // Both packets are cryptographically valid under the *same* trusted
    // publisher key. Signing does not establish which is chronologically newer.
    final conflicting = await signedEnvelope(
      key: keys.pair, verifiedAt: at, version: '1.0.0',
    );
    final store = MemorySnapshotStore()..snapshot = trusted;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/packs/verified.json',
        publicKeyBase64: keys.key,
        transport: (_) async => conflicting,
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    expect((await repo.load()).version, '2.0.0');
    await expectLater(repo.source.verify(conflicting), completes,
        reason: 'The attack is a valid publisher-signed replay, not bad crypto');
    await expectLater(repo.sync(), throwsFormatException);
    expect(store.snapshot, trusted);
    expect(store.writes, 0);
    expect((await repo.load()).version, '2.0.0');
  });

  test('identical timestamp and signed bytes are idempotent; strictly newer'
      ' signed pack still advances once', () async {
    final keys = await signer();
    final time = DateTime.now().toUtc().subtract(const Duration(minutes: 10));
    final trusted = await signedEnvelope(
      key: keys.pair, verifiedAt: time, version: '2.0.0',
    );
    final newer = await signedEnvelope(
      key: keys.pair,
      verifiedAt: time.add(const Duration(minutes: 2)),
      version: '2.1.0',
    );
    var currentDownload = trusted;
    final store = MemorySnapshotStore()..snapshot = trusted;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/packs/verified.json',
        publicKeyBase64: keys.key,
        transport: (_) async => currentDownload,
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );

    final repeated = await repo.sync();
    expect(repeated.signedVerified, isTrue);
    expect(repeated.version, '2.0.0');
    expect(store.writes, 0,
        reason: 'Identical verification receipt must not rewrite the cache');
    expect(store.snapshot, trusted);

    currentDownload = newer;
    final updated = await repo.sync();
    expect(updated.signedVerified, isTrue);
    expect(updated.version, '2.1.0');
    expect(store.snapshot, newer);
    expect(store.writes, 1);
    expect((await repo.load()).version, '2.1.0');

    await repo.sync();
    expect(store.writes, 1,
        reason: 'A second fetch of the same newer envelope is a no-op');
  });

  test('concurrent signed updates serialize writes: older cannot overtake newer',
      () async {
    final keys = await signer();
    final time = DateTime.now().toUtc().subtract(const Duration(hours: 2));
    final older = await signedEnvelope(
      key: keys.pair, verifiedAt: time, version: '1.0.0',
    );
    final newer = await signedEnvelope(
      key: keys.pair,
      verifiedAt: time.add(const Duration(hours: 1)),
      version: '2.0.0',
    );
    final store = GatedSnapshotStore();
    var fetches = 0;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/packs/signed.json',
        publicKeyBase64: keys.key,
        transport: (_) async {
          fetches++;
          return fetches == 1 ? older : newer;
        },
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );

    final first = repo.sync();
    await store.firstStarted.future;
    final second = repo.sync();
    await Future<void>.delayed(Duration.zero);
    expect(fetches, 1,
        reason: 'Next request must not read an obsolete cache while a write is pending');
    expect(store.writes, 0);
    expect(store.activeWrites, 1);

    store.releaseFirst.complete();
    final results = await Future.wait([first, second]);
    expect(results.map((pack) => pack.version), ['1.0.0', '2.0.0']);
    expect(results.every((pack) => pack.signedVerified), isTrue);
    expect(fetches, 2);
    expect(store.maxActiveWrites, 1);
    expect(store.activeWrites, 0);
    expect(store.writes, 2);
    expect(store.snapshot, newer);
    expect((await repo.load()).version, '2.0.0');
  });

  test('separate repository instances cannot overwrite a newer signed snapshot',
      () async {
    final keys = await signer();
    final time = DateTime.now().toUtc().subtract(const Duration(hours: 3));
    final old = await signedEnvelope(
      key: keys.pair, verifiedAt: time, version: '1.0.0',
    );
    final recent = await signedEnvelope(
      key: keys.pair,
      verifiedAt: time.add(const Duration(hours: 1)),
      version: '2.0.0',
    );
    final store = GatedSnapshotStore();
    var recentDownloads = 0;
    var oldDownloads = 0;

    OfflineRunbookRepository makeRepo(String envelope, void Function() onFetch) =>
        OfflineRunbookRepository(
          source: SignedRunbookSource(
            url: 'https://docs.example.org/packs/signed.json',
            publicKeyBase64: keys.key,
            transport: (_) async {
              onFetch();
              return envelope;
            },
          ),
          store: store,
          bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
        );
    final recentRepo = makeRepo(recent, () => recentDownloads++);
    final oldRepo = makeRepo(old, () => oldDownloads++);

    final first = recentRepo.sync();
    await store.firstStarted.future;
    final stale = oldRepo.sync();
    await Future<void>.delayed(Duration.zero);
    expect(recentDownloads, 1);
    expect(oldDownloads, 0,
        reason: 'Another repository must not fetch while a signed write is pending');
    expect(store.activeWrites, 1);

    store.releaseFirst.complete();
    expect((await first).version, '2.0.0');
    await expectLater(stale, throwsFormatException);
    expect(oldDownloads, 1);
    expect(store.maxActiveWrites, 1);
    expect(store.writes, 1);
    expect(store.snapshot, recent,
        reason: 'Older publisher-signed bytes cannot replace the newer verified snapshot');
    expect((await oldRepo.load()).version, '2.0.0');
  });

  test('failed first signed sync releases the queue for verified next update',
      () async {
    final keys = await signer();
    final trusted = await signedEnvelope(key: keys.pair);
    var requests = 0;
    final store = MemorySnapshotStore();
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://docs.example.org/packs/signed.json',
        publicKeyBase64: keys.key,
        transport: (_) async {
          requests++;
          return requests == 1 ? 'PRIVATE_INVALID_SIGNATURE' : trusted;
        },
      ),
      store: store,
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    final first = repo.sync();
    final second = repo.sync();
    await expectLater(first, throwsFormatException);
    final recovered = await second;
    expect(recovered.version, '2.0.0');
    expect(recovered.signedVerified, isTrue);
    expect(store.snapshot, trusted);
    expect(store.writes, 1);
    expect(requests, 2);
  });

  test('unsafe endpoints and oversized signed envelope fail closed', () async {
    final keys = await signer();
    var transportCalls = 0;
    for (final url in [
      'http://docs.example.org/pack.json',
      'https://localhost/pack.json',
      'https://127.0.0.1/pack.json',
      'https://[::1]/pack.json',
      'https://user:pass@docs.example.org/pack.json',
      'https://docs.example.org/pack.json?token=not-allowed',
      'https://docs.example.org/pack.json#fragment',
      'https://docs.example.org:8443/pack.json',
      'https://router.lan/pack.json',
      'https://service.svc/pack.json',
      'https://intranet.home/pack.json',
      'https://endpoint.arpa/pack.json',
      'https://sandbox.test/pack.json',
      'https://archive.invalid/pack.json',
      'https://hidden.onion/pack.json',
      'https://docs.example.org./pack.json',
      'https://bad_host.example.org/pack.json',
      'https://0x7f000001/pack.json',
      'https://2130706433/pack.json',
      'https://docs.example.org/',
    ]) {
      final blocked = SignedRunbookSource(
        url: url,
        publicKeyBase64: keys.key,
        transport: (_) async {
          transportCalls++;
          return '{}';
        },
      );
      expect(blocked.configured, isFalse, reason: url);
      await expectLater(blocked.fetch(), throwsFormatException);
    }
    expect(transportCalls, 0,
        reason: 'Blocked destinations must not reach a network transport');

    final source = SignedRunbookSource(
      url: 'https://docs.example.org/pack.json',
      publicKeyBase64: keys.key,
      transport: (_) async => 'x' * (810 * 1024),
    );
    await expectLater(source.fetch(), throwsFormatException);
  });

  test('standard HTTPS port 443 permits signed public runbook sync', () async {
    final keys = await signer();
    final envelope = await signedEnvelope(key: keys.pair);
    var calls = 0;
    final source = SignedRunbookSource(
      url: 'https://docs.example.org:443/packs/signed.json',
      publicKeyBase64: keys.key,
      transport: (uri) async {
        calls++;
        expect(uri.host, 'docs.example.org');
        expect(uri.port, 443);
        return envelope;
      },
    );
    expect(source.configured, isTrue);
    final repo = OfflineRunbookRepository(
      source: source,
      store: MemorySnapshotStore(),
      bundled: () => SynchronousFuture<OfflineRunbookPack>(fallback),
    );
    final pack = await repo.sync();
    expect(pack.signedVerified, isTrue);
    expect(pack.items.single.id, 'safe-checklist');
    expect(calls, 1);
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

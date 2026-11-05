import 'dart:convert';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/signed_runbook_sync.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

const publishedId = '12345678-1234-4234-8234-123456789012';
const otherId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const publishedMarkdown = '# Approved recovery\nVerify the evidence first.';
final publishedAt = DateTime.parse('2026-11-02T10:30:00Z');

class _MemoryStore implements RunbookSnapshotStore {
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

/// Exercises the production optional per-UUID contract without an actual
/// phone filesystem. A newly constructed repository is a cold start.
class _PinnedMemoryStore implements RunbookPinnedSnapshotStore {
  String? global;
  final Map<String, String> pins = {};
  int writes = 0;

  @override
  Future<String?> read() async => global;

  @override
  Future<void> write(String envelope) async {
    global = envelope;
    writes++;
  }

  @override
  Future<List<String>> pinnedIds() async => pins.keys.toList()..sort();

  @override
  Future<String?> readPinned(String id) async => pins[id];

  @override
  Future<void> writePinned(String id, String envelope) async {
    pins[id] = envelope;
    writes++;
  }
}

Future<String> _envelope(
  SimpleKeyPair pair, {
  String id = publishedId,
  int version = 3,
  String markdown = publishedMarkdown,
  DateTime? validatedAt,
  String? checksumOverride,
  bool published = true,
  DateTime? signedAt,
}) async {
  final checksum = (await Sha256().hash(utf8.encode(markdown)))
      .bytes.map((byte) => byte.toRadixString(16).padLeft(2, '0')).join();
  final item = <String, dynamic>{
    'id': id,
    'title_en': 'Approved recovery',
    'title_ar': 'استعادة معتمدة',
    'steps_en': ['Verify approved backup'],
    'steps_ar': ['تحقق من النسخة المعتمدة'],
    if (published) ...{
      'published_version': version,
      'published_markdown': markdown,
      'published_validated_at':
          (validatedAt ?? publishedAt).toIso8601String(),
      'published_body_sha256': checksumOverride ?? checksum,
    },
  };
  final timestamp = (signedAt ?? DateTime.now().toUtc()
      .subtract(const Duration(minutes: 3))).toIso8601String();
  final payload = utf8.encode(jsonEncode({
    'schema': 'walidsite.offline-runbook-pack.v1',
    'version': '3.0.0',
    'source': 'Fixture publisher — reviewed public content',
    'last_synced_at': timestamp,
    'verified_at': timestamp,
    'items': [item],
  }));
  final signature = await Ed25519().sign(payload, keyPair: pair);
  return jsonEncode({
    'schema': 'walidsite.signed-runbook-pack.v1',
    'payload_base64': base64Encode(payload),
    'signature_base64': base64Encode(signature.bytes),
  });
}

Future<({SimpleKeyPair pair, String publicKey})> _keys() async {
  final pair = await Ed25519().newKeyPair();
  return (pair: pair,
      publicKey: base64Encode((await pair.extractPublicKey()).bytes));
}

SignedRunbookSource _source(String key, String body) => SignedRunbookSource(
  url: 'https://updates.example.org/reviewed-pack.json',
  publicKeyBase64: key,
  transport: (_) async => body,
);

PublishedRunbookRevision _current({
  String id = publishedId,
  int version = 3,
  String markdown = publishedMarkdown,
  DateTime? at,
}) => PublishedRunbookRevision(
  id: id, version: version, markdown: markdown,
  validatedAt: at ?? publishedAt,
);

ReviewedRunbook _live({String id = publishedId,
    int version = 3, String markdown = publishedMarkdown}) =>
    ReviewedRunbook(
      id: id, title: 'Approved recovery', markdown: markdown,
      version: version, validatedAt: publishedAt,
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('MOBILE-05 sequential disjoint signed downloads survive cold restart', () async {
    final keys = await _keys();
    final packA = await _envelope(keys.pair, id: publishedId);
    final packB = await _envelope(keys.pair, id: otherId,
        markdown: '# Different reviewed database runbook');
    var response = packA;
    var requests = 0;
    final source = SignedRunbookSource(
      url: 'https://updates.example.org/reviewed-pack.json',
      publicKeyBase64: keys.publicKey,
      transport: (_) async { requests++; return response; },
    );
    final store = _PinnedMemoryStore();
    final repository = OfflineRunbookRepository(source: source, store: store);
    final first = await repository.syncPublished(_current());
    expect(first.items.map((item) => item.id), [publishedId]);
    response = packB;
    final second = await repository.syncPublished(_current(
        id: otherId, markdown: '# Different reviewed database runbook'));
    expect(second.signedVerified, isTrue);
    expect(second.items.map((item) => item.id), [otherId, publishedId]..sort());
    expect(store.pins.keys.toSet(), {publishedId, otherId});
    expect(store.global, isNull, reason: 'Selected downloads cannot overwrite global snapshot');

    final restarted = OfflineRunbookRepository(source: source, store: store);
    final offline = await restarted.load();
    expect(offline.signedVerified, isTrue);
    expect(offline.items.length, 2);
    expect(offline.items.firstWhere((item) => item.id == publishedId)
        .publishedMarkdown, publishedMarkdown);
    expect(offline.items.firstWhere((item) => item.id == otherId)
        .publishedMarkdown, '# Different reviewed database runbook');
    expect(requests, 2, reason: 'Cold-start offline reopening does not fetch publisher');
  });

  test('revoked or mismatched second download never evicts an earlier signed UUID',
      () async {
    final keys = await _keys();
    final original = await _envelope(keys.pair, id: publishedId);
    final wrong = await _envelope(keys.pair, id: otherId,
        checksumOverride: '0' * 64);
    var response = original;
    final store = _PinnedMemoryStore();
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://updates.example.org/reviewed-pack.json',
        publicKeyBase64: keys.publicKey,
        transport: (_) async => response,
      ),
      store: store,
    );
    await repo.syncPublished(_current());
    response = wrong;
    await expectLater(repo.syncPublished(_current(id: otherId)),
        throwsFormatException);
    expect(store.pins.keys.toList(), [publishedId]);
    expect((await repo.load()).items.single.id, publishedId);
  });

  test('older signed revision of the SAME UUID cannot replace newer pinned data',
      () async {
    final keys = await _keys();
    final moment = DateTime.now().toUtc();
    final newRevision = await _envelope(keys.pair, version: 4,
        signedAt: moment.subtract(const Duration(minutes: 1)));
    final oldRevision = await _envelope(keys.pair, version: 3,
        signedAt: moment.subtract(const Duration(minutes: 2)));
    var response = newRevision;
    final store = _PinnedMemoryStore();
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://updates.example.org/reviewed-pack.json',
        publicKeyBase64: keys.publicKey,
        transport: (_) async => response,
      ), store: store,
    );
    await repo.syncPublished(_current(version: 4));
    response = oldRevision;
    await expectLater(repo.syncPublished(_current(version: 3)),
        throwsFormatException);
    expect(store.pins[publishedId], newRevision);
    expect((await repo.load()).items.single.publishedVersion, 4);
  });

  test('newer signature timestamp cannot downgrade an already pinned published version',
      () async {
    final keys = await _keys();
    final moment = DateTime.now().toUtc();
    final versionFour = await _envelope(keys.pair, version: 4,
        signedAt: moment.subtract(const Duration(minutes: 2)));
    final versionThreeResigned = await _envelope(keys.pair, version: 3,
        signedAt: moment.subtract(const Duration(minutes: 1)));
    var response = versionFour;
    final store = _PinnedMemoryStore();
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'https://updates.example.org/reviewed-pack.json',
        publicKeyBase64: keys.publicKey,
        transport: (_) async => response,
      ),
      store: store,
    );
    await repo.syncPublished(_current(version: 4));
    final writesBefore = store.writes;
    response = versionThreeResigned;
    await expectLater(repo.syncPublished(_current(version: 3)),
        throwsFormatException);
    expect(store.writes, writesBefore,
        reason: 'A re-signed downgrade cannot replace a verified old copy');
    expect(store.pins[publishedId], versionFour);
    final restarted = OfflineRunbookRepository(source: repo.source, store: store);
    expect((await restarted.load()).items.single.publishedVersion, 4);
  });

  test('reviewed UUID + exact revision saves signed copy and reopens offline after restart',
      () async {
    final keys = await _keys();
    final envelope = await _envelope(keys.pair);
    final store = _MemoryStore();
    final first = OfflineRunbookRepository(
      source: _source(keys.publicKey, envelope), store: store,
    );

    final pack = await first.syncPublished(_current());
    expect(pack.signedVerified, isTrue);
    expect(pack.items.single.id, publishedId);
    expect(pack.items.single.publishedVersion, 3);
    expect(pack.items.single.publishedMarkdown, publishedMarkdown);
    expect(store.writes, 1);

    final restarted = OfflineRunbookRepository(
      source: _source(keys.publicKey, envelope), store: store,
    );
    final cached = await restarted.load();
    expect(cached.signedVerified, isTrue);
    expect(cached.items.single.publishedBodySha256,
        pack.items.single.publishedBodySha256);
    expect(cached.items.single.publishedMarkdown, publishedMarkdown);
  });

  test('valid signature for another ID, version, body or review date never replaces old copy',
      () async {
    final keys = await _keys();
    final good = await _envelope(keys.pair);
    final store = _MemoryStore()..snapshot = good;
    final variants = [
      await _envelope(keys.pair, id: otherId),
      await _envelope(keys.pair, version: 4),
      await _envelope(keys.pair, markdown: '# Changed approved content'),
      await _envelope(keys.pair,
          validatedAt: publishedAt.add(const Duration(hours: 1))),
      await _envelope(keys.pair, published: false),
    ];
    for (final bad in variants) {
      final repository = OfflineRunbookRepository(
        source: _source(keys.publicKey, bad), store: store,
      );
      await expectLater(
          repository.syncPublished(_current()), throwsFormatException);
      expect(store.snapshot, good);
      expect(store.writes, 0);
      expect((await repository.load()).items.single.publishedMarkdown,
          publishedMarkdown);
    }
  });

  test('even a signed payload with a forged internal markdown digest is rejected',
      () async {
    final keys = await _keys();
    final good = await _envelope(keys.pair);
    final store = _MemoryStore()..snapshot = good;
    final forged = await _envelope(keys.pair, checksumOverride: '0' * 64);
    final repository = OfflineRunbookRepository(
      source: _source(keys.publicKey, forged), store: store,
    );
    await expectLater(repository.syncPublished(_current()), throwsFormatException);
    expect(store.snapshot, good);
    expect(store.writes, 0);
  });

  test('published metadata must be complete, UUID-scoped and bounded', () async {
    final keys = await _keys();
    final partial = await _envelope(keys.pair, id: 'bundled-guide');
    // Non-UUID signed publication is rejected, not quietly treated as a
    // downloaded copy of the desired public runbook.
    await expectLater(
      _source(keys.publicKey, partial).verify(partial),
      throwsFormatException,
    );
    await expectLater(
      OfflineRunbookRepository(
        source: const SignedRunbookSource(
          url: '', publicKeyBase64: '',
        ),
        store: _MemoryStore(),
      ).syncPublished(_current()),
      throwsFormatException,
    );
  });

  testWidgets('EN and AR show explicit unavailable signed download, not fake offline success',
      (tester) async {
    for (final arabic in [false, true]) {
      await tester.pumpWidget(MaterialApp(
        home: ReviewedRunbookDetail(
          key: ValueKey(arabic), id: publishedId, arabic: arabic,
          loader: (_) async => _live(),
          offlineRepository: OfflineRunbookRepository(
            source: const SignedRunbookSource(url: '', publicKeyBase64: ''),
            store: _MemoryStore(),
          ),
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('online-runbook-download')), findsOneWidget);
      final button = tester.widget<OutlinedButton>(
          find.byKey(const Key('online-runbook-download')));
      expect(button.onPressed, isNull);
      expect(find.byKey(const Key('online-runbook-download-unconfigured')),
          findsOneWidget);
      expect(find.byKey(const Key('runbook-published-markdown')), findsNothing);
    }
  });

  testWidgets('reviewed Download rechecks approval and opens exact signed revision',
      (tester) async {
    final keys = await _keys();
    final good = await _envelope(keys.pair);
    final store = _MemoryStore();
    var reads = 0;
    var downloads = 0;
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookDetail(
      id: publishedId, arabic: false,
      loader: (_) async { reads++; return _live(); },
      offlineRepository: OfflineRunbookRepository(
        source: SignedRunbookSource(
          url: 'https://updates.example.org/reviewed-pack.json',
          publicKeyBase64: keys.publicKey,
          transport: (_) async { downloads++; return good; },
        ),
        store: store,
      ),
    )));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('online-runbook-download')));
    await tester.tap(find.byKey(const Key('online-runbook-download')));
    await tester.pumpAndSettle();
    expect(reads, 2, reason: 'Opening detail is not a substitute for download-time review');
    expect(downloads, 1);
    expect(store.writes, 1);
    expect(find.byKey(const Key('offline-runbook-detail')), findsOneWidget);
    expect(find.byKey(const Key('runbook-published-markdown')), findsOneWidget);
    expect(find.text(publishedMarkdown), findsOneWidget);
  });

  testWidgets('revoked live guide aborts download before touching signed snapshot',
      (tester) async {
    final keys = await _keys();
    final good = await _envelope(keys.pair);
    final store = _MemoryStore()..snapshot = good;
    var reads = 0;
    var fetches = 0;
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookDetail(
      id: publishedId, arabic: true,
      loader: (_) async {
        reads++;
        if (reads > 1) throw const FormatException('SECRET internal withdrawn');
        return _live();
      },
      offlineRepository: OfflineRunbookRepository(
        source: SignedRunbookSource(
          url: 'https://updates.example.org/reviewed-pack.json',
          publicKeyBase64: keys.publicKey,
          transport: (_) async { fetches++; return good; },
        ),
        store: store,
      ),
    )));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('online-runbook-download')));
    await tester.tap(find.byKey(const Key('online-runbook-download')));
    await tester.pumpAndSettle();
    expect(reads, 2);
    expect(fetches, 0);
    expect(store.snapshot, good);
    expect(store.writes, 0);
    expect(find.textContaining('SECRET internal withdrawn'), findsNothing);
    expect(find.byKey(const Key('online-runbook-download-status')), findsOneWidget);
  });
}

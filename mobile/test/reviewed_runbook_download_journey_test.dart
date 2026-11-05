import 'dart:async';
import 'dart:convert';

import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';
import 'package:waleed_tech_companion/runbooks/signed_runbook_sync.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

const selectedId = '00000000-0000-4000-8000-000000000001';
const selectedBody = '# Verify backup before restoring';
const downloadActionKey = Key('online-runbook-download-00000000-0000-4000-8000-000000000001');
const validated = '2026-10-31T12:30:00+00:00';

class SnapshotMemory implements RunbookSnapshotStore {
  String? envelope;
  int writes = 0;

  @override
  Future<String?> read() async => envelope;

  @override
  Future<void> write(String value) async {
    envelope = value;
    writes++;
  }
}

ReviewedRunbook selected({int version = 2, String body = selectedBody}) =>
    ReviewedRunbook(
      id: selectedId,
      title: 'Reviewed database restoration',
      markdown: body,
      version: version,
      validatedAt: DateTime.parse(validated),
    );

ReviewedRunbookPage firstPage() => ReviewedRunbookPage(
  items: [selected()],
  currentPage: 1,
  hasMorePages: false,
);

Future<String> publisherEnvelope(SimpleKeyPair signingKey, {
  String id = selectedId,
  int version = 2,
  String markdown = selectedBody,
  bool wrongBodyHash = false,
}) async {
  final digest = await Sha256().hash(utf8.encode(markdown));
  final checksum = digest.bytes.map(
    (byte) => byte.toRadixString(16).padLeft(2, '0'),
  ).join();
  final at = DateTime.now().toUtc().subtract(const Duration(minutes: 2))
      .toIso8601String();
  final bytes = utf8.encode(jsonEncode({
    'schema': 'walidsite.offline-runbook-pack.v1',
    'version': '2.0.0',
    'source': 'Reviewed runbook publisher fixture',
    'last_synced_at': at,
    'verified_at': at,
    'items': [{
      'id': id,
      'title_en': 'Reviewed database restoration',
      'title_ar': 'استعادة قواعد البيانات المعتمدة',
      'steps_en': ['Verify backup before restoring'],
      'steps_ar': ['تحقق من النسخة قبل الاستعادة'],
      'published_version': version,
      'published_markdown': markdown,
      'published_validated_at': validated,
      'published_body_sha256': wrongBodyHash ? List.filled(64, 'a').join() : checksum,
    }],
  }));
  final signature = await Ed25519().sign(bytes, keyPair: signingKey);
  return jsonEncode({
    'schema': 'walidsite.signed-runbook-pack.v1',
    'payload_base64': base64Encode(bytes),
    'signature_base64': base64Encode(signature.bytes),
  });
}

Future<({
  OfflineRunbookRepository repository,
  SnapshotMemory store,
  int Function() calls,
})> repositoryFixture({
  bool invalidSignature = false,
  int signedVersion = 2,
  bool wrongBodyHash = false,
}) async {
  final signing = await Ed25519().newKeyPair();
  final unrelated = await Ed25519().newKeyPair();
  final publicKey = base64Encode((await
      (invalidSignature ? unrelated : signing).extractPublicKey()).bytes);
  final envelope = await publisherEnvelope(
    signing, version: signedVersion, wrongBodyHash: wrongBodyHash,
  );
  final store = SnapshotMemory();
  var calls = 0;
  final source = SignedRunbookSource(
    url: 'https://docs.example.org/packs/signed.json',
    publicKeyBase64: publicKey,
    transport: (_) async {
      calls++;
      return envelope;
    },
  );
  return (
    repository: OfflineRunbookRepository(
      source: source,
      store: store,
      bundled: () async => const OfflineRunbookPack(
        version: '0.1.0',
        source: 'Bundled educational copy only',
        lastSynced: null,
        verifiedAt: null,
        items: [],
      ),
    ),
    store: store,
    calls: () => calls,
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('selected UUID and exact revision are required before publisher contact',
      () async {
    final fixture = await repositoryFixture();
    for (final changed in [
      selected(version: 3),
      selected(body: '# Replaced without matching reviewed revision'),
      ReviewedRunbook(
        id: '00000000-0000-4000-8000-000000000002',
        title: 'Other',
        markdown: selectedBody,
        version: 2,
        validatedAt: DateTime.parse(validated),
      ),
    ]) {
      await expectLater(
        downloadReviewedPublication(
          listed: selected(),
          reload: (_) async => changed,
          repository: fixture.repository,
        ),
        throwsFormatException,
      );
    }
    await expectLater(
      downloadReviewedPublication(
        listed: selected(),
        reload: (_) async => throw const FormatException('Withdrawn'),
        repository: fixture.repository,
      ),
      throwsFormatException,
    );
    expect(fixture.calls(), 0);
    expect(fixture.store.writes, 0);
  });

  test('wrong publisher signature, version and content hash never persist',
      () async {
    for (final setup in [
      (invalidSignature: true, signedVersion: 2, wrongBodyHash: false),
      (invalidSignature: false, signedVersion: 3, wrongBodyHash: false),
      (invalidSignature: false, signedVersion: 2, wrongBodyHash: true),
    ]) {
      final fixture = await repositoryFixture(
        invalidSignature: setup.invalidSignature,
        signedVersion: setup.signedVersion,
        wrongBodyHash: setup.wrongBodyHash,
      );
      await expectLater(
        downloadReviewedPublication(
          listed: selected(),
          reload: (_) async => selected(),
          repository: fixture.repository,
        ),
        throwsFormatException,
      );
      expect(fixture.store.writes, 0);
      expect(fixture.store.envelope, isNull);
    }
  });

  testWidgets('MOBILE-05 direct signed download opens MOBILE-06 offline and survives restart',
      (tester) async {
    final fixture = await repositoryFixture();
    var freshReads = 0;
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: false,
      pageLoader: (_) => SynchronousFuture(firstPage()),
      detailLoader: (_) async {
        freshReads++;
        return selected();
      },
      offlineRepository: fixture.repository,
    )));
    await tester.pumpAndSettle();
    final action = find.byKey(downloadActionKey);
    expect(action, findsOneWidget);
    expect(fixture.calls(), 0);
    await tester.ensureVisible(action);
    await tester.tap(action);
    await tester.pumpAndSettle();
    expect(freshReads, 1);
    expect(fixture.calls(), 1);
    expect(fixture.store.writes, 1);
    expect(find.byKey(const Key('offline-runbook-detail')), findsOneWidget);

    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(find.text('Signed published revision verified and stored offline.'),
        findsOneWidget);
    // A new repository instance represents a cold start. It must authenticate
    // the retained bytes without making another publisher request.
    final restarted = OfflineRunbookRepository(
      source: fixture.repository.source,
      store: fixture.store,
      bundled: fixture.repository.bundled,
    );
    final copy = await restarted.load();
    expect(copy.signedVerified, isTrue);
    expect(copy.items.single.id, selectedId);
    expect(copy.items.single.publishedMarkdown, selectedBody);
    expect(fixture.calls(), 1);
  });

  testWidgets('MOBILE-05 rejects withdrawn guide without false success in Arabic',
      (tester) async {
    final fixture = await repositoryFixture();
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: true,
      pageLoader: (_) => SynchronousFuture(firstPage()),
      detailLoader: (_) async => throw const FormatException('PRIVATE HOST ERROR'),
      offlineRepository: fixture.repository,
    )));
    await tester.pumpAndSettle();
    final action = find.byKey(downloadActionKey);
    await tester.ensureVisible(action);
    await tester.tap(action);
    await tester.pumpAndSettle();
    expect(fixture.calls(), 0);
    expect(fixture.store.writes, 0);
    expect(find.byKey(const Key('offline-runbook-detail')), findsNothing);
    expect(find.textContaining('PRIVATE HOST ERROR'), findsNothing);
    expect(find.text('تعذّر توثيق التنزيل. احتُفظ بالنسخة المحلية السابقة.'),
        findsOneWidget);
  });

  testWidgets('unconfigured signed source disables direct download without requests',
      (tester) async {
    var transportCalls = 0;
    final repo = OfflineRunbookRepository(
      source: SignedRunbookSource(
        url: 'http://localhost/unsafe.json',
        publicKeyBase64: '',
        transport: (_) async {
          transportCalls++;
          return '{}';
        },
      ),
      store: SnapshotMemory(),
    );
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: false,
      pageLoader: (_) => SynchronousFuture(firstPage()),
      offlineRepository: repo,
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-download-unconfigured')),
        findsOneWidget);
    final action = find.byKey(downloadActionKey);
    expect(tester.widget<OutlinedButton>(action).onPressed, isNull);
    expect(transportCalls, 0);
  });

  testWidgets('pending download disables another tap and does not fake completion',
      (tester) async {
    final fixture = await repositoryFixture();
    final waiting = Completer<ReviewedRunbook>();
    var reads = 0;
    await tester.pumpWidget(MaterialApp(home: ReviewedRunbookLibrary(
      arabic: false,
      pageLoader: (_) => SynchronousFuture(firstPage()),
      detailLoader: (_) {
        reads++;
        return waiting.future;
      },
      offlineRepository: fixture.repository,
    )));
    await tester.pumpAndSettle();
    final action = find.byKey(downloadActionKey);
    await tester.ensureVisible(action);
    await tester.tap(action);
    await tester.pump();
    expect(tester.widget<OutlinedButton>(action).onPressed, isNull);
    expect(reads, 1);
    expect(fixture.calls(), 0);
    expect(find.text('Signed published revision verified and stored offline.'),
        findsNothing);
    waiting.completeError(const FormatException('Revoked'));
    await tester.pumpAndSettle();
    expect(fixture.store.writes, 0);
  });
}

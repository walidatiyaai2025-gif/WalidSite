import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';
import 'package:path_provider/path_provider.dart';

import 'offline_runbooks.dart';

/// A signed update is not trusted simply because HTTPS or JSON succeeded.
/// The pinned Ed25519 publisher public key is supplied at build time, never
/// downloaded with the update or replaced by a remote key id.
class SignedRunbookSource {
  const SignedRunbookSource({
    required this.url,
    required this.publicKeyBase64,
    this.transport,
    this.httpClientFactory,
    this.totalTimeout = const Duration(seconds: 12),
  });

  static const configuredUrl = String.fromEnvironment('WALIDSITE_RUNBOOK_PACK_URL');
  static const configuredPublicKey =
      String.fromEnvironment('WALIDSITE_RUNBOOK_PUBLIC_KEY');

  final String url;
  final String publicKeyBase64;
  final Future<String> Function(Uri uri)? transport;

  /// Only a test fixture should replace the production HTTPS HttpClient.
  /// The same whole-request deadline and force-close path handles both.
  final HttpClient Function()? httpClientFactory;
  final Duration totalTimeout;

  Uri? get endpoint {
    final uri = Uri.tryParse(url);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        uri.path.isEmpty ||
        uri.path == '/' ||
        (uri.hasPort && uri.port != 443)) {
      return null;
    }

    // Pinned signatures protect content integrity, not the destination.
    // Never connect to internal, numeric-alias or non-public DNS targets.
    final host = uri.host.toLowerCase();
    final publicHostPattern = RegExp(
      r'^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$',
    );
    const privateSuffixes = [
      'localhost', 'local', 'localdomain', 'internal', 'test',
      'invalid', 'onion', 'lan', 'home', 'arpa', 'svc',
    ];
    if (InternetAddress.tryParse(host) != null ||
        !publicHostPattern.hasMatch(host) ||
        privateSuffixes.any(
          (suffix) => host == suffix || host.endsWith('.$suffix'),
        )) {
      return null;
    }
    return uri;
  }

  List<int>? get trustedKey {
    try {
      final bytes = base64Decode(publicKeyBase64);
      return bytes.length == 32 ? bytes : null;
    } on FormatException {
      return null;
    }
  }

  bool get configured => endpoint != null && trustedKey != null;

  Future<String> fetch() async {
    final uri = endpoint;
    if (uri == null || trustedKey == null) {
      throw const FormatException('Signed runbook updates not configured');
    }
    if (totalTimeout <= Duration.zero) {
      throw const FormatException('Invalid signed runbook deadline');
    }
    if (transport != null) {
      final value = await transport!(uri).timeout(totalTimeout);
      if (utf8.encode(value).length > 800 * 1024) {
        throw const FormatException('Signed runbook update too large');
      }
      return value;
    }

    final client = (httpClientFactory?.call() ?? HttpClient())
      ..connectionTimeout = const Duration(seconds: 8);
    try {
      // A per-chunk timeout can be reset indefinitely by a slow peer.
      // Apply one deadline to connection, headers, every byte and UTF-8
      // decoding; force-closing the underlying client aborts the live socket.
      return await _fetchFromClient(client, uri).timeout(
        totalTimeout,
        onTimeout: () {
          client.close(force: true);
          throw TimeoutException('Signed runbook total download deadline exceeded');
        },
      );
    } finally {
      client.close(force: true);
    }
  }

  Future<String> _fetchFromClient(HttpClient client, Uri uri) async {
    final request = await client.getUrl(uri);
    request.followRedirects = false;
    request.headers.set(HttpHeaders.acceptHeader, 'application/json');
    final response = await request.close();
    if (response.statusCode != HttpStatus.ok) {
      throw const HttpException('Signed runbook service unavailable');
    }
    final buffer = BytesBuilder(copy: false);
    await for (final chunk in response) {
      if (buffer.length + chunk.length > 800 * 1024) {
        throw const FormatException('Signed runbook update too large');
      }
      buffer.add(chunk);
    }
    return utf8.decode(buffer.takeBytes(), allowMalformed: false);
  }

  Future<OfflineRunbookPack> verify(String envelope) async {
    if (!configured || utf8.encode(envelope).length > 800 * 1024) {
      throw const FormatException('Untrusted runbook envelope');
    }
    final decoded = jsonDecode(envelope);
    if (decoded is! Map<String, dynamic> ||
        decoded['schema'] != 'walidsite.signed-runbook-pack.v1' ||
        decoded['payload_base64'] is! String ||
        decoded['signature_base64'] is! String) {
      throw const FormatException('Invalid signed runbook envelope');
    }
    late List<int> payload;
    late List<int> signature;
    try {
      payload = base64Decode(decoded['payload_base64'] as String);
      signature = base64Decode(decoded['signature_base64'] as String);
    } on FormatException {
      throw const FormatException('Invalid runbook signature encoding');
    }
    if (payload.length > 512 * 1024 || signature.length != 64) {
      throw const FormatException('Invalid runbook signature length');
    }
    final valid = await Ed25519().verify(
      payload,
      signature: Signature(
        signature,
        publicKey: SimplePublicKey(trustedKey!, type: KeyPairType.ed25519),
      ),
    );
    if (!valid) throw const FormatException('Runbook signature verification failed');
    final pack = OfflineRunbookPack.parse(
      utf8.decode(payload, allowMalformed: false),
    );
    // An Ed25519 signature authenticates the payload, not the internal
    // checksum claim. Verify exact UTF-8 markdown bytes before storing it.
    for (final item in pack.items) {
      if (item.publishedMarkdown == null) continue;
      final digest = await Sha256().hash(utf8.encode(item.publishedMarkdown!));
      final actual = digest.bytes.map(
        (byte) => byte.toRadixString(16).padLeft(2, '0'),
      ).join();
      if (actual != item.publishedBodySha256) {
        throw const FormatException('Signed publication content mismatch');
      }
    }
    final verified = pack.verifiedAt;
    if (verified == null ||
        verified.isAfter(DateTime.now().toUtc().add(const Duration(minutes: 5)))) {
      throw const FormatException('Runbook publisher verification time missing/invalid');
    }
    return OfflineRunbookPack(
      version: pack.version,
      source: pack.source,
      lastSynced: pack.lastSynced,
      verifiedAt: pack.verifiedAt,
      items: pack.items,
      signedVerified: true,
    );
  }
}

/// App-support storage, not a public download or web-root file. The entire
/// envelope is reverified on EVERY startup before cached content is displayed.
abstract class RunbookSnapshotStore {
  Future<String?> read();
  Future<void> write(String envelope);
}

class AppSupportRunbookStore implements RunbookSnapshotStore {
  const AppSupportRunbookStore();

  Future<File> _file() async {
    final dir = await getApplicationSupportDirectory();
    return File(dir.path + Platform.pathSeparator + 'signed-runbook-pack-v1.json');
  }

  @override
  Future<String?> read() async {
    final file = await _file();
    if (!await file.exists()) return null;
    if (await file.length() > 800 * 1024) return null;
    return file.readAsString();
  }

  /// Clear only the downloaded signed snapshot and its interrupted temp file.
  /// The immutable bundled fallback and all server/account data stay intact.
  Future<void> clearDownloadedSnapshot() async {
    final target = await _file();
    for (final file in [target, File(target.path + '.part')]) {
      if (await file.exists()) await file.delete();
    }
  }

  @override
  Future<void> write(String envelope) async {
    final target = await _file();
    final temp = File(target.path + '.part');
    try {
      await temp.writeAsString(envelope, flush: true);
      await temp.rename(target.path);
    } finally {
      if (await temp.exists()) await temp.delete();
    }
  }
}

/// Live reviewed identity refreshed on explicit user Download; a generic
/// signed educational pack cannot masquerade as this published revision.
class PublishedRunbookRevision {
  const PublishedRunbookRevision({
    required this.id,
    required this.version,
    required this.markdown,
    required this.validatedAt,
  });

  final String id;
  final int version;
  final String markdown;
  final DateTime validatedAt;
}

class OfflineRunbookRepository {
  OfflineRunbookRepository({
    SignedRunbookSource? source,
    RunbookSnapshotStore? store,
    Future<OfflineRunbookPack> Function()? bundled,
  })  : source = source ?? const SignedRunbookSource(
          url: SignedRunbookSource.configuredUrl,
          publicKeyBase64: SignedRunbookSource.configuredPublicKey,
        ),
        store = store ?? const AppSupportRunbookStore(),
        bundled = bundled ?? OfflineRunbookPack.loadBundled;

  final SignedRunbookSource source;
  final RunbookSnapshotStore store;
  final Future<OfflineRunbookPack> Function() bundled;

  // Multiple repository instances (online library, offline library, or a
  // recreated view) may share the same app-support snapshot. An instance-only
  // queue lets both validate the same stale disk state and then overwrite a
  // newer verified pack. Serialize all signed syncs within the app isolate;
  // every operation rechecks the stored signature after acquiring its slot.
  // Do not retain a completed Future across unrelated Flutter widget zones.
  static Future<void>? _sharedSyncBarrier;

  // Retain the exact signed envelope alongside its verified payload.
  // A timestamp alone cannot establish an ordering between two different
  // publisher-signed packs stamped at the same instant.
  Future<({OfflineRunbookPack pack, String envelope})?> _trustedSnapshot() async {
    if (!source.configured) return null;
    try {
      final stored = await store.read();
      if (stored == null) return null;
      final pack = await source.verify(stored);
      return (pack: pack, envelope: stored);
    } catch (_) {
      // Invalid/partially written/unreadable snapshots never become trusted.
      return null;
    }
  }

  Future<OfflineRunbookPack> load() async =>
      (await _trustedSnapshot())?.pack ?? await bundled();

  Future<OfflineRunbookPack> sync() {
    final prior = _sharedSyncBarrier;
    final completed = Completer<void>();
    _sharedSyncBarrier = completed.future;
    return _syncAfter(prior, completed);
  }

  /// A signed envelope for a different UUID/revision must NEVER replace the
  /// last trusted copy as the result of a user-selected published download.
  Future<OfflineRunbookPack> syncPublished(PublishedRunbookRevision revision) {
    final prior = _sharedSyncBarrier;
    final completed = Completer<void>();
    _sharedSyncBarrier = completed.future;
    return _syncAfter(prior, completed, revision);
  }

  Future<OfflineRunbookPack> _syncAfter(
      Future<void>? prior, Completer<void> completed,
      [PublishedRunbookRevision? revision]) async {
    try {
      if (prior != null) await prior;
      return await _syncOnce(revision);
    } finally {
      // A newer queued update keeps its reservation; the last completed
      // operation clears the slot so unrelated widget zones don't inherit
      // an old asynchronous Future.
      if (identical(_sharedSyncBarrier, completed.future)) {
        _sharedSyncBarrier = null;
      }
      completed.complete();
    }
  }

  Future<OfflineRunbookPack> _syncOnce(
      [PublishedRunbookRevision? revision]) async {
    if (!source.configured) {
      throw const FormatException('Signed runbook updates not configured');
    }
    final received = await source.fetch();
    final fresh = await source.verify(received);
    if (revision != null) {
      // Compare against the live API's current identity, not just the
      // previously listed item or a publisher-controlled label.
      final matches = fresh.items.where((item) =>
          item.id == revision.id.toLowerCase() &&
          item.publishedVersion == revision.version &&
          item.publishedMarkdown == revision.markdown &&
          item.publishedValidatedAt?.isAtSameMomentAs(
              revision.validatedAt) == true).toList();
      if (matches.length != 1) {
        throw const FormatException('Published revision absent from signed pack');
      }
    }
    final snapshot = await _trustedSnapshot();
    if (snapshot != null) {
      final order = fresh.verifiedAt!.compareTo(snapshot.pack.verifiedAt!);
      if (order < 0) {
        throw const FormatException('Refusing older signed runbook update');
      }
      if (order == 0) {
        // Re-downloading identical signed bytes is harmless and should not
        // rewrite the offline cache. A *different* signed payload with the
        // same UTC time is unordered: do not permit a same-time rollback.
        if (received != snapshot.envelope) {
          throw const FormatException('Conflicting signed runbook timestamp');
        }
        return snapshot.pack;
      }
    }
    await store.write(received);
    return fresh;
  }
}

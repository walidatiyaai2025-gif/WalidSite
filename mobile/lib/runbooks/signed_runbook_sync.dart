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
      'corp', 'intranet', 'private',
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

/// A second publisher-signed pack must not erase another downloaded UUID.
/// Both the global and individually pinned envelopes are reauthenticated on
/// each new repository instance; no parsed JSON is trusted directly.
abstract class RunbookPinnedSnapshotStore implements RunbookSnapshotStore {
  Future<List<String>> pinnedIds();
  Future<String?> readPinned(String id);
  Future<void> writePinned(String id, String envelope);
}

class AppSupportRunbookStore implements RunbookPinnedSnapshotStore {
  const AppSupportRunbookStore({Directory? supportDirectory})
      : _supportDirectory = supportDirectory;

  // Optional injected app-support directory permits real filesystem tests
  // without overriding the platform's production storage location.
  final Directory? _supportDirectory;

  Future<Directory> _home() async =>
      _supportDirectory ?? await getApplicationSupportDirectory();

  static final _uuid = RegExp(
    r'^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$',
  );
  static const maxPins = 20;

  Future<File> _file() async {
    final dir = await _home();
    return File(dir.path + Platform.pathSeparator + 'signed-runbook-pack-v1.json');
  }

  Future<Directory> _pins({bool create = false}) async {
    final home = await _home();
    final directory = Directory(
      home.path + Platform.pathSeparator + 'signed-runbook-pins-v1',
    );
    final kind = await FileSystemEntity.type(directory.path, followLinks: false);
    if (kind == FileSystemEntityType.notFound && create) {
      await directory.create();
    } else if (kind != FileSystemEntityType.notFound &&
        kind != FileSystemEntityType.directory) {
      throw const FormatException('Unsafe signed runbook storage');
    }
    return directory;
  }

  File _pinned(Directory directory, String id) {
    if (!_uuid.hasMatch(id)) {
      throw const FormatException('Invalid published runbook UUID');
    }
    return File(directory.path + Platform.pathSeparator + '$id.json');
  }

  @override
  Future<List<String>> pinnedIds() async {
    final directory = await _pins();
    if (!await directory.exists()) return const [];
    final ids = <String>[];
    var scanned = 0;
    await for (final entity in directory.list(followLinks: false)) {
      if (++scanned > 64) {
        throw const FormatException('Unbounded signed runbook storage');
      }
      final name = entity.path.split(Platform.pathSeparator).last;
      if (name.endsWith('.part')) continue;
      if (!name.endsWith('.json')) {
        throw const FormatException('Unexpected signed runbook storage entry');
      }
      final id = name.substring(0, name.length - 5);
      if (!_uuid.hasMatch(id) ||
          await FileSystemEntity.type(entity.path, followLinks: false) !=
              FileSystemEntityType.file) {
        throw const FormatException('Unsafe signed runbook storage entry');
      }
      ids.add(id);
      if (ids.length > maxPins) {
        throw const FormatException('Too many signed runbook downloads');
      }
    }
    ids.sort();
    return List.unmodifiable(ids);
  }

  @override
  Future<String?> readPinned(String id) async {
    final file = _pinned(await _pins(), id);
    if (await FileSystemEntity.type(file.path, followLinks: false) !=
        FileSystemEntityType.file) return null;
    if (await file.length() > 800 * 1024) return null;
    return file.readAsString();
  }

  /// Never truncate a pre-existing .part inode: it might be a hardlink to
  /// unrelated app-support data. Remove only the temporary directory entry,
  /// then reserve a new file exclusively before writing any signed bytes.
  /// Symlinks and special-file leftovers are rejected without following them.
  Future<void> _prepareTemporary(File temp) async {
    final kind = await FileSystemEntity.type(temp.path, followLinks: false);
    if (kind == FileSystemEntityType.file) {
      await temp.delete();
    } else if (kind != FileSystemEntityType.notFound) {
      throw const FormatException('Unsafe signed runbook temporary file');
    }
    await temp.create(exclusive: true);
  }

  @override
  Future<void> writePinned(String id, String envelope) async {
    if (envelope.isEmpty || utf8.encode(envelope).length > 800 * 1024) {
      throw const FormatException('Signed runbook snapshot exceeds storage limit');
    }
    final directory = await _pins(create: true);
    final target = _pinned(directory, id);
    final current = await pinnedIds();
    if (!current.contains(id) && current.length >= maxPins) {
      throw const FormatException('Signed runbook storage capacity exceeded');
    }
    final kind = await FileSystemEntity.type(target.path, followLinks: false);
    if (kind != FileSystemEntityType.notFound &&
        kind != FileSystemEntityType.file) {
      throw const FormatException('Unsafe signed snapshot destination');
    }
    final temp = File(target.path + '.part');
    await _prepareTemporary(temp);
    try {
      // Atomic replacement on supported Android/iOS app-support filesystems.
      // A partial write leaves the previous complete pin in place.
      await temp.writeAsString(envelope, flush: true);
      await temp.rename(target.path);
    } finally {
      if (await FileSystemEntity.type(temp.path, followLinks: false) ==
          FileSystemEntityType.file) {
        await temp.delete();
      }
    }
  }

  @override
  Future<String?> read() async {
    final file = await _file();
    final kind = await FileSystemEntity.type(file.path, followLinks: false);
    if (kind == FileSystemEntityType.notFound) return null;
    if (kind != FileSystemEntityType.file) {
      throw const FormatException('Unsafe signed runbook snapshot');
    }
    if (await file.length() > 800 * 1024) return null;
    return file.readAsString();
  }

  /// Removes only the downloaded signed snapshots, never bundled guidance.
  Future<void> clearDownloadedSnapshot() async {
    final target = await _file();
    for (final file in [target, File(target.path + '.part')]) {
      if (await file.exists()) await file.delete();
    }
    final directory = await _pins();
    if (!await directory.exists()) return;
    await for (final entry in directory.list(followLinks: false)) {
      final name = entry.path.split(Platform.pathSeparator).last;
      final id = name.endsWith('.json.part')
          ? name.substring(0, name.length - 10)
          : name.endsWith('.json')
              ? name.substring(0, name.length - 5)
              : '';
      if (_uuid.hasMatch(id) &&
          await FileSystemEntity.type(entry.path, followLinks: false) !=
              FileSystemEntityType.directory) {
        await entry.delete();
      }
    }
  }

  @override
  Future<void> write(String envelope) async {
    if (envelope.isEmpty || utf8.encode(envelope).length > 800 * 1024) {
      throw const FormatException('Signed runbook snapshot exceeds storage limit');
    }
    final target = await _file();
    final temp = File(target.path + '.part');
    final targetKind = await FileSystemEntity.type(
        target.path, followLinks: false);
    if (targetKind != FileSystemEntityType.notFound &&
        targetKind != FileSystemEntityType.file) {
      throw const FormatException('Unsafe signed runbook snapshot destination');
    }
    await _prepareTemporary(temp);
    try {
      await temp.writeAsString(envelope, flush: true);
      await temp.rename(target.path);
    } finally {
      if (await FileSystemEntity.type(temp.path, followLinks: false) ==
          FileSystemEntityType.file) {
        await temp.delete();
      }
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

  /// Reverify global and per-UUID signed envelopes on EVERY cold start.
  /// Only published, hash-checked entries are combined. The OLDEST signed
  /// timestamp controls staleness, not the newest downloaded publication.
  Future<OfflineRunbookPack> load() async {
    final global = await _trustedSnapshot();
    if (store is! RunbookPinnedSnapshotStore || !source.configured) {
      return global?.pack ?? await bundled();
    }
    final entries = <String, ({OfflineRunbook item, DateTime time, String envelope})>{};
    final conflicts = <String>{};
    void include(OfflineRunbookPack pack, String envelope, {String? only}) {
      final time = pack.verifiedAt!;
      for (final item in pack.items) {
        if (item.publishedMarkdown == null ||
            (only != null && item.id != only) ||
            conflicts.contains(item.id)) continue;
        final prior = entries[item.id];
        // A signed envelope's verification timestamp is not the published
        // content version. When a legacy global pack and a per-UUID pin both
        // exist, never let a newly re-signed older revision replace the
        // higher published version on a subsequent cold start.
        final version = item.publishedVersion ?? 0;
        final previousVersion = prior?.item.publishedVersion ?? 0;
        if (prior != null && version == previousVersion &&
            (item.publishedMarkdown != prior.item.publishedMarkdown ||
             item.publishedBodySha256 != prior.item.publishedBodySha256 ||
             item.publishedValidatedAt?.isAtSameMomentAs(
                 prior.item.publishedValidatedAt ?? DateTime.fromMillisecondsSinceEpoch(0, isUtc: true)) != true)) {
          // Two correctly signed envelopes cannot silently redefine the
          // same publication version. Hide this UUID until the conflict is
          // resolved rather than selecting by the newer signing timestamp.
          entries.remove(item.id);
          conflicts.add(item.id);
          continue;
        }
        if (prior == null || version > previousVersion ||
            (version == previousVersion && time.isAfter(prior.time))) {
          entries[item.id] = (item: item, time: time, envelope: envelope);
        } else if (version == previousVersion &&
            time.isAtSameMomentAs(prior.time) &&
            envelope != prior.envelope) {
          // Equal version + time with different signed content is unordered.
          entries.remove(item.id);
          conflicts.add(item.id);
        }
      }
    }

    if (global != null) include(global.pack, global.envelope);
    try {
      final pinnedStore = store as RunbookPinnedSnapshotStore;
      for (final id in await pinnedStore.pinnedIds()) {
        try {
          final text = await pinnedStore.readPinned(id);
          if (text == null) continue;
          final pack = await source.verify(text);
          if (pack.items.where((item) =>
              item.id == id && item.publishedMarkdown != null).length != 1) {
            continue;
          }
          include(pack, text, only: id);
        } catch (_) {
          // Corrupted individual files are never treated as signed data.
        }
      }
    } catch (_) {
      // Corrupted index is not trusted. A valid global snapshot is unchanged.
    }
    // A conflicting signed publication must not re-enter through the
    // legacy global-pack fallback after being deliberately excluded.
    if (entries.isEmpty) {
      return conflicts.isEmpty ? (global?.pack ?? await bundled()) : await bundled();
    }
    final ids = entries.keys.toList()..sort();
    final copies = ids.map((id) => entries[id]!).toList();
    final oldest = copies.map((copy) => copy.time)
        .reduce((a, b) => a.isBefore(b) ? a : b);
    return OfflineRunbookPack(
      version: 'verified-local-revisions',
      source: 'Publisher-verified individual offline publications',
      lastSynced: oldest,
      verifiedAt: oldest,
      items: List.unmodifiable(copies.map((copy) => copy.item)),
      signedVerified: true,
    );
  }

  Future<({OfflineRunbookPack pack, String envelope})?> _trustedPinned(
      String id) async {
    if (store is! RunbookPinnedSnapshotStore || !source.configured) return null;
    try {
      final text = await (store as RunbookPinnedSnapshotStore).readPinned(id);
      if (text == null) return null;
      final pack = await source.verify(text);
      if (pack.items.where((item) =>
          item.id == id && item.publishedMarkdown != null).length != 1) {
        return null;
      }
      return (pack: pack, envelope: text);
    } catch (_) {
      return null;
    }
  }

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
    if (revision != null && store is RunbookPinnedSnapshotStore) {
      final id = revision.id.toLowerCase();
      final pin = await _trustedPinned(id);
      final global = await _trustedSnapshot();
      // A different UUID's newer global pack must not block this download.
      // Never roll this SAME UUID back to an older publisher snapshot.
      final precedents = [
        if (pin != null) pin,
        if (global != null &&
            global.pack.items.any((item) =>
                item.id == id && item.publishedMarkdown != null))
          global,
      ];
      for (final previous in precedents) {
        // A newly signed envelope can carry an older published content
        // version. Signature time must never override a known newer
        // revision of the SAME public UUID in the offline cache.
        final previousVersion = previous.pack.items
            .where((item) => item.id == id && item.publishedMarkdown != null)
            .map((item) => item.publishedVersion)
            .whereType<int>()
            .fold<int>(0, (maxVersion, version) =>
                version > maxVersion ? version : maxVersion);
        if (revision.version < previousVersion) {
          throw const FormatException('Refusing published runbook version downgrade');
        }
        final order = fresh.verifiedAt!.compareTo(previous.pack.verifiedAt!);
        if (order < 0) {
          throw const FormatException('Refusing older signed published revision');
        }
        if (order == 0 && received != previous.envelope) {
          throw const FormatException('Conflicting signed publication timestamp');
        }
        if (order == 0 && received == previous.envelope) {
          return load();
        }
      }
      // Persist only the selected verified UUID; other signed copies survive.
      await (store as RunbookPinnedSnapshotStore).writePinned(id, received);
      final loaded = await load();
      if (!loaded.signedVerified ||
          !loaded.items.any((item) =>
              item.id == id &&
              item.publishedVersion == revision.version &&
              item.publishedMarkdown == revision.markdown &&
              item.publishedValidatedAt?.isAtSameMomentAs(
                  revision.validatedAt) == true)) {
        throw const FormatException('Signed published revision not recoverable');
      }
      return loaded;
    }
    final snapshot = await _trustedSnapshot();
    if (snapshot != null) {
      // Legacy/global signed snapshot storage must enforce the same
      // published-version rollback rule as per-UUID pins. A more recent
      // signature timestamp does not authorize older reviewed content.
      if (revision != null && snapshot.pack.items.any((item) =>
          item.id == revision.id.toLowerCase() &&
          item.publishedMarkdown != null &&
          item.publishedVersion != null &&
          item.publishedVersion! > revision.version)) {
        throw const FormatException('Refusing published runbook version downgrade');
      }
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
    return store is RunbookPinnedSnapshotStore ? await load() : fresh;
  }
}

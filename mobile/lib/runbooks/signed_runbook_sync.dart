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
  });

  static const configuredUrl = String.fromEnvironment('WALIDSITE_RUNBOOK_PACK_URL');
  static const configuredPublicKey =
      String.fromEnvironment('WALIDSITE_RUNBOOK_PUBLIC_KEY');

  final String url;
  final String publicKeyBase64;
  final Future<String> Function(Uri uri)? transport;

  Uri? get endpoint {
    final uri = Uri.tryParse(url);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        uri.path.isEmpty ||
        uri.path == '/' ||
        uri.host == 'localhost' ||
        InternetAddress.tryParse(uri.host) != null ||
        uri.host.endsWith('.local') ||
        uri.host.endsWith('.internal')) {
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
    if (transport != null) {
      final value = await transport!(uri).timeout(const Duration(seconds: 12));
      if (utf8.encode(value).length > 800 * 1024) {
        throw const FormatException('Signed runbook update too large');
      }
      return value;
    }
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(uri).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Signed runbook service unavailable');
      }
      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > 800 * 1024) {
          throw const FormatException('Signed runbook update too large');
        }
        buffer.add(chunk);
      }
      return utf8.decode(buffer.takeBytes(), allowMalformed: false);
    } finally {
      client.close(force: true);
    }
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
    final verified = pack.verifiedAt;
    if (verified == null ||
        verified.isAfter(DateTime.now().toUtc().add(const Duration(minutes: 5)))) {
      throw const FormatException('Runbook publisher verification time missing/invalid');
    }
    return pack;
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

  Future<OfflineRunbookPack?> _trustedSnapshot() async {
    if (!source.configured) return null;
    try {
      final stored = await store.read();
      if (stored == null) return null;
      return await source.verify(stored);
    } catch (_) {
      // Invalid/partially written/unreadable snapshots never become trusted.
      return null;
    }
  }

  Future<OfflineRunbookPack> load() async =>
      await _trustedSnapshot() ?? await bundled();

  Future<OfflineRunbookPack> sync() async {
    if (!source.configured) {
      throw const FormatException('Signed runbook updates not configured');
    }
    final received = await source.fetch();
    final fresh = await source.verify(received);
    final current = await _trustedSnapshot();
    // Reject replay/rollback: an older signed pack cannot replace a newer
    // trusted local snapshot, even if its signature is mathematically valid.
    if (current != null &&
        fresh.verifiedAt!.isBefore(current.verifiedAt!)) {
      throw const FormatException('Refusing older signed runbook update');
    }
    await store.write(received);
    return fresh;
  }
}

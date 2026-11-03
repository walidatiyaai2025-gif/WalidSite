import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:cryptography/cryptography.dart';
import 'package:path_provider/path_provider.dart';

/// An opaque, unpredictable, single-intent key, unrelated to prompt, bearer,
/// technology, or account identity. The backend rejects changed-payload reuse.
final class MobileAiIntentKey {
  MobileAiIntentKey._();

  static final RegExp _valid =
      RegExp(r'^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$');

  static bool isValid(String value) => _valid.hasMatch(value);

  static String generate() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes.map((byte) => byte.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-'
        '${hex.substring(12, 16)}-${hex.substring(16, 20)}-'
        '${hex.substring(20)}';
  }
}

/// The only retained network-retry metadata is an opaque key, never the
/// question, code, token, consent record, or model/provider cost details.
abstract interface class MobileAiIntentStore {
  Future<String?> readKey(String authenticatedEmail);
  Future<void> saveKey(String authenticatedEmail, String key);
  Future<void> clearKey(String authenticatedEmail);
}

/// Explicit dependency-injection fixture: never selected for real HTTP by
/// the production screen. The production default is private disk storage.
final class MemoryMobileAiIntentStore implements MobileAiIntentStore {
  final Map<String, String> _keys = <String, String>{};

  String _scope(String email) => email.trim().toLowerCase();

  @override
  Future<String?> readKey(String authenticatedEmail) async =>
      _keys[_scope(authenticatedEmail)];

  @override
  Future<void> saveKey(String authenticatedEmail, String key) async {
    if (!MobileAiIntentKey.isValid(key)) {
      throw const FormatException('Invalid opaque diagnosis key');
    }
    final scope = _scope(authenticatedEmail);
    final prior = _keys[scope];
    if (prior != null && prior != key) {
      throw StateError('The previous paid diagnosis intent is unresolved');
    }
    _keys[scope] = key;
  }

  @override
  Future<void> clearKey(String authenticatedEmail) async {
    _keys.remove(_scope(authenticatedEmail));
  }
}

/// Persist the key before a potentially chargeable POST. A crash or missing
/// acknowledgement cannot silently generate a new paid intent after restart.
/// Stores use an app-private support directory and a one-way account locator,
/// never raw bearer, email or diagnosis text. The caller must reauthenticate.
final class FileMobileAiIntentStore implements MobileAiIntentStore {
  const FileMobileAiIntentStore({this.rootDirectory});

  /// Tests inject an isolated temporary directory; production uses the
  /// platform application-support directory, not public downloads.
  final Future<Directory> Function()? rootDirectory;

  Future<File> _file(String email) async {
    final normalized = email.trim().toLowerCase();
    if (normalized.isEmpty || normalized.length > 254) {
      throw const FormatException('Invalid account scope');
    }
    final digest = await Sha256().hash(utf8.encode(normalized));
    final locator = digest.bytes
        .map((value) => value.toRadixString(16).padLeft(2, '0')).join();
    final base = await (rootDirectory?.call() ?? getApplicationSupportDirectory());
    final dir = Directory('${base.path}${Platform.pathSeparator}paid-ai-attempts');
    await dir.create(recursive: true);
    return File('${dir.path}${Platform.pathSeparator}$locator.json');
  }

  @override
  Future<String?> readKey(String authenticatedEmail) async {
    final file = await _file(authenticatedEmail);
    if (!await file.exists()) return null;
    if (await file.length() > 1024) {
      throw const FormatException('Corrupted private diagnosis key');
    }
    final decoded = jsonDecode(await file.readAsString());
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 2 ||
        decoded['version'] != 1 ||
        decoded['key'] is! String ||
        !MobileAiIntentKey.isValid(decoded['key'] as String)) {
      throw const FormatException('Corrupted private diagnosis key');
    }
    return decoded['key'] as String;
  }

  @override
  Future<void> saveKey(String authenticatedEmail, String key) async {
    if (!MobileAiIntentKey.isValid(key)) {
      throw const FormatException('Invalid opaque diagnosis key');
    }
    final file = await _file(authenticatedEmail);
    final prior = await readKey(authenticatedEmail);
    if (prior != null) {
      if (prior == key) return;
      throw StateError('The previous paid diagnosis intent is unresolved');
    }
    // O_EXCL is the cross-screen/process admission mutex. Two screens that
    // observe no record must NEVER replace each other's newly reserved key.
    // A crash during write leaves an invalid marker and fails CLOSED on next
    // login; no chargeable POST is issued until a complete flushed key exists.
    try {
      await file.create(exclusive: true);
    } on FileSystemException {
      final winner = await readKey(authenticatedEmail);
      if (winner == key) return;
      throw StateError('The previous paid diagnosis intent is unresolved');
    }
    await file.writeAsString(
      jsonEncode({'version': 1, 'key': key}),
      flush: true,
    );
  }

  @override
  Future<void> clearKey(String authenticatedEmail) async {
    final file = await _file(authenticatedEmail);
    if (await file.exists()) await file.delete();
  }
}

/// Snapshot is memory-only. Manual retries never send edited text with the
/// old key; after process restart the backend itself detects any mismatch.
final class MobileAiPaidAttempt {
  const MobileAiPaidAttempt({
    required this.key,
    required this.question,
    required this.technology,
    required this.codeSnippet,
  });

  final String key;
  final String question;
  final String technology;
  final String codeSnippet;
}

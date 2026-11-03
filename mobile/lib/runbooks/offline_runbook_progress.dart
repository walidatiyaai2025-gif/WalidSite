import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:cryptography/cryptography.dart';
import 'package:path_provider/path_provider.dart';

/// Local checklist state is deliberately separate from the publisher-signed
/// public pack. A caller may use 'guest', or a stable opaque 'account:<id>'
/// scope; login secrets, prompts, email addresses and raw account IDs are never
/// written. An account-scoped reader must pass its own scope explicitly.
abstract class RunbookProgressStore {
  Future<Set<int>> read({
    required String scope,
    required String packVersion,
    required String runbookId,
  });

  Future<void> write({
    required String scope,
    required String packVersion,
    required String runbookId,
    required Set<int> completed,
  });
}

class AppSupportRunbookProgressStore implements RunbookProgressStore {
  AppSupportRunbookProgressStore({Future<Directory> Function()? directory})
      : _directory = directory ?? getApplicationSupportDirectory;

  final Future<Directory> Function() _directory;
  static Future<void> _writeBarrier = Future<void>.value();
  static const _fileName = 'offline-runbook-progress-v1.json';
  static const _maxFileBytes = 96 * 1024;
  static final _scopePattern =
      RegExp(r'^(?:guest|account:[a-zA-Z0-9_-]{1,100})$');
  static final _versionPattern = RegExp(r'^[a-zA-Z0-9._-]{1,64}$');
  static final _idPattern = RegExp(r'^[a-z0-9-]{1,60}$');

  Future<String> _key(String scope, String version, String id) async {
    if (!_scopePattern.hasMatch(scope) ||
        !_versionPattern.hasMatch(version) ||
        !_idPattern.hasMatch(id)) {
      throw const FormatException('Invalid private runbook progress scope');
    }
    final data = utf8.encode([scope, version, id].join('\u0000'));
    final digest = await Sha256().hash(data);
    return base64UrlEncode(digest.bytes).replaceAll('=', '');
  }

  Future<File> _file() async {
    final directory = await _directory();
    await directory.create(recursive: true);
    return File(directory.path + Platform.pathSeparator + _fileName);
  }

  Future<Map<String, List<int>>> _readData() async {
    final file = await _file();
    if (await FileSystemEntity.type(file.path, followLinks: false) !=
        FileSystemEntityType.file) {
      return {};
    }
    try {
      if (await file.length() > _maxFileBytes) return {};
      final bytes = await file.readAsBytes();
      if (bytes.length > _maxFileBytes) return {};
      final decoded = jsonDecode(utf8.decode(bytes, allowMalformed: false));
      if (decoded is! Map<String, dynamic> ||
          decoded['schema'] != 'walidsite.local-runbook-progress.v1' ||
          decoded['entries'] is! Map<String, dynamic>) {
        return {};
      }
      final entries = decoded['entries'] as Map<String, dynamic>;
      if (entries.length > 200) return {};
      final accepted = <String, List<int>>{};
      for (final entry in entries.entries) {
        if (!RegExp(r'^[a-zA-Z0-9_-]{43}$').hasMatch(entry.key) ||
            entry.value is! List ||
            (entry.value as List).length > 50 ||
            !(entry.value as List).every(
                (index) => index is int && index >= 0 && index < 50)) {
          return {};
        }
        accepted[entry.key] =
            (entry.value as List).cast<int>().toSet().toList()..sort();
      }
      return accepted;
    } on FileSystemException {
      return {};
    } on FormatException {
      return {};
    }
  }

  @override
  Future<Set<int>> read({
    required String scope,
    required String packVersion,
    required String runbookId,
  }) async {
    final key = await _key(scope, packVersion, runbookId);
    return Set<int>.of((await _readData())[key] ?? const <int>[]);
  }

  @override
  Future<void> write({
    required String scope,
    required String packVersion,
    required String runbookId,
    required Set<int> completed,
  }) async {
    final key = await _key(scope, packVersion, runbookId);
    if (completed.length > 50 ||
        completed.any((index) => index < 0 || index >= 50)) {
      throw const FormatException('Invalid runbook step index');
    }

    // Reserve the write slot before awaiting disk: widget recreation must not
    // let an older write replace a newer checkbox change.
    final prior = _writeBarrier;
    final done = Completer<void>();
    _writeBarrier = done.future;
    try {
      await prior;
      final data = await _readData();
      if (completed.isEmpty) {
        data.remove(key);
      } else {
        if (data.length >= 200 && !data.containsKey(key)) {
          data.remove(data.keys.first);
        }
        data[key] = completed.toList()..sort();
      }
      final bytes = utf8.encode(jsonEncode({
        'schema': 'walidsite.local-runbook-progress.v1',
        'entries': data,
      }));
      if (bytes.length > _maxFileBytes) {
        throw const FormatException('Runbook progress storage full');
      }
      final file = await _file();
      final temp = File(file.path + '.part');
      try {
        await temp.writeAsBytes(bytes, flush: true);
        await temp.rename(file.path);
      } finally {
        if (await temp.exists()) await temp.delete();
      }
    } finally {
      done.complete();
    }
  }
}

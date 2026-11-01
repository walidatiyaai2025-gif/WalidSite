import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import '../articles/verified_articles.dart';

class RunbookActionHttpException implements Exception {
  const RunbookActionHttpException(this.statusCode);
  final int statusCode;
}

class RunbookSession {
  const RunbookSession({required this.token, required this.expiresAt});
  final String token;
  final DateTime? expiresAt;
}

class ReviewedRunbookActionsClient {
  const ReviewedRunbookActionsClient();

  static const String apiOrigin =
      String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const int _maxResponseBytes = 1024 * 1024;
  static final RegExp _uuid = RegExp(
    r'^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
  );

  static Uri? configuredOrigin() =>
      VerifiedArticleReader.validatedOrigin(apiOrigin);

  Future<MapEntry<int, String>> _jsonRequest(
    String method,
    String path, {
    Map<String, Object?>? body,
    String? bearer,
  }) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('API origin is not configured');
    }
    final url = origin.replace(path: path);
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request =
          await client.openUrl(method, url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
      if (bearer != null) {
        request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $bearer');
      }
      if (body != null) {
        request.add(utf8.encode(jsonEncode(body)));
      }
      final response =
          await request.close().timeout(const Duration(seconds: 8));
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > _maxResponseBytes) {
          throw const FormatException('API response too large');
        }
        bytes.add(chunk);
      }
      return MapEntry(
        response.statusCode,
        utf8.decode(bytes.takeBytes(), allowMalformed: false),
      );
    } finally {
      client.close(force: true);
    }
  }

  Future<RunbookSession> login(String email, String password) async {
    final safeEmail = email.trim();
    if (safeEmail.isEmpty ||
        safeEmail.length > 254 ||
        password.isEmpty ||
        password.length > 4096) {
      throw const FormatException('Invalid login input');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/auth/login',
      body: {
        'email': safeEmail,
        'password': password,
        'device_name': 'waleed-tech-companion',
      },
    );
    if (response.key != HttpStatus.ok) {
      throw RunbookActionHttpException(response.key);
    }
    final decoded = jsonDecode(response.value);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid login response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    const allowed = {'token', 'token_type', 'expires_at'};
    if (data.keys.toSet().difference(allowed).isNotEmpty ||
        data['token'] is! String ||
        data['token_type'] != 'Bearer' ||
        (data['token'] as String).isEmpty ||
        (data['token'] as String).length > 8192) {
      throw const FormatException('Invalid mobile token response');
    }
    final rawExpiry = data['expires_at'];
    final parsedExpiry =
        rawExpiry is String ? DateTime.tryParse(rawExpiry)?.toUtc() : null;
    if (rawExpiry != null && parsedExpiry == null) {
      throw const FormatException('Invalid mobile token expiry');
    }
    return RunbookSession(
      token: data['token'] as String,
      expiresAt: parsedExpiry,
    );
  }

  Future<void> saveRunbook(String token, String id) async {
    if (token.isEmpty || token.length > 8192 || !_uuid.hasMatch(id)) {
      throw const FormatException('Invalid saved runbook request');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/mobile/saved',
      bearer: token,
      body: {
        'resource_type': 'runbook',
        'resource_id': id,
      },
    );
    if (response.key != HttpStatus.ok) {
      throw RunbookActionHttpException(response.key);
    }
    final decoded = jsonDecode(response.value);
    if (decoded is! Map<String, dynamic> ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid save response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    if (data['saved'] != true ||
        data['resource_type'] != 'runbook' ||
        data['resource_id'] != id) {
      throw const FormatException('Invalid save confirmation');
    }
  }
}

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/account/account_privacy.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';

// Real local HTTP I/O only: no production API or real account accessed.
void main() {
  const token = 'loopback-private-token';

  test('slow-drip private export cannot extend total operation deadline', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    Timer? drip;
    var requests = 0;
    var chunks = 0;
    String? bearer;
    final listener = server.listen((request) async {
      requests++;
      bearer = request.headers.value(HttpHeaders.authorizationHeader);
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"data":"');
      try {
        await request.response.flush();
        drip = Timer.periodic(const Duration(milliseconds: 30), (timer) {
          try {
            request.response.write('x');
            chunks++;
            unawaited(request.response.flush().catchError((Object _) {}));
          } catch (_) {
            timer.cancel();
          }
        });
      } catch (_) {
        drip?.cancel();
      }
    });
    try {
      await expectLater(
        AccountPrivacyClient.boundedExchange(
          'GET', Uri(scheme: 'http', host: '127.0.0.1', port: server.port,
              path: '/api/v1/mobile/account/export'),
          token, totalTimeout: const Duration(milliseconds: 450),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1, reason: 'Never retry private export automatically');
      expect(bearer, 'Bearer $token');
      expect(chunks, greaterThan(1));
    } finally {
      drip?.cancel();
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('hanging privacy response headers have one total deadline', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) {
      requests++; // Deliberately never reply.
    });
    try {
      await expectLater(
        AccountPrivacyClient.boundedExchange(
          'GET', Uri(scheme: 'http', host: '127.0.0.1', port: server.port,
              path: '/api/v1/mobile/notification-preferences'),
          token, totalTimeout: const Duration(milliseconds: 350),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('fast consent read succeeds with bearer and exact default deny', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) async {
      requests++;
      expect(request.method, 'GET');
      expect(request.headers.value(HttpHeaders.authorizationHeader),
          'Bearer $token');
      request.response.headers.contentType = ContentType.json;
      final rows = <Map<String, Object?>>[];
      for (final purpose in AccountConsentMatrix.purposes) {
        for (final channel in AccountConsentMatrix.channels) {
          rows.add({
            'purpose': purpose, 'channel': channel, 'enabled': false,
            'consented_at': null, 'revoked_at': null,
          });
        }
      }
      request.response.write(jsonEncode({
        'data': rows, 'meta': {'default_deny': true},
      }));
      await request.response.close();
    });
    try {
      final raw = await AccountPrivacyClient.boundedExchange(
        'GET', Uri(scheme: 'http', host: '127.0.0.1', port: server.port,
            path: '/api/v1/mobile/notification-preferences'),
        token, totalTimeout: const Duration(seconds: 2),
      );
      final matrix = AccountConsentMatrix.parse(raw);
      expect(matrix.entries.length, 6);
      expect(matrix.entries.every((entry) => !entry.enabled), isTrue);
      expect(raw, isNot(contains(token)));
      expect(requests, 1);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('401, 403 and 429 fail closed with no private token or retry', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) async {
      requests++;
      request.response.statusCode = int.parse(request.uri.pathSegments.last);
      request.response.write('{"error":{"code":"request_denied"}}');
      await request.response.close();
    });
    try {
      for (final status in [
        HttpStatus.unauthorized, HttpStatus.forbidden,
        HttpStatus.tooManyRequests,
      ]) {
        await expectLater(
          AccountPrivacyClient.boundedExchange(
            'PUT', Uri(scheme: 'http', host: '127.0.0.1',
                port: server.port, path: '/$status'), token,
            payload: const {'preferences': []},
            totalTimeout: const Duration(seconds: 2),
          ),
          throwsA(isA<MobileAiHttpException>()
              .having((error) => error.statusCode, 'statusCode', status)),
        );
      }
      expect(requests, 3);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('truncated account DELETE acknowledgement cannot prove deletion', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    String? requestBody;
    final listener = server.listen((request) async {
      requests++;
      expect(request.method, 'DELETE');
      requestBody = await utf8.decoder.bind(request).join();
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"data":{"deleted":true');
      await request.response.close();
    });
    try {
      final raw = await AccountPrivacyClient.boundedExchange(
        'DELETE', Uri(scheme: 'http', host: '127.0.0.1', port: server.port,
            path: '/api/v1/mobile/account'),
        token, payload: const {
          'password': 'fixture-only', 'confirmation': 'DELETE',
        }, totalTimeout: const Duration(seconds: 2),
      );
      expect(() => jsonDecode(raw), throwsFormatException);
      expect(requestBody, contains('"confirmation":"DELETE"'));
      expect(raw, isNot(contains(token)));
      expect(requests, 1, reason: 'Never retry an ambiguous DELETE');
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('invalid UTF-8 and over-limit response are both rejected', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final listener = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path == '/invalid') {
        request.response.add([0xC3, 0x28]);
      } else {
        request.response.write('x' * (2 * 1024 * 1024 + 1));
      }
      try {
        await request.response.close();
      } catch (_) {
        // The client closes a deliberately over-limit socket.
      }
    });
    try {
      for (final path in ['/invalid', '/oversize']) {
        await expectLater(
          AccountPrivacyClient.boundedExchange(
            'GET', Uri(scheme: 'http', host: '127.0.0.1',
                port: server.port, path: path), token,
            totalTimeout: const Duration(seconds: 4),
          ),
          throwsFormatException,
        );
      }
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('invalid token and deadline reject before any network', () async {
    await expectLater(
      AccountPrivacyClient.boundedExchange(
        'GET', Uri.parse('http://127.0.0.1:1/blocked'), '',
      ),
      throwsFormatException,
    );
    await expectLater(
      AccountPrivacyClient.boundedExchange(
        'DELETE', Uri.parse('http://127.0.0.1:1/blocked'), token,
        totalTimeout: Duration.zero,
      ),
      throwsFormatException,
    );
  });
}

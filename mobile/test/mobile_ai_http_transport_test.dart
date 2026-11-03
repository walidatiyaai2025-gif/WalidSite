import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';

// A real loopback HTTP server exercises the same production read path as
// login/diagnosis/status/logout. No API origin, credentials or paid AI provider
// is contacted. These are network transport tests, not device screenshots.
void main() {
  test('slow-drip authenticated diagnosis cannot extend the total deadline',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    Timer? drip;
    var requests = 0;
    var chunks = 0;
    String? observedBearer;
    final listener = server.listen((request) async {
      requests++;
      observedBearer = request.headers.value(HttpHeaders.authorizationHeader);
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"data":"');
      try {
        await request.response.flush();
        drip = Timer.periodic(const Duration(milliseconds: 35), (timer) {
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
        MobileAiClient.boundedExchange(
          'POST',
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/ai/diagnose'),
          body: const {
            'question': 'safe fixture',
            'technology': 'Flutter',
            'code_snippet': '',
            'consent_to_process': true,
          },
          bearer: 'loopback-only-test-bearer',
          totalTimeout: const Duration(milliseconds: 450),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1, reason: 'Never retry a paid diagnosis POST');
      expect(observedBearer, 'Bearer loopback-only-test-bearer');
      expect(chunks, greaterThan(1),
          reason: 'The server must send repeated chunks before the deadline');
    } finally {
      drip?.cancel();
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('hanging response headers share the same whole-request deadline',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) {
      requests++;
      // Deliberately never send HTTP response headers or close the body.
      // Closing the server in finally cleans the fixture connection.
    });
    try {
      await expectLater(
        MobileAiClient.boundedExchange(
          'GET',
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/ai/jobs/fixture'),
          totalTimeout: const Duration(milliseconds: 300),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('HTTP 401/403 remain explicit, with no automatic redirect or retry',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) async {
      requests++;
      request.response.statusCode =
          request.uri.path.endsWith('/denied')
              ? HttpStatus.forbidden
              : HttpStatus.unauthorized;
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"error":{"code":"not_authorized"}}');
      await request.response.close();
    });

    try {
      for (final status in [HttpStatus.unauthorized, HttpStatus.forbidden]) {
        final url = status == HttpStatus.forbidden ? 'denied' : 'unauthorized';
        final result = await MobileAiClient.boundedExchange(
          'GET',
          Uri.parse('http://127.0.0.1:${server.port}/$url'),
          bearer: 'private-fixture-token',
          totalTimeout: const Duration(seconds: 2),
        );
        expect(result.key, status);
        expect(result.value, contains('not_authorized'));
        expect(result.value, isNot(contains('private-fixture-token')));
      }
      expect(requests, 2, reason: 'Do not retry failed authenticated calls');
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('oversized streaming JSON is rejected rather than held in memory',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final listener = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      request.response.write('x' * (1024 * 1024 + 1));
      try {
        await request.response.close();
      } catch (_) {
        // The client is expected to terminate this over-limit connection.
      }
    });
    try {
      await expectLater(
        MobileAiClient.boundedExchange(
          'GET',
          Uri.parse('http://127.0.0.1:${server.port}/too-large'),
          totalTimeout: const Duration(seconds: 4),
        ),
        throwsFormatException,
      );
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });
}

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/reviewed_runbook_actions.dart';

/// Loopback fixtures exercise the same HttpClient path used by production
/// Login/Save, without any credential, paid API, or external endpoint.
void main() {
  test('slow-drip Save POST obeys a single total deadline and never retries',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    Timer? drip;
    var requests = 0;
    var chunks = 0;
    String? observedAuth;
    final listener = server.listen((request) async {
      requests++;
      observedAuth = request.headers.value(HttpHeaders.authorizationHeader);
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"data":');
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
    });
    try {
      await expectLater(
        ReviewedRunbookActionsClient.boundedExchange(
          'POST',
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/saved'),
          body: const {
            'resource_type': 'runbook',
            'resource_id': '00000000-0000-4000-8000-000000000777',
          },
          bearer: 'local-fixture-bearer',
          totalTimeout: const Duration(milliseconds: 450),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
      expect(chunks, greaterThan(1));
      expect(observedAuth, 'Bearer local-fixture-bearer');
    } finally {
      drip?.cancel();
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('hanging Login response headers cannot extend the total deadline',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) {
      requests++;
      // No headers or body: connection stays waiting until timeout.
    });
    try {
      await expectLater(
        ReviewedRunbookActionsClient.boundedExchange(
          'POST',
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/auth/login'),
          body: const {'email': 'user@example.test', 'password': 'fixture'},
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

  test('interrupted mid-body response fails without a second POST', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) async {
      requests++;
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"data":');
      await request.response.flush();
      await server.close(force: true);
    });
    try {
      await expectLater(
        ReviewedRunbookActionsClient.boundedExchange(
          'POST',
          Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/saved'),
          bearer: 'private-fixture',
          body: const {'resource_type': 'runbook'},
          totalTimeout: const Duration(seconds: 2),
        ),
        throwsA(isA<Exception>()),
      );
      expect(requests, 1);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('oversized response and invalid UTF-8 are rejected', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final listener = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path == '/oversize') {
        request.response.write('x' * (1024 * 1024 + 1));
      } else {
        request.response.add([0xC3, 0x28]);
      }
      try {
        await request.response.close();
      } catch (_) {
        // The client must abort an oversized body.
      }
    });
    try {
      for (final route in ['oversize', 'bad-utf8']) {
        await expectLater(
          ReviewedRunbookActionsClient.boundedExchange(
            'GET',
            Uri.parse('http://127.0.0.1:${server.port}/$route'),
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

  test('401/403/429 remain explicit and never retry or leak a bearer', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final listener = server.listen((request) async {
      requests++;
      request.response.statusCode = int.parse(
        request.uri.pathSegments.last,
      );
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"error":{"code":"request_rejected"}}');
      await request.response.close();
    });
    try {
      for (final code in [401, 403, 429]) {
        final result = await ReviewedRunbookActionsClient.boundedExchange(
          'POST',
          Uri.parse('http://127.0.0.1:${server.port}/$code'),
          bearer: 'never-expose-this-token',
          totalTimeout: const Duration(seconds: 3),
        );
        expect(result.key, code);
        expect(result.value, contains('request_rejected'));
        expect(result.value, isNot(contains('never-expose-this-token')));
      }
      expect(requests, 3);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

  test('successful Login and Save requests serialize exact public DTOs',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final paths = <String>[];
    final listener = server.listen((request) async {
      paths.add(request.uri.path);
      expect(request.method, 'POST');
      final payload = jsonDecode(await utf8.decoder.bind(request).join())
          as Map<String, dynamic>;
      if (request.uri.path == '/api/v1/auth/login') {
        expect(payload['email'], 'reader@example.test');
        expect(request.headers.value(HttpHeaders.authorizationHeader), isNull);
      } else {
        expect(payload['resource_type'], 'runbook');
        expect(request.headers.value(HttpHeaders.authorizationHeader),
            'Bearer fixture-token');
      }
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"data":{"saved":true}}');
      await request.response.close();
    });
    try {
      final login = await ReviewedRunbookActionsClient.boundedExchange(
        'POST',
        Uri.parse('http://127.0.0.1:${server.port}/api/v1/auth/login'),
        body: const {
          'email': 'reader@example.test',
          'password': 'fixture-password',
          'device_name': 'waleed-tech-companion',
        },
        totalTimeout: const Duration(seconds: 3),
      );
      final save = await ReviewedRunbookActionsClient.boundedExchange(
        'POST',
        Uri.parse('http://127.0.0.1:${server.port}/api/v1/mobile/saved'),
        bearer: 'fixture-token',
        body: const {
          'resource_type': 'runbook',
          'resource_id': '00000000-0000-4000-8000-000000000777',
        },
        totalTimeout: const Duration(seconds: 3),
      );
      expect(login.key, HttpStatus.ok);
      expect(save.key, HttpStatus.ok);
      expect(paths, ['/api/v1/auth/login', '/api/v1/mobile/saved']);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });
}

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/diagnosis_report.dart';

// A real disposable loopback HttpServer. No production contacts, email
// transport, user credentials, private AI prompt, or job metadata are sent.
void main() {
  DiagnosisReportInput report() => DiagnosisReportInput(
    name: 'Test Reader',
    email: 'reader@example.org',
    message: 'A reviewed example was not clear enough.',
    consent: true,
  );

  test('slow-drip report receipt cannot extend one total deadline', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    Timer? drip;
    var requests = 0;
    var chunks = 0;
    String? observedBody;
    String? bearer;
    final subscription = server.listen((request) async {
      requests++;
      bearer = request.headers.value(HttpHeaders.authorizationHeader);
      observedBody = await utf8.decoder.bind(request).join();
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"ok":true,"data":{"accepted_by_transport":');
      try {
        await request.response.flush();
        drip = Timer.periodic(const Duration(milliseconds: 30), (timer) {
          try {
            request.response.write(' ');
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
        DiagnosisReportClient.boundedSubmit(
          report(),
          Uri(scheme: 'http', host: '127.0.0.1', port: server.port,
              path: '/api/v1/public/contact'),
          totalTimeout: const Duration(milliseconds: 450),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1, reason: 'No second consented POST on timeout');
      expect(chunks, greaterThan(1));
      expect(bearer, isNull, reason: 'Contact report must not attach a bearer');
      expect(jsonDecode(observedBody!)['privacy_consent'], true);
      expect(observedBody, isNot(contains('diagnosis_job_id')));
      expect(observedBody, isNot(contains('code_snippet')));
    } finally {
      drip?.cancel();
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('headers that never arrive obey the same whole-request deadline', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final subscription = server.listen((request) {
      requests++; // Hold headers indefinitely; close fixture in finally.
    });
    try {
      await expectLater(
        DiagnosisReportClient.boundedSubmit(
          report(), Uri(scheme: 'http', host: '127.0.0.1',
              port: server.port, path: '/api/v1/public/contact'),
          totalTimeout: const Duration(milliseconds: 350),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
    } finally {
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('valid actual HTTP receipt succeeds once and uses contact-only body',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final subscription = server.listen((request) async {
      requests++;
      expect(request.method, 'POST');
      expect(request.headers.value(HttpHeaders.authorizationHeader), isNull);
      final body = jsonDecode(await utf8.decoder.bind(request).join());
      expect(body.keys.toSet(),
          {'name', 'email', 'message', 'privacy_consent'});
      expect(body['privacy_consent'], true);
      request.response.headers.contentType = ContentType.json;
      request.response.write(
          '{"ok":true,"data":{"accepted_by_transport":true}}');
      await request.response.close();
    });
    try {
      await DiagnosisReportClient.boundedSubmit(
        report(), Uri(scheme: 'http', host: '127.0.0.1',
            port: server.port, path: '/api/v1/public/contact'),
        totalTimeout: const Duration(seconds: 2),
      );
      expect(requests, 1);
    } finally {
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('redirect and 429 never trigger alternate post or automatic retry',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final subscription = server.listen((request) async {
      requests++;
      request.response.statusCode = request.uri.path == '/redirect'
          ? HttpStatus.found : HttpStatus.tooManyRequests;
      if (request.uri.path == '/redirect') {
        request.response.headers.set(
            HttpHeaders.locationHeader, 'https://untrusted.example.org/collect');
      }
      await request.response.close();
    });
    try {
      for (final status in [HttpStatus.found, HttpStatus.tooManyRequests]) {
        final path = status == HttpStatus.found ? '/redirect' : '/rate-limit';
        await expectLater(
          DiagnosisReportClient.boundedSubmit(
            report(), Uri(scheme: 'http', host: '127.0.0.1',
                port: server.port, path: path),
            totalTimeout: const Duration(seconds: 2),
          ),
          throwsA(isA<DiagnosisReportHttpException>()
              .having((error) => error.statusCode, 'statusCode', status)),
        );
      }
      expect(requests, 2);
    } finally {
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('HTTP 200 truncated and false receipts cannot claim acceptance',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var requests = 0;
    final subscription = server.listen((request) async {
      requests++;
      request.response.headers.contentType = ContentType.json;
      request.response.write(request.uri.path == '/truncated'
          ? '{"ok":true,"data":'
          : '{"ok":true,"data":{"accepted_by_transport":false}}');
      await request.response.close();
    });
    try {
      for (final path in ['/truncated', '/not-accepted']) {
        await expectLater(
          DiagnosisReportClient.boundedSubmit(
            report(), Uri(scheme: 'http', host: '127.0.0.1',
                port: server.port, path: path),
            totalTimeout: const Duration(seconds: 2),
          ),
          throwsFormatException,
        );
      }
      expect(requests, 2, reason: 'Never retry an unconfirmed contact POST');
    } finally {
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('oversized response and malformed UTF-8 receipts fail closed', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final subscription = server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      if (request.uri.path == '/bad-utf8') {
        request.response.add([0xC3, 0x28]);
      } else {
        request.response.write('x' * 4097);
      }
      try {
        await request.response.close();
      } catch (_) {
        // Client deliberately terminates the oversized stream.
      }
    });
    try {
      for (final path in ['/bad-utf8', '/oversize']) {
        await expectLater(
          DiagnosisReportClient.boundedSubmit(
            report(), Uri(scheme: 'http', host: '127.0.0.1',
                port: server.port, path: path),
            totalTimeout: const Duration(seconds: 2),
          ),
          throwsFormatException,
        );
      }
    } finally {
      await subscription.cancel();
      await server.close(force: true);
    }
  });

  test('invalid total deadline fails before network', () async {
    await expectLater(
      DiagnosisReportClient.boundedSubmit(
        report(), Uri.parse('http://127.0.0.1:1/unreachable'),
        totalTimeout: Duration.zero,
      ),
      throwsFormatException,
    );
  });
}

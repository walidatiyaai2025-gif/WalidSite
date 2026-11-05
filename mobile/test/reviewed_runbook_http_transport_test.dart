import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

// This file deliberately uses plain test(), not testWidgets(), so that the
// Flutter widget binding does not replace real localhost HttpClient sockets
// with its synthetic 400-response test client.
void main() {
  test('real slow-drip reviewed HTTP body has a single total deadline',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final stopped = Completer<void>();
    var requests = 0;
    server.listen((request) async {
      requests++;
      try {
        request.response.headers.contentType = ContentType.json;
        request.response.write('{"data":');
        await request.response.flush();
        while (!stopped.isCompleted) {
          await Future<void>.delayed(const Duration(milliseconds: 20));
          if (stopped.isCompleted) break;
          request.response.write(' ');
          await request.response.flush();
        }
      } catch (_) {
        // Client force-close is expected on expiry.
      }
    });
    try {
      final url = Uri.parse('http://127.0.0.1:${server.port}/guide');
      await expectLater(
        ReviewedRunbookReader.fetchHttp(
          url, timeout: const Duration(milliseconds: 250),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
    } finally {
      stopped.complete();
      await server.close(force: true);
    }
  });

  test('real reviewed HTTP request rejects hanging headers', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final release = Completer<void>();
    var requests = 0;
    server.listen((request) async {
      requests++;
      await release.future;
      try {
        await request.response.close();
      } catch (_) {
        // Deadline closed the peer.
      }
    });
    try {
      final url = Uri.parse('http://127.0.0.1:${server.port}/guide');
      await expectLater(
        ReviewedRunbookReader.fetchHttp(
          url, timeout: const Duration(milliseconds: 250),
        ),
        throwsA(isA<TimeoutException>()),
      );
      expect(requests, 1);
    } finally {
      release.complete();
      await server.close(force: true);
    }
  });

  test('real quick reviewed HTTP response reads exact UTF-8 bytes', () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    server.listen((request) async {
      request.response.headers.contentType = ContentType.json;
      request.response.write('{"message":"reviewed"}');
      await request.response.close();
    });
    try {
      final url = Uri.parse('http://127.0.0.1:${server.port}/guide');
      final result = await ReviewedRunbookReader.fetchHttp(
        url, timeout: const Duration(seconds: 2),
      );
      expect(result, '{"message":"reviewed"}');
    } finally {
      await server.close(force: true);
    }
  });

  test('real redirect cannot silently fetch an unapproved destination',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    var leakedRequests = 0;
    server.listen((request) async {
      if (request.uri.path == '/secret') {
        leakedRequests++;
        request.response.write('PRIVATE DATA');
      } else {
        request.response.statusCode = HttpStatus.found;
        request.response.headers.set(HttpHeaders.locationHeader, '/secret');
      }
      await request.response.close();
    });
    try {
      final url = Uri.parse('http://127.0.0.1:${server.port}/redirect');
      await expectLater(
        ReviewedRunbookReader.fetchHttp(
          url, timeout: const Duration(seconds: 2),
        ),
        throwsA(isA<HttpException>()),
      );
      expect(leakedRequests, 0);
    } finally {
      await server.close(force: true);
    }
  });
}

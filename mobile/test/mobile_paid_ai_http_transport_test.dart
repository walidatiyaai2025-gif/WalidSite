import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/ai/mobile_ai_paid_intent.dart';

const _jobId = '11111111-1111-4111-8111-111111111111';

/// This separate test suite intentionally uses ONLY test(), not testWidgets:
/// TestWidgetsFlutterBinding overrides all HttpClient requests with HTTP 400.
void main() {
  test('manual lost-ack HTTP retry sends the exact same opaque header',
      () async {
    final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    final observed = <String?>[];
    final listener = server.listen((request) async {
      observed.add(request.headers.value('Idempotency-Key'));
      request.response.headers.contentType = ContentType.json;
      if (observed.length == 1) {
        // Simulate a request admitted by a backend, but no final acknowledgement
        // before the client wall-clock deadline. Never call a paid provider.
        request.response.write('{"data":');
        await request.response.flush();
      } else {
        request.response.write('{"data":{"id":"$_jobId"}}');
        await request.response.close();
      }
    });
    try {
      final key = MobileAiIntentKey.generate();
      final url = Uri.parse(
          'http://127.0.0.1:${server.port}/api/v1/mobile/ai/diagnose');
      Future<MapEntry<int, String>> send(Duration deadline) =>
          MobileAiClient.boundedExchange(
            'POST',
            url,
            bearer: 'private-test-bearer',
            idempotencyKey: key,
            body: const {'question': 'safe test', 'consent_to_process': true},
            totalTimeout: deadline,
          );
      await expectLater(
        send(const Duration(milliseconds: 350)),
        throwsA(isA<TimeoutException>()),
      );
      expect(observed, [key]); // No automatic second paid POST.
      final result = await send(const Duration(seconds: 2));
      expect(result.key, HttpStatus.ok);
      expect(result.value, contains(_jobId));
      expect(observed, [key, key]);
    } finally {
      await listener.cancel();
      await server.close(force: true);
    }
  });

}

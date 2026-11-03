import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/signed_runbook_sync.dart';

class _Headers implements HttpHeaders {
  @override
  void set(String name, Object value, {bool preserveHeaderCase = false}) {}
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Response implements HttpClientResponse {
  _Response(this.body, {this.statusCode = HttpStatus.ok});
  final Stream<List<int>> body;
  @override
  final int statusCode;

  @override
  StreamSubscription<List<int>> listen(
    void Function(List<int>)? onData, {
    Function? onError,
    void Function()? onDone,
    bool? cancelOnError,
  }) => body.listen(onData, onError: onError, onDone: onDone,
      cancelOnError: cancelOnError);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Request implements HttpClientRequest {
  _Request(this.reply);
  final Future<HttpClientResponse> reply;
  @override
  bool followRedirects = true;
  @override
  final HttpHeaders headers = _Headers();

  @override
  Future<HttpClientResponse> close() => reply;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Client implements HttpClient {
  _Client(this.request);
  final _Request request;
  bool forciblyClosed = false;
  int requests = 0;
  Uri? requestedUri;

  @override
  Duration? connectionTimeout;

  @override
  Future<HttpClientRequest> getUrl(Uri url) async {
    requests++;
    requestedUri = url;
    return request;
  }

  @override
  void close({bool force = false}) {
    if (force) forciblyClosed = true;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  // Deterministic in-process streaming HttpClient fixture exercises the
  // *production* getUrl/headers/body path, without insecure loopback HTTPS
  // exceptions or any real outbound call to a private host.
  SignedRunbookSource source(_Client client, {Duration? deadline}) =>
      SignedRunbookSource(
        url: 'https://docs.example.org/packs/signed.json',
        publicKeyBase64: base64Encode(List<int>.filled(32, 7)),
        httpClientFactory: () => client,
        totalTimeout: deadline ?? const Duration(milliseconds: 80),
      );

  test('slow-drip stream cannot reset signed pack total deadline', () async {
    final periodic = Stream<List<int>>.periodic(
      const Duration(milliseconds: 12), (_) => const [123],
    ).take(1000);
    final client = _Client(_Request(Future.value(_Response(periodic))));
    final stopwatch = Stopwatch()..start();
    await expectLater(source(client).fetch(), throwsA(isA<TimeoutException>()));
    stopwatch.stop();
    expect(stopwatch.elapsed, lessThan(const Duration(seconds: 2)));
    expect(client.requests, 1);
    expect(client.requestedUri!.scheme, 'https');
    expect(client.request.followRedirects, isFalse);
    expect(client.forciblyClosed, isTrue);
  });

  test('hanging headers are terminated by the same deadline', () async {
    final never = Completer<HttpClientResponse>();
    final client = _Client(_Request(never.future));
    await expectLater(source(client).fetch(), throwsA(isA<TimeoutException>()));
    expect(client.forciblyClosed, isTrue);
    expect(client.requests, 1);
  });

  test('socket break, oversized body and invalid UTF-8 reject update', () async {
    final cases = <Stream<List<int>>>[
      Stream<List<int>>.error(const SocketException('fixture disconnect')),
      Stream<List<int>>.value(List<int>.filled(800 * 1024 + 1, 0x61)),
      Stream<List<int>>.value(const [0xc3, 0x28]),
    ];
    final matchers = [
      isA<SocketException>(),
      isA<FormatException>(),
      isA<FormatException>(),
    ];
    for (var i = 0; i < cases.length; i++) {
      final client = _Client(_Request(Future.value(_Response(cases[i]))));
      await expectLater(source(client).fetch(), throwsA(matchers[i]));
      expect(client.forciblyClosed, isTrue);
    }
  });

  test('redirects reject instead of downloading from a different host', () async {
    final client = _Client(_Request(Future.value(_Response(
      Stream<List<int>>.value(utf8.encode('moved')),
      statusCode: HttpStatus.found,
    ))));
    await expectLater(source(client).fetch(), throwsA(isA<HttpException>()));
    expect(client.request.followRedirects, isFalse);
    expect(client.forciblyClosed, isTrue);
  });

  test('small valid HTTP 200 body is read and client always closed', () async {
    final client = _Client(_Request(Future.value(_Response(
      Stream<List<int>>.fromIterable([utf8.encode('{'), utf8.encode('}')]),
    ))));
    expect(await source(client).fetch(), '{}');
    expect(client.forciblyClosed, isTrue);
    expect(client.connectionTimeout, const Duration(seconds: 8));
  });
}

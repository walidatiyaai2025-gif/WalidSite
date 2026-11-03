import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';

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
  }) => body.listen(
    onData,
    onError: onError,
    onDone: onDone,
    cancelOnError: cancelOnError,
  );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Request implements HttpClientRequest {
  _Request(this.response);
  final Future<HttpClientResponse> response;

  @override
  bool followRedirects = true;

  @override
  final HttpHeaders headers = _Headers();

  @override
  Future<HttpClientResponse> close() => response;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Client implements HttpClient {
  _Client(this.request);
  final _Request request;
  int requests = 0;
  bool forciblyClosed = false;
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

  const article = '''
    {"slug":"database-recovery","title":"Database recovery",
    "body_markdown":"Verified published body",
    "citations":["https://docs.example.org/recovery"],
    "tested_status":"documented","published_at":"2026-10-31T18:00:00Z"}
  ''';
  final indexJson = '{"meta":{"current_page":1,"has_more_pages":false},'
      '"data":[$article]}';
  final detailJson = '{"data":$article}';

  VerifiedArticleReader source(_Client client,
          {Duration deadline = const Duration(milliseconds: 85),
          String origin = 'https://articles.example.org'}) =>
      VerifiedArticleReader.fixture(
        httpClientFactory: () => client,
        publicOrigin: origin,
        wholeDeadline: deadline,
      );

  test('slow-drip response cannot reset production article page deadline', () async {
    final body = Stream<List<int>>.periodic(
      const Duration(milliseconds: 12), (_) => const [123],
    ).take(1000);
    final client = _Client(_Request(Future.value(_Response(body))));
    await expectLater(source(client).loadPage(false, 1),
        throwsA(isA<TimeoutException>()));
    expect(client.requests, 1);
    expect(client.requestedUri!.scheme, 'https');
    expect(client.requestedUri!.path, '/api/v1/public/articles');
    expect(client.request.followRedirects, isFalse);
    expect(client.forciblyClosed, isTrue);
    expect(client.connectionTimeout, const Duration(seconds: 8));
  });

  test('hanging response headers use the same total deadline', () async {
    final never = Completer<HttpClientResponse>();
    final client = _Client(_Request(never.future));
    await expectLater(source(client).loadPage(true, 1),
        throwsA(isA<TimeoutException>()));
    expect(client.requestedUri!.queryParameters['lang'], 'ar');
    expect(client.requests, 1);
    expect(client.forciblyClosed, isTrue);
  });

  test('disconnect, oversized response and malformed UTF-8 fail closed', () async {
    final responses = <Stream<List<int>>>[
      Stream<List<int>>.error(const SocketException('fixture disconnect')),
      Stream<List<int>>.value(List<int>.filled(2 * 1024 * 1024 + 1, 0x61)),
      Stream<List<int>>.value(const [0xc3, 0x28]),
    ];
    final expected = <Matcher>[
      isA<SocketException>(),
      isA<FormatException>(),
      isA<FormatException>(),
    ];
    for (var i = 0; i < responses.length; i++) {
      final client = _Client(_Request(Future.value(_Response(responses[i]))));
      await expectLater(source(client).loadPage(false, 1),
          throwsA(expected[i]));
      expect(client.requests, 1);
      expect(client.forciblyClosed, isTrue);
    }
  });

  test('302, 429 and server errors never redirect or retry', () async {
    for (final status in [HttpStatus.found, 429, 500]) {
      final client = _Client(_Request(Future.value(_Response(
        const Stream<List<int>>.empty(), statusCode: status,
      ))));
      await expectLater(source(client).loadPage(false, 1),
          throwsA(isA<HttpException>()));
      expect(client.requests, 1);
      expect(client.request.followRedirects, isFalse);
      expect(client.forciblyClosed, isTrue);
    }
  });

  test('valid reviewed index and exact detail use safe public GET only', () async {
    final index = _Client(_Request(Future.value(_Response(
      Stream<List<int>>.value(utf8.encode(indexJson)),
    ))));
    final page = await source(index).loadPage(false, 1);
    expect(page.articles.single.slug, 'database-recovery');
    expect(index.requestedUri!.queryParameters['page'], '1');
    expect(index.forciblyClosed, isTrue);

    final detail = _Client(_Request(Future.value(_Response(
      Stream<List<int>>.value(utf8.encode(detailJson)),
    ))));
    final publication = await source(detail).loadDetail(false, 'database-recovery');
    expect(publication.body, 'Verified published body');
    expect(detail.requestedUri!.path,
        '/api/v1/public/articles/database-recovery');
    expect(detail.forciblyClosed, isTrue);
  });

  test('revoked and mismatched fresh detail never displays stale data', () async {
    final withdrawn = _Client(_Request(Future.value(_Response(
      const Stream<List<int>>.empty(),
      statusCode: HttpStatus.notFound,
    ))));
    await expectLater(
      source(withdrawn).loadDetail(false, 'database-recovery'),
      throwsA(isA<HttpException>()),
    );
    expect(withdrawn.requests, 1);
    expect(withdrawn.forciblyClosed, isTrue);

    final mismatched = _Client(_Request(Future.value(_Response(
      Stream<List<int>>.value(utf8.encode(detailJson)),
    ))));
    await expectLater(
      source(mismatched).loadDetail(false, 'different-slug'),
      throwsA(isA<FormatException>()),
    );
    expect(mismatched.forciblyClosed, isTrue);
  });

  test('test injection still refuses unsafe origins before any request', () async {
    final client = _Client(_Request(Future.value(_Response(
      Stream<List<int>>.value(utf8.encode(indexJson)),
    ))));
    await expectLater(
      source(client, origin: 'http://127.0.0.1').loadPage(false, 1),
      throwsA(isA<FormatException>()),
    );
    expect(client.requests, 0);
  });
}

import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/ai/mobile_ai_paid_intent.dart';

const _jobId = '11111111-1111-4111-8111-111111111111';
const _token = '1|private-widget-test-token';

void main() {
  test('opaque key is UUIDv4, unique and independent of secret prompt', () {
    final first = MobileAiIntentKey.generate();
    final second = MobileAiIntentKey.generate();
    expect(MobileAiIntentKey.isValid(first), isTrue);
    expect(MobileAiIntentKey.isValid(second), isTrue);
    expect(first, isNot(second));
    expect(first, isNot(contains('private')));
    expect(MobileAiIntentKey.isValid('invalid'), isFalse);
  });

  test('private durable key survives restart without a prompt, bearer or email',
      () async {
    final root = await Directory.systemTemp.createTemp('paid-intent-fixture-');
    final store = FileMobileAiIntentStore(rootDirectory: () async => root);
    final secondProcess = FileMobileAiIntentStore(rootDirectory: () async => root);
    final key = MobileAiIntentKey.generate();
    try {
      await store.saveKey('Reader@Example.Test', key);
      expect(await secondProcess.readKey('reader@example.test'), key);
      expect(await secondProcess.readKey('another@example.test'), isNull);
      await expectLater(
        secondProcess.saveKey('reader@example.test',
            MobileAiIntentKey.generate()),
        throwsStateError,
      );
      final files = await Directory('${root.path}${Platform.pathSeparator}paid-ai-attempts')
          .list().toList();
      expect(files.length, 1);
      final raw = await File(files.single.path).readAsString();
      expect(raw, contains(key));
      expect(raw, isNot(contains('Reader@Example.Test')));
      expect(raw, isNot(contains('question')));
      expect(raw, isNot(contains(_token)));
      await secondProcess.clearKey('reader@example.test');
      expect(await store.readKey('reader@example.test'), isNull);
    } finally {
      await root.delete(recursive: true);
    }
  });

  test('two concurrent screens cannot create different paid keys',
      () async {
    final root = await Directory.systemTemp.createTemp('paid-key-race-');
    final first = FileMobileAiIntentStore(rootDirectory: () async => root);
    final second = FileMobileAiIntentStore(rootDirectory: () async => root);
    final keyA = MobileAiIntentKey.generate();
    final keyB = MobileAiIntentKey.generate();
    try {
      final outcomes = await Future.wait<bool>([
        first.saveKey('owner@example.test', keyA)
            .then((_) => true).catchError((Object _) => false),
        second.saveKey('owner@example.test', keyB)
            .then((_) => true).catchError((Object _) => false),
      ]);
      expect(outcomes.where((success) => success).length, 1);
      expect(await first.readKey('owner@example.test'), anyOf(keyA, keyB));
      await first.clearKey('owner@example.test');
    } finally {
      await root.delete(recursive: true);
    }
  });


  Future<void> reveal(WidgetTester tester, Key key) async {
    final mainScroll = find.descendant(
      of: find.byType(ListView),
      matching: find.byType(Scrollable),
    ).first;
    await tester.scrollUntilVisible(
      find.byKey(key),
      220,
      scrollable: mainScroll,
    );
    await tester.ensureVisible(find.byKey(key));
    await tester.pumpAndSettle();
  }

  Future<void> revealAbove(WidgetTester tester, Key key) async {
    final mainScroll = find.descendant(
      of: find.byType(ListView),
      matching: find.byType(Scrollable),
    ).first;
    await tester.scrollUntilVisible(
      find.byKey(key),
      -220,
      scrollable: mainScroll,
    );
    await tester.ensureVisible(find.byKey(key));
    await tester.pumpAndSettle();
  }

  Future<void> signInAndFill(
    WidgetTester tester, {
    required String question,
  }) async {
    await tester.enterText(
      find.byKey(const Key('ai-login-email')),
      'reader@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('ai-login-password')),
      'not-a-real-password',
    );
    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('ai-technology')),
      'Flutter',
    );
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      question,
    );
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await tester.pumpAndSettle();
  }

  testWidgets('lost paid POST ACK permits only manual same-key same-body retry',
      (tester) async {
    final store = MemoryMobileAiIntentStore();
    final keys = <String>[];
    final questions = <String>[];
    var calls = 0;

    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        intentStore: store,
        loginAction: (_, __) async =>
            const MobileAiSession(token: _token, expiresAt: null),
        keyedDiagnoseAction: (token, question, technology, code, key) async {
          expect(token, _token);
          expect(technology, 'Flutter');
          keys.add(key);
          questions.add(question);
          calls++;
          if (calls == 1) {
            throw TimeoutException('secret downstream response');
          }
          return const MobileAiJob(
            id: _jobId,
            status: 'completed',
            progressPercent: 100,
            resultUrl: null,
            retryAfterSeconds: null,
          );
        },
      ),
    ));
    await signInAndFill(tester, question: 'Original consented diagnosis');
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(calls, 1);
    expect(await store.readKey('reader@example.test'), keys.single);
    expect(find.textContaining('outcome is unknown'), findsOneWidget);
    expect(find.textContaining('secret downstream'), findsNothing);
    expect(find.text('Recover previous diagnosis'), findsOneWidget);

    // Even if the form has been edited, a manual retry keeps the original
    // snapshot and the same server idempotency key.
    await revealAbove(tester, const Key('ai-question'));
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'Different question must never replace in-flight intent',
    );
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(keys[0], keys[1]);
    expect(questions, [
      'Original consented diagnosis',
      'Original consented diagnosis',
    ]);
    expect(await store.readKey('reader@example.test'), isNull);
    expect(find.textContaining('secret downstream'), findsNothing);
  });

  testWidgets('Arabic re-login after process recreation recovers same key',
      (tester) async {
    final store = MemoryMobileAiIntentStore();
    final keys = <String>[];
    var calls = 0;

    Widget buildScreen() => MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: true,
        intentStore: store,
        loginAction: (_, __) async =>
            const MobileAiSession(token: _token, expiresAt: null),
        keyedDiagnoseAction: (token, question, technology, code, key) async {
          keys.add(key);
          calls++;
          if (calls == 1) throw TimeoutException('unacknowledged');
          return const MobileAiJob(
            id: _jobId,
            status: 'completed',
            progressPercent: 100,
            resultUrl: null,
            retryAfterSeconds: null,
          );
        },
      ),
    );
    await tester.pumpWidget(buildScreen());
    await signInAndFill(tester, question: 'First request in Arabic');
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(await store.readKey('reader@example.test'), keys.single);

    // Dispose old screen to simulate relaunch; only the key survives.
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpWidget(buildScreen());
    await signInAndFill(tester, question: 'First request in Arabic');
    expect(find.textContaining('قد يكون طلب تشخيص سابق'), findsOneWidget);
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(keys[0], keys[1]);
    expect(await store.readKey('reader@example.test'), isNull);
  });

  testWidgets('HTTP 409 keeps the key, never claims paid success',
      (tester) async {
    final store = MemoryMobileAiIntentStore();
    final keys = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        intentStore: store,
        loginAction: (_, __) async =>
            const MobileAiSession(token: _token, expiresAt: null),
        keyedDiagnoseAction: (_, __, ___, ____, key) async {
          keys.add(key);
          throw const MobileAiHttpException(409);
        },
      ),
    ));
    await signInAndFill(tester, question: 'A real technical question');
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(find.textContaining('differs from the previous attempt'),
        findsOneWidget);
    expect(await store.readKey('reader@example.test'), keys.single);
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(keys, [keys.first, keys.first]);
  });
}

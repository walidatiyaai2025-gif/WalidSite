import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';

void main() {
  const bearer = '1|private-test-bearer';
  const jobId = '11111111-1111-4111-8111-111111111111';
  const solutionId = '22222222-2222-4222-8222-222222222222';

  Future<void> reveal(WidgetTester tester, String key) async {
    await tester.scrollUntilVisible(
      find.byKey(Key(key)),
      220,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.ensureVisible(find.byKey(Key(key)));
    await tester.pump();
  }

  Future<void> signIn(WidgetTester tester, {String email = 'first@example.test'}) async {
    await tester.enterText(find.byKey(const Key('ai-login-email')), email);
    await tester.enterText(find.byKey(const Key('ai-login-password')), 'fixture-password');
    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pumpAndSettle();
  }

  Future<void> enterSecretDiagnosis(WidgetTester tester) async {
    await tester.enterText(find.byKey(const Key('ai-technology')), 'PRIVATE_TECH');
    await tester.enterText(
      find.byKey(const Key('ai-question')), 'PRIVATE_QUESTION_FROM_ACCOUNT_ONE',
    );
    await tester.enterText(find.byKey(const Key('ai-code')), 'PRIVATE_CODE_FROM_ACCOUNT_ONE');
    await reveal(tester, 'ai-consent');
    await tester.tap(find.byKey(const Key('ai-consent')));
    await tester.pump();
  }

  Future<void> assertCleanForSecondAccount(WidgetTester tester) async {
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    final email = tester.widget<TextField>(find.byKey(const Key('ai-login-email')));
    expect(email.controller?.text, isEmpty);
    expect(find.textContaining('PRIVATE_QUESTION_FROM_ACCOUNT_ONE'), findsNothing);
    expect(find.textContaining('PRIVATE_CODE_FROM_ACCOUNT_ONE'), findsNothing);
    expect(find.text(bearer), findsNothing);

    await signIn(tester, email: 'second@example.test');
    for (final key in ['ai-technology', 'ai-question', 'ai-code', 'ai-resume-id']) {
      await reveal(tester, key);
      expect(
        tester.widget<TextField>(find.byKey(Key(key))).controller?.text,
        isEmpty,
        reason: '$key must not survive an auth boundary',
      );
    }
    await reveal(tester, 'ai-consent');
    expect(
      tester.widget<CheckboxListTile>(find.byKey(const Key('ai-consent'))).value,
      isFalse,
    );
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    expect(find.text(bearer), findsNothing);
  }

  testWidgets('expired bearer scrubs private inputs before different account signs in',
      (tester) async {
    var now = DateTime.utc(2026, 11, 2, 8);
    var calls = 0;
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        nowUtc: () => now,
        loginAction: (_, __) async => MobileAiSession(
          token: bearer, expiresAt: now.add(const Duration(minutes: 1)),
        ),
        diagnoseAction: (_, __, ___, ____) async {
          calls++;
          throw StateError('No network call after token expiry');
        },
      ),
    ));
    await signIn(tester);
    await enterSecretDiagnosis(tester);
    now = now.add(const Duration(minutes: 2));
    await reveal(tester, 'ai-diagnose-submit');
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(calls, 0);
    expect(find.text('Session expired. Sign in again.'), findsOneWidget);
    await assertCleanForSecondAccount(tester);
  });

  for (final status in [401, 403]) {
    testWidgets('diagnosis HTTP $status revokes account-bound input and consent in AR',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: AiTroubleshooterScreen(
          arabic: true,
          loginAction: (_, __) async =>
              const MobileAiSession(token: bearer, expiresAt: null),
          diagnoseAction: (_, __, ___, ____) async =>
              throw MobileAiHttpException(status),
        ),
      ));
      await signIn(tester);
      await enterSecretDiagnosis(tester);
      await reveal(tester, 'ai-diagnose-submit');
      await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
      await tester.pumpAndSettle();
      expect(find.textContaining('انتهت الجلسة'), findsOneWidget);
      await assertCleanForSecondAccount(tester);
      expect(Directionality.of(tester.element(find.byType(Scaffold))),
          TextDirection.rtl);
    });
  }

  testWidgets('polling 401 scrubs old job and code before another login',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        diagnoseAction: (_, __, ___, ____) async => const MobileAiJob(
          id: jobId, status: 'queued', progressPercent: 0,
          resultUrl: null, retryAfterSeconds: 1,
        ),
        jobStatusAction: (_, __) async => throw const MobileAiHttpException(401),
      ),
    ));
    await signIn(tester);
    await enterSecretDiagnosis(tester);
    await reveal(tester, 'ai-diagnose-submit');
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    await tester.pump();
    await reveal(tester, 'ai-refresh-status');
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    await assertCleanForSecondAccount(tester);
  });

  testWidgets('manual job restore 403 clears resume ID and private prompt',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        jobStatusAction: (_, __) async => throw const MobileAiHttpException(403),
      ),
    ));
    await signIn(tester);
    await enterSecretDiagnosis(tester);
    await reveal(tester, 'ai-resume-id');
    await tester.enterText(find.byKey(const Key('ai-resume-id')), jobId);
    await reveal(tester, 'ai-resume-submit');
    await tester.tap(find.byKey(const Key('ai-resume-submit')));
    await tester.pumpAndSettle();
    await assertCleanForSecondAccount(tester);
  });

  testWidgets('save result 403 revokes old account and clears all inputs',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: bearer, expiresAt: null),
        diagnoseAction: (_, __, ___, ____) async => const MobileAiJob(
          id: jobId, status: 'completed', progressPercent: 100,
          resultUrl: '/api/v1/mobile/solutions/$solutionId',
          retryAfterSeconds: null,
        ),
        saveAction: (_, __) async => throw const MobileAiHttpException(403),
      ),
    ));
    await signIn(tester);
    await enterSecretDiagnosis(tester);
    await reveal(tester, 'ai-diagnose-submit');
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await reveal(tester, 'ai-save-answer');
    await tester.tap(find.byKey(const Key('ai-save-answer')));
    await tester.pumpAndSettle();
    await assertCleanForSecondAccount(tester);
  });
}

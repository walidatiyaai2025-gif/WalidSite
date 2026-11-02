import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';

void main() {
  const token = '1|test-session-only';
  const jobId = '11111111-1111-4111-8111-111111111111';
  const differentId = '22222222-2222-4222-8222-222222222222';

  Future<void> signIn(WidgetTester tester) async {
    await tester.enterText(
        find.byKey(const Key('ai-login-email')), 'reader@example.test');
    await tester.enterText(
        find.byKey(const Key('ai-login-password')), 'testing-secret');
    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pumpAndSettle();
  }

  Future<void> resume(WidgetTester tester, String id) async {
    await tester.scrollUntilVisible(
      find.byKey(const Key('ai-resume-id')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.enterText(find.byKey(const Key('ai-resume-id')), id);
    await tester.ensureVisible(find.byKey(const Key('ai-resume-submit')));
    await tester.tap(find.byKey(const Key('ai-resume-submit')));
    await tester.pumpAndSettle();
  }

  test('transport requires affirmative caller consent even without an origin',
      () async {
    await expectLater(
      const MobileAiClient().diagnose(
        token,
        question: 'Unexpected server failure',
        technology: 'Laravel',
        codeSnippet: '',
        consentToProcess: false,
      ),
      throwsA(isA<FormatException>().having(
          (e) => e.message, 'reason', 'Explicit diagnosis consent required')),
    );
    expect(MobileAiJob.isValidId(jobId), isTrue);
    expect(MobileAiJob.isValidId('https://untrusted.example/$jobId'), isFalse);
  });

  testWidgets('authenticated restore uses GET only and never replays prompt',
      (tester) async {
    var gets = 0;
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: token, expiresAt: null),
        diagnoseAction: (_, __, ___, ____) async {
          fail('Restoring a job must not invoke diagnosis POST.');
        },
        jobStatusAction: (bearer, id) async {
          gets++;
          expect(bearer, token);
          expect(id, jobId);
          return const MobileAiJob(
            id: jobId,
            status: 'completed',
            progressPercent: 100,
            resultUrl: null,
            retryAfterSeconds: null,
          );
        },
      ),
    ));
    await signIn(tester);
    await resume(tester, jobId);
    expect(gets, 1);
    // Restoring a job replaces the form with the real status card.
    expect(find.byKey(const Key('ai-resume-id')), findsNothing);
    await tester.scrollUntilVisible(
      find.byKey(const Key('ai-message')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.textContaining('Diagnosis completed'), findsOneWidget);
    expect(find.text(token), findsNothing);
    expect(find.text('testing-secret'), findsNothing);
  });

  testWidgets('malformed ID and mismatched GET results fail closed in Arabic',
      (tester) async {
    var gets = 0;
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: true,
        loginAction: (_, __) async =>
            const MobileAiSession(token: token, expiresAt: null),
        jobStatusAction: (_, __) async {
          gets++;
          return const MobileAiJob(
            id: differentId,
            status: 'completed',
            progressPercent: 100,
            resultUrl: null,
            retryAfterSeconds: null,
          );
        },
      ),
    ));
    await signIn(tester);
    expect(Directionality.of(tester.element(find.byType(Scaffold))),
        TextDirection.rtl);
    await resume(tester, 'not-a-job-id');
    expect(gets, 0);
    await tester.scrollUntilVisible(
      find.byKey(const Key('ai-message')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.textContaining('معرّف مهمة صالحًا'), findsOneWidget);

    await resume(tester, jobId);
    expect(gets, 1);
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    await tester.scrollUntilVisible(
      find.byKey(const Key('ai-message')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.textContaining('تعذر الاتصال بالخدمة بأمان'), findsOneWidget);
  });

  testWidgets('404 stays signed in but 403 revokes the local session',
      (tester) async {
    var gets = 0;
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: token, expiresAt: null),
        jobStatusAction: (_, __) async {
          gets++;
          throw MobileAiHttpException(gets == 1 ? 404 : 403);
        },
      ),
    ));
    await signIn(tester);
    await resume(tester, jobId);
    await tester.scrollUntilVisible(
      find.byKey(const Key('ai-message')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.textContaining('Job not available or expired'), findsOneWidget);

    await resume(tester, jobId);
    expect(gets, 2);
    expect(find.byKey(const Key('ai-login-submit')), findsOneWidget);
    expect(find.byKey(const Key('ai-resume-id')), findsNothing);
    expect(find.text(token), findsNothing);
  });
}

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

void main() {
  const token = '1|test-mobile-bearer-token';
  const jobId = '11111111-1111-4111-8111-111111111111';
  const runbookId = '22222222-2222-4222-8222-222222222222';

  Future<void> pumpScreen(
    WidgetTester tester, {
    bool arabic = false,
    MobileLoginAction? login,
    MobileDiagnoseAction? diagnose,
    MobileSaveAction? save,
    Future<ReviewedRunbook> Function(String id)? detail,
  }) async {
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: arabic,
        loginAction: login,
        diagnoseAction: diagnose,
        saveAction: save,
        detailLoader: detail,
      ),
    ));
  }

  Future<void> reveal(WidgetTester tester, Key key) async {
    final finder = find.byKey(key);
    final mainScroll = find.descendant(
      of: find.byType(ListView),
      matching: find.byType(Scrollable),
    ).first;
    await tester.scrollUntilVisible(
      finder,
      220,
      scrollable: mainScroll,
    );
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
  }

  Future<void> login(
    WidgetTester tester, {
    String email = 'reader@example.test',
    String password = 'correct horse battery staple',
  }) async {
    await tester.enterText(find.byKey(const Key('ai-login-email')), email);
    await tester.enterText(find.byKey(const Key('ai-login-password')), password);
    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pumpAndSettle();
  }

  test('strict AiJob parser accepts only canonical solution URL', () {
    final job = MobileAiJob.parse(
      '{"data":{"id":"$jobId","status":"completed","progress_percent":100,'
      '"result_url":"/api/v1/mobile/solutions/$runbookId",'
      '"retry_after_seconds":null}}',
    );
    expect(job.reviewedRunbookId(), runbookId);

    final external = MobileAiJob.parse(
      '{"data":{"id":"$jobId","status":"completed","progress_percent":100,'
      '"result_url":"https://evil.example/api/v1/mobile/solutions/$runbookId",'
      '"retry_after_seconds":null}}',
    );
    expect(external.reviewedRunbookId(), isNull);

    expect(
      () => MobileAiJob.parse(
        '{"data":{"id":"$jobId","status":"completed","progress_percent":90,'
        '"result_url":null,"retry_after_seconds":null}}',
      ),
      throwsFormatException,
    );
    expect(
      () => MobileAiJob.parse(
        '{"data":{"id":"$jobId","status":"completed","progress_percent":100,'
        '"result_url":null,"retry_after_seconds":null,"secret":"x"}}',
      ),
      throwsFormatException,
    );
  });

  testWidgets('requires real login and never keeps password in the form',
      (tester) async {
    var loginCalls = 0;
    String? receivedEmail;
    String? receivedPassword;
    await pumpScreen(
      tester,
      login: (email, password) async {
        loginCalls++;
        receivedEmail = email;
        receivedPassword = password;
        return const MobileAiSession(token: token, expiresAt: null);
      },
      diagnose: (token, question, technology, code) async {
        fail('Diagnosis must not run before explicit submit.');
      },
    );

    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.byKey(const Key('ai-diagnose-submit')), findsNothing);

    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pump();
    expect(loginCalls, 0);
    expect(find.text('Enter email and password.'), findsOneWidget);

    await login(tester);

    expect(loginCalls, 1);
    expect(receivedEmail, 'reader@example.test');
    expect(receivedPassword, 'correct horse battery staple');
    await reveal(tester, const Key('ai-diagnose-submit'));
    expect(find.byKey(const Key('ai-diagnose-submit')), findsOneWidget);
    expect(find.text('correct horse battery staple'), findsNothing);
    expect(find.text(token), findsNothing);
    await reveal(tester, const Key('ai-message'));
    expect(find.text('Signed in for this session only.'), findsOneWidget);
  });

  testWidgets('consent gates diagnosis and successful result opens reviewed detail',
      (tester) async {
    var diagnoseCalls = 0;
    String? receivedToken;
    String? receivedQuestion;
    String? receivedTechnology;
    String? receivedCode;

    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (bearer, question, technology, code) async {
        diagnoseCalls++;
        receivedToken = bearer;
        receivedQuestion = question;
        receivedTechnology = technology;
        receivedCode = code;
        return const MobileAiJob(
          id: jobId,
          status: 'completed',
          progressPercent: 100,
          resultUrl: '/api/v1/mobile/solutions/$runbookId',
          retryAfterSeconds: null,
        );
      },
      detail: (id) async {
        expect(id, runbookId);
        return ReviewedRunbook(
          id: id,
          title: 'Reviewed SQL recovery',
          markdown: '# Verified steps\n1. Check recovery state.',
          version: 3,
          validatedAt: DateTime.utc(2026, 11, 1, 10),
        );
      },
    );
    await login(tester);
    await tester.enterText(
      find.byKey(const Key('ai-technology')),
      'SQL Server',
    );
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'Why did recovery stop after restart?',
    );
    await tester.enterText(
      find.byKey(const Key('ai-code')),
      'RESTORE DATABASE sample WITH RECOVERY;',
    );

    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pump();
    expect(diagnoseCalls, 0);
    expect(
      find.text('Consent is required before sending data for processing.'),
      findsOneWidget,
    );

    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await tester.pump();
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    expect(diagnoseCalls, 1);
    expect(receivedToken, token);
    expect(receivedQuestion, 'Why did recovery stop after restart?');
    expect(receivedTechnology, 'SQL Server');
    expect(receivedCode, 'RESTORE DATABASE sample WITH RECOVERY;');
    await reveal(tester, const Key('ai-open-solution'));
    expect(find.byKey(const Key('ai-open-solution')), findsOneWidget);
    expect(find.byKey(const Key('ai-save-answer')), findsOneWidget);
    await reveal(tester, const Key('ai-report-error-disabled'));
    expect(find.byKey(const Key('ai-report-error-disabled')), findsOneWidget);

    await reveal(tester, const Key('ai-open-solution'));
    await tester.tap(find.byKey(const Key('ai-open-solution')));
    await tester.pumpAndSettle();

    expect(find.text('Reviewed SQL recovery'), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-body')), findsOneWidget);
    expect(find.textContaining('RESTORE DATABASE'), findsNothing);
    expect(find.text(token), findsNothing);
  });

  testWidgets('save answer uses authenticated server bookmark once',
      (tester) async {
    var saveCalls = 0;
    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async => const MobileAiJob(
        id: jobId,
        status: 'completed',
        progressPercent: 100,
        resultUrl: '/api/v1/mobile/solutions/$runbookId',
        retryAfterSeconds: null,
      ),
      save: (bearer, id) async {
        saveCalls++;
        expect(bearer, token);
        expect(id, runbookId);
      },
    );
    await login(tester);
    await tester.enterText(
      find.byKey(const Key('ai-technology')),
      'Laravel',
    );
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'Why is this queue retrying?',
    );
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    await reveal(tester, const Key('ai-save-answer'));
    await tester.tap(find.byKey(const Key('ai-save-answer')));
    await tester.pumpAndSettle();
    expect(saveCalls, 1);
    expect(find.text('Saved'), findsOneWidget);

    final savedButton = tester.widget<OutlinedButton>(
      find.byKey(const Key('ai-save-answer')),
    );
    expect(savedButton.onPressed, isNull);
    expect(find.text(token), findsNothing);
  });

  testWidgets('quota and authorization failures are explicit and redact session',
      (tester) async {
    var attempts = 0;
    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async {
        attempts++;
        if (attempts == 1) throw const MobileAiHttpException(429);
        throw const MobileAiHttpException(401);
      },
    );
    await login(tester);
    await tester.enterText(
      find.byKey(const Key('ai-technology')),
      'Flutter',
    );
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'Why does this request fail?',
    );
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));

    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(find.text('Usage limit reached. Try again later.'), findsOneWidget);
    expect(find.byKey(const Key('ai-login-email')), findsNothing);

    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(
      find.text('Your session expired or is not authorized for this tool.'),
      findsOneWidget,
    );
    await reveal(tester, const Key('ai-login-email'));
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.text(token), findsNothing);
  });

  testWidgets('failed job and queued job never invent a solution',
      (tester) async {
    var calls = 0;
    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async {
        calls++;
        return MobileAiJob(
          id: jobId,
          status: calls == 1 ? 'queued' : 'failed',
          progressPercent: calls == 1 ? 0 : 100,
          resultUrl: null,
          retryAfterSeconds: calls == 1 ? 30 : null,
        );
      },
    );
    await login(tester);
    await tester.enterText(
      find.byKey(const Key('ai-technology')),
      'API',
    );
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'Why is the request delayed?',
    );
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));

    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await reveal(tester, const Key('ai-job-status'));
    expect(find.textContaining('Retry after about 30 seconds'), findsOneWidget);
    expect(find.byKey(const Key('ai-open-solution')), findsNothing);

    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(find.text('The diagnosis could not complete safely.'), findsOneWidget);
    expect(find.byKey(const Key('ai-open-solution')), findsNothing);
  });

  testWidgets('Arabic journey is RTL and keeps reporting explicitly unavailable',
      (tester) async {
    await pumpScreen(
      tester,
      arabic: true,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async => const MobileAiJob(
        id: jobId,
        status: 'completed',
        progressPercent: 100,
        resultUrl: '/api/v1/mobile/solutions/$runbookId',
        retryAfterSeconds: null,
      ),
    );

    expect(Directionality.of(tester.element(find.byType(Scaffold))), TextDirection.rtl);
    expect(find.text('تسجيل الدخول مطلوب'), findsOneWidget);

    await login(
      tester,
      email: 'arabic@example.test',
      password: 'safe password',
    );
    await tester.enterText(
      find.byKey(const Key('ai-technology')),
      'SQL Server',
    );
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'لماذا فشل الاسترجاع بعد إعادة التشغيل؟',
    );
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    await reveal(tester, const Key('ai-open-solution'));
    expect(find.text('افتح الحل المراجع'), findsOneWidget);
    await reveal(tester, const Key('ai-report-error-disabled'));
    expect(find.text('الإبلاغ غير متاح حاليًا'), findsOneWidget);
  });
}

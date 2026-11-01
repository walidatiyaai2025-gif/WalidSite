import 'dart:async';

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
    MobileJobStatusAction? poll,
    MobileSaveAction? save,
    Future<ReviewedRunbook> Function(String id)? detail,
    DateTime Function()? nowUtc,
  }) async {
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: arabic,
        loginAction: login,
        diagnoseAction: diagnose,
        jobStatusAction: poll,
        saveAction: save,
        detailLoader: detail,
        nowUtc: nowUtc,
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
    var detailSaveCalls = 0;
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
      save: (bearer, id) async {
        detailSaveCalls++;
        expect(bearer, token);
        expect(id, runbookId);
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
    await reveal(tester, const Key('ai-report-error'));
    expect(find.byKey(const Key('ai-report-error')), findsOneWidget);

    await reveal(tester, const Key('ai-open-solution'));
    await tester.tap(find.byKey(const Key('ai-open-solution')));
    await tester.pumpAndSettle();

    expect(find.text('Reviewed SQL recovery'), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-body')), findsOneWidget);
    expect(find.textContaining('RESTORE DATABASE'), findsNothing);
    expect(find.text(token), findsNothing);
    await reveal(tester, const Key('online-runbook-save'));
    await tester.tap(find.byKey(const Key('online-runbook-save')));
    await tester.pumpAndSettle();
    expect(detailSaveCalls, 1);
    expect(find.byKey(const Key('online-runbook-login-email')), findsNothing);
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

  testWidgets('queued job can fail by read-only status without a second POST',
      (tester) async {
    var diagnoses = 0;
    var reads = 0;
    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async {
        diagnoses++;
        return const MobileAiJob(
          id: jobId,
          status: 'queued',
          progressPercent: 0,
          resultUrl: null,
          retryAfterSeconds: 30,
        );
      },
      poll: (bearer, id) async {
        reads++;
        expect(bearer, token);
        expect(id, jobId);
        return const MobileAiJob(
          id: jobId,
          status: 'failed',
          progressPercent: 100,
          resultUrl: null,
          retryAfterSeconds: null,
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
    expect(tester.widget<FilledButton>(
      find.byKey(const Key('ai-diagnose-submit')),
    ).onPressed, isNull);
    await reveal(tester, const Key('ai-refresh-status'));
    expect(tester.widget<OutlinedButton>(
      find.byKey(const Key('ai-refresh-status')),
    ).onPressed, isNull);
    expect(reads, 0);
    await tester.pump(const Duration(seconds: 30));
    await tester.pump();
    await reveal(tester, const Key('ai-refresh-status'));
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    await reveal(tester, const Key('ai-message'));
    expect(find.text('The diagnosis could not complete safely.'),
        findsOneWidget);
    expect(find.byKey(const Key('ai-open-solution')), findsNothing);
    expect(diagnoses, 1);
    expect(reads, 1);
  });

  testWidgets('Arabic journey is RTL and exposes explicit report action',
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
    await reveal(tester, const Key('ai-report-error'));
    expect(find.text('الإبلاغ عن مشكلة التشخيص'), findsOneWidget);
  });
  testWidgets('expired login token is rejected without revealing credentials',
      (tester) async {
    final now = DateTime.utc(2026, 11, 1, 10);
    var diagnosisCalls = 0;
    await pumpScreen(
      tester,
      nowUtc: () => now,
      login: (_, __) async => MobileAiSession(
        token: token,
        expiresAt: now.subtract(const Duration(seconds: 1)),
      ),
      diagnose: (_, __, ___, ____) async {
        diagnosisCalls++;
        throw StateError('Must not diagnose with an expired token');
      },
    );
    await login(tester);
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.text('Session expired. Sign in again.'), findsOneWidget);
    expect(find.text('correct horse battery staple'), findsNothing);
    expect(find.text(token), findsNothing);
    expect(diagnosisCalls, 0);
  });

  testWidgets('expires in-memory login before attempting diagnosis in Arabic',
      (tester) async {
    var now = DateTime.utc(2026, 11, 1, 10);
    var diagnosisCalls = 0;
    await pumpScreen(
      tester,
      arabic: true,
      nowUtc: () => now,
      login: (_, __) async => MobileAiSession(
        token: token,
        expiresAt: DateTime.utc(2026, 11, 1, 10, 1),
      ),
      diagnose: (_, __, ___, ____) async {
        diagnosisCalls++;
        throw StateError('Expired bearer must never reach the API');
      },
    );
    await login(tester);
    now = now.add(const Duration(minutes: 2));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(diagnosisCalls, 0);
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.text('انتهت صلاحية الجلسة. سجّل الدخول مرة أخرى.'),
        findsOneWidget);
    expect(find.text(token), findsNothing);
  });

  testWidgets('expired token cannot save previously reviewed AI result',
      (tester) async {
    var now = DateTime.utc(2026, 11, 1, 10);
    var saveCalls = 0;
    await pumpScreen(
      tester,
      nowUtc: () => now,
      login: (_, __) async => MobileAiSession(
        token: token,
        expiresAt: DateTime.utc(2026, 11, 1, 10, 1),
      ),
      diagnose: (_, __, ___, ____) async => const MobileAiJob(
        id: jobId,
        status: 'completed',
        progressPercent: 100,
        resultUrl: '/api/v1/mobile/solutions/$runbookId',
        retryAfterSeconds: null,
      ),
      save: (_, __) async { saveCalls++; },
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'Laravel');
    await tester.enterText(
        find.byKey(const Key('ai-question')), 'Why is the queue stalled?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await reveal(tester, const Key('ai-save-answer'));
    now = now.add(const Duration(minutes: 2));
    await tester.tap(find.byKey(const Key('ai-save-answer')));
    await tester.pumpAndSettle();
    expect(saveCalls, 0);
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    expect(find.text('Session expired. Sign in again.'), findsOneWidget);
  });


  testWidgets('queued and running status refresh reaches reviewed result safely',
      (tester) async {
    var postCalls = 0;
    var getCalls = 0;
    await pumpScreen(
      tester,
      arabic: true,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async {
        postCalls++;
        return const MobileAiJob(
          id: jobId,
          status: 'queued',
          progressPercent: 0,
          resultUrl: null,
          retryAfterSeconds: 300,
        );
      },
      poll: (bearer, id) async {
        expect(bearer, token);
        expect(id, jobId);
        getCalls++;
        if (getCalls == 1) {
          return const MobileAiJob(
            id: jobId,
            status: 'running',
            progressPercent: 50,
            resultUrl: null,
            retryAfterSeconds: 300,
          );
        }
        return const MobileAiJob(
          id: jobId,
          status: 'completed',
          progressPercent: 100,
          resultUrl: '/api/v1/mobile/solutions/$runbookId',
          retryAfterSeconds: null,
        );
      },
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'SQL');
    await tester.enterText(find.byKey(const Key('ai-question')),
        'Why is this long-running job queued?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    await reveal(tester, const Key('ai-refresh-status'));
    expect(find.text('تحديث حالة التشخيص'), findsOneWidget);
    expect(find.text('في الانتظار · 0%'), findsOneWidget);
    expect(find.text('queued · 0%'), findsNothing);
    expect(getCalls, 0);
    expect(tester.widget<OutlinedButton>(
      find.byKey(const Key('ai-refresh-status')),
    ).onPressed, isNull);
    // Scroll animations advance fake test time; keep a wide, explicit
    // server-advised cooldown so this remains deterministic on CI.
    await tester.pump(const Duration(seconds: 30));
    await tester.pump();
    expect(tester.widget<OutlinedButton>(
      find.byKey(const Key('ai-refresh-status')),
    ).onPressed, isNull);
    await tester.pump(const Duration(seconds: 270));
    await tester.pump();
    await reveal(tester, const Key('ai-refresh-status'));
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    expect(getCalls, 1);
    await reveal(tester, const Key('ai-job-status'));
    expect(find.text('قيد التنفيذ · 50%'), findsOneWidget);
    expect(find.byKey(const Key('ai-open-solution')), findsNothing);

    await tester.pump(const Duration(minutes: 5));
    await tester.pump();
    await reveal(tester, const Key('ai-refresh-status'));
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    expect(getCalls, 2);
    expect(postCalls, 1);
    await reveal(tester, const Key('ai-open-solution'));
    expect(find.text('افتح الحل المراجع'), findsOneWidget);
    expect(find.text('مكتمل · 100%'), findsOneWidget);
    expect(find.byKey(const Key('ai-refresh-status')), findsNothing);
    expect(find.text(token), findsNothing);
  });

  testWidgets('401 on status refresh revokes local session and job',
      (tester) async {
    var postCalls = 0;
    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async {
        postCalls++;
        return const MobileAiJob(
          id: jobId, status: 'queued', progressPercent: 0,
          resultUrl: null, retryAfterSeconds: 1,
        );
      },
      poll: (_, __) async => throw const MobileAiHttpException(401),
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'API');
    await tester.enterText(find.byKey(const Key('ai-question')),
        'Why does this request fail?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    await tester.pump();
    await reveal(tester, const Key('ai-refresh-status'));
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    expect(find.text(
        'Your session expired or is not authorized for this tool.'),
        findsOneWidget);
    expect(find.text(token), findsNothing);
    expect(postCalls, 1);
  });

  testWidgets('missing job returns safe recovery without resending',
      (tester) async {
    var postCalls = 0;
    await pumpScreen(
      tester,
      arabic: true,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async {
        postCalls++;
        return const MobileAiJob(
          id: jobId, status: 'running', progressPercent: 50,
          resultUrl: null, retryAfterSeconds: 1,
        );
      },
      poll: (_, __) async => throw const MobileAiHttpException(404),
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'API');
    await tester.enterText(find.byKey(const Key('ai-question')),
        'Where did the job go?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    await tester.pump();
    await reveal(tester, const Key('ai-refresh-status'));
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    expect(find.text('لم تعد مهمة التشخيص متاحة. يمكنك بدء طلب جديد.'),
        findsOneWidget);
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    expect(find.byKey(const Key('ai-login-email')), findsNothing);
    expect(postCalls, 1);
  });

  testWidgets('mismatched status identity cannot expose another job result',
      (tester) async {
    var getCalls = 0;
    await pumpScreen(
      tester,
      login: (_, __) async =>
          const MobileAiSession(token: token, expiresAt: null),
      diagnose: (_, __, ___, ____) async => const MobileAiJob(
        id: jobId, status: 'queued', progressPercent: 0,
        resultUrl: null, retryAfterSeconds: 1,
      ),
      poll: (_, __) async {
        getCalls++;
        return const MobileAiJob(
          id: runbookId,
          status: 'completed',
          progressPercent: 100,
          resultUrl: '/api/v1/mobile/solutions/$runbookId',
          retryAfterSeconds: null,
        );
      },
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'Dart');
    await tester.enterText(find.byKey(const Key('ai-question')),
        'Is this the correct diagnosis?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(seconds: 1));
    await tester.pump();
    await reveal(tester, const Key('ai-refresh-status'));
    await tester.tap(find.byKey(const Key('ai-refresh-status')));
    await tester.pumpAndSettle();
    expect(getCalls, 1);
    await reveal(tester, const Key('ai-job-status'));
    expect(find.text('Queued · 0%'), findsOneWidget);
    expect(find.byKey(const Key('ai-open-solution')), findsNothing);
    expect(find.text(token), findsNothing);
  });


  testWidgets('terminal job states have safe bilingual labels', (tester) async {
    const labels = {
      'failed': ['Failed', 'فشل'],
      'blocked': ['Blocked', 'محظور'],
      'cancelled': ['Cancelled', 'ملغى'],
    };
    var submitCount = 0;

    for (final arabic in [false, true]) {
      for (final item in labels.entries) {
        // Discard the previous diagnosis widget to avoid reusing a session
        // or an asynchronous job state across language/status scenarios.
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
        await pumpScreen(
          tester,
          arabic: arabic,
          login: (_, __) async =>
              const MobileAiSession(token: token, expiresAt: null),
          diagnose: (_, __, ___, ____) async {
            submitCount++;
            return MobileAiJob(
              id: jobId,
              status: item.key,
              progressPercent: 0,
              resultUrl: null,
              retryAfterSeconds: null,
            );
          },
        );
        await login(tester);
        await tester.enterText(find.byKey(const Key('ai-technology')), 'SQL');
        await tester.enterText(
          find.byKey(const Key('ai-question')),
          'Why did the diagnosis not complete?',
        );
        await reveal(tester, const Key('ai-consent'));
        await tester.tap(find.byKey(const Key('ai-consent')));
        await reveal(tester, const Key('ai-diagnose-submit'));
        await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
        await tester.pumpAndSettle();
        await reveal(tester, const Key('ai-job-status'));

        expect(
          find.text('${item.value[arabic ? 1 : 0]} · 0%'),
          findsOneWidget,
        );
        if (arabic) {
          expect(find.text('${item.key} · 0%'), findsNothing);
        }
        expect(find.byKey(const Key('ai-open-solution')), findsNothing);
        expect(find.byKey(const Key('ai-refresh-status')), findsNothing);
        expect(find.text(token), findsNothing);
      }
    }
    expect(submitCount, 6);
  });

  testWidgets('in-flight diagnosis completion cannot restore an expired session',
      (tester) async {
    var now = DateTime.utc(2026, 11, 1, 10);
    final response = Completer<MobileAiJob>();
    var postCalls = 0;
    await pumpScreen(
      tester,
      nowUtc: () => now,
      login: (_, __) async => MobileAiSession(
        token: token,
        expiresAt: DateTime.utc(2026, 11, 1, 10, 1),
      ),
      diagnose: (bearer, _, __, ___) {
        expect(bearer, token);
        postCalls++;
        return response.future;
      },
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'Dart');
    await tester.enterText(find.byKey(const Key('ai-question')),
        'Why is this request still in progress?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pump();
    expect(postCalls, 1);

    now = now.add(const Duration(minutes: 2));
    response.complete(const MobileAiJob(
      id: jobId,
      status: 'completed',
      progressPercent: 100,
      resultUrl: '/api/v1/mobile/solutions/$runbookId',
      retryAfterSeconds: null,
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    expect(find.byKey(const Key('ai-open-solution')), findsNothing);
    expect(find.text('Session expired. Sign in again.'), findsOneWidget);
    expect(find.text('A reviewed published solution is ready.'), findsNothing);
    expect(find.text(token), findsNothing);
    expect(postCalls, 1);
  });

  testWidgets('in-flight save expiry cannot mark old solution saved or start a second POST',
      (tester) async {
    var now = DateTime.utc(2026, 11, 1, 10);
    final saving = Completer<void>();
    var postCalls = 0;
    var saveCalls = 0;
    await pumpScreen(
      tester,
      arabic: true,
      nowUtc: () => now,
      login: (_, __) async => MobileAiSession(
        token: token,
        expiresAt: DateTime.utc(2026, 11, 1, 10, 1),
      ),
      diagnose: (bearer, _, __, ___) async {
        expect(bearer, token);
        postCalls++;
        return const MobileAiJob(
          id: jobId,
          status: 'completed',
          progressPercent: 100,
          resultUrl: '/api/v1/mobile/solutions/$runbookId',
          retryAfterSeconds: null,
        );
      },
      save: (bearer, id) {
        expect(bearer, token);
        expect(id, runbookId);
        saveCalls++;
        return saving.future;
      },
    );
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'Laravel');
    await tester.enterText(find.byKey(const Key('ai-question')),
        'Why did the queue stop processing?');
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();
    expect(postCalls, 1);

    await reveal(tester, const Key('ai-save-answer'));
    await tester.tap(find.byKey(const Key('ai-save-answer')));
    await tester.pump();
    expect(saveCalls, 1);
    await reveal(tester, const Key('ai-diagnose-submit'));
    expect(tester.widget<FilledButton>(
      find.byKey(const Key('ai-diagnose-submit')),
    ).onPressed, isNull);
    expect(postCalls, 1);

    now = now.add(const Duration(minutes: 2));
    saving.complete();
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ai-login-email')), findsOneWidget);
    expect(find.byKey(const Key('ai-job-card')), findsNothing);
    expect(find.text('انتهت صلاحية الجلسة. سجّل الدخول مرة أخرى.'),
        findsOneWidget);
    expect(find.text('تم حفظ الدليل في حسابك.'), findsNothing);
    expect(find.text(token), findsNothing);
    expect(saveCalls, 1);
    expect(postCalls, 1);
  });

}

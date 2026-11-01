import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/ai/diagnosis_report.dart';

void main() {
  const uuid = '11111111-1111-4111-8111-111111111111';

  Future<void> pumpReport(WidgetTester tester, {
    bool arabic = false,
    DiagnosisReportAction? submit,
  }) async {
    await tester.pumpWidget(MaterialApp(
      home: MobileDiagnosisReportScreen(
        arabic: arabic,
        initialEmail: 'reader@example.test',
        submitAction: submit,
      ),
    ));
  }

  Future<void> reveal(WidgetTester tester, String key) async {
    await tester.scrollUntilVisible(
      find.byKey(Key(key)), 200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
  }

  Future<void> fill(WidgetTester tester) async {
    await tester.enterText(find.byKey(const Key('report-name')), 'Verified reader');
    await tester.enterText(find.byKey(const Key('report-description')),
        'The reviewed result did not answer my question.');
    await reveal(tester, 'report-consent');
    await tester.tap(find.byKey(const Key('report-consent')));
    await tester.pump();
    await reveal(tester, 'report-send');
  }

  test('report payload is contact-only and rejects missing consent or unsafe fields', () {
    final report = DiagnosisReportInput(
      name: '  Example Reader  ',
      email: ' reader@example.test ',
      message: '  A reviewed guide did not resolve the issue.  ',
      consent: true,
    );
    expect(report.toJson(), {
      'name': 'Example Reader',
      'email': 'reader@example.test',
      'message': 'A reviewed guide did not resolve the issue.',
      'privacy_consent': true,
    });
    expect(report.toJson().keys.toSet(), {
      'name', 'email', 'message', 'privacy_consent',
    });
    for (final bad in [
      () => DiagnosisReportInput(
          name: 'Reader', email: 'reader@example.test',
          message: 'Valid descriptive message', consent: false),
      () => DiagnosisReportInput(
          name: 'R', email: 'reader@example.test',
          message: 'Valid descriptive message', consent: true),
      () => DiagnosisReportInput(
          name: 'Reader', email: 'invalid',
          message: 'Valid descriptive message', consent: true),
      () => DiagnosisReportInput(
          name: 'Reader', email: 'reader@example.test',
          message: 'short', consent: true),
      () => DiagnosisReportInput(
          name: 'Reader', email: 'reader@example.test',
          message: 'a' * 3001, consent: true),
      () => DiagnosisReportInput(
          name: 'Reader', email: 'reader@example.test',
          message: 'Contains a forbidden\u0000byte', consent: true),
    ]) {
      expect(bad, throwsFormatException);
    }
  });

  test('transport receipt never claims delivered mail without explicit acceptance', () {
    expect(
      DiagnosisReportReceipt.parse(
        '{"ok":true,"data":{"accepted_by_transport":true},'
        '"timestamp":"2026-11-01T00:00:00Z","request_id":"$uuid"}'),
      isA<DiagnosisReportReceipt>(),
    );
    for (final raw in [
      '{"ok":true,"data":{"accepted_by_transport":false}}',
      '{"ok":false,"data":{"accepted_by_transport":true}}',
      '{"ok":true,"data":null}',
      '{"ok":true,"data":{"accepted_by_transport":true}}{"unexpected":true}',
    ]) {
      expect(() => DiagnosisReportReceipt.parse(raw), throwsFormatException);
    }
  });

  testWidgets('honest unconfigured state never offers a fake send action',
      (tester) async {
    await pumpReport(tester);
    expect(find.byKey(const Key('report-unconfigured')), findsOneWidget);
    expect(find.byKey(const Key('report-send')), findsNothing);
  });

  testWidgets('English consent, validation and transport acceptance are one-shot',
      (tester) async {
    final sent = <DiagnosisReportInput>[];
    await pumpReport(tester, submit: (report) async { sent.add(report); });

    expect(find.textContaining('Your AI question, code, job ID'), findsOneWidget);
    await reveal(tester, 'report-send');
    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pump();
    expect(sent, isEmpty);
    expect(find.textContaining('Enter a valid name'), findsOneWidget);

    await fill(tester);
    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pumpAndSettle();
    expect(sent, hasLength(1));
    expect(sent.single.email, 'reader@example.test');
    expect(sent.single.name, 'Verified reader');
    expect(sent.single.message, 'The reviewed result did not answer my question.');
    expect(find.textContaining('Inbox delivery is not confirmed'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('report-send')))
        .onPressed, isNull);
    expect(find.text('The reviewed result did not answer my question.'), findsNothing);
  });

  testWidgets('Arabic failure and retry preserve consent without false success',
      (tester) async {
    var attempts = 0;
    await pumpReport(tester, arabic: true, submit: (report) async {
      attempts++;
      if (attempts == 1) throw const DiagnosisReportHttpException(503);
    });
    expect(Directionality.of(tester.element(find.byType(Scaffold))),
        TextDirection.rtl);
    await fill(tester);
    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pumpAndSettle();
    expect(find.text('إرسال البلاغات غير مهيأ أو غير متاح حاليًا.'), findsOneWidget);
    expect(attempts, 1);
    expect(tester.widget<FilledButton>(find.byKey(const Key('report-send')))
        .onPressed, isNotNull);

    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pumpAndSettle();
    expect(attempts, 2);
    expect(find.textContaining('لم يتم التأكد من وصوله'), findsOneWidget);
  });

  testWidgets('pending report disables duplicate submissions until transport replies',
      (tester) async {
    final complete = Completer<void>();
    var attempts = 0;
    await pumpReport(tester, submit: (_) {
      attempts++;
      return complete.future;
    });
    await fill(tester);
    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pump();
    expect(attempts, 1);
    expect(tester.widget<FilledButton>(find.byKey(const Key('report-send')))
        .onPressed, isNull);
    complete.complete();
    await tester.pumpAndSettle();
    expect(attempts, 1);
    expect(find.textContaining('Inbox delivery is not confirmed'), findsOneWidget);
  });

  testWidgets('AI failed-job report opens with no leaked prompt, code or bearer',
      (tester) async {
    final reports = <DiagnosisReportInput>[];
    await tester.pumpWidget(MaterialApp(home: AiTroubleshooterScreen(
      arabic: true,
      loginAction: (_, __) async => const MobileAiSession(
        token: '1|private-bearer', expiresAt: null,
      ),
      diagnoseAction: (_, __, ___, ____) async => const MobileAiJob(
        id: uuid, status: 'failed', progressPercent: 0,
        resultUrl: null, retryAfterSeconds: null,
      ),
      reportAction: (report) async { reports.add(report); },
    )));
    await tester.enterText(
        find.byKey(const Key('ai-login-email')), 'reader@example.test');
    await tester.enterText(
        find.byKey(const Key('ai-login-password')), 'private-password');
    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pumpAndSettle();

    await tester.enterText(
        find.byKey(const Key('ai-technology')), 'SQL Server');
    await tester.enterText(
        find.byKey(const Key('ai-question')), 'Sensitive technical question');
    await tester.enterText(
        find.byKey(const Key('ai-code')), 'PRIVATE_DIAGNOSIS_CODE');
    await reveal(tester, 'ai-consent');
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, 'ai-diagnose-submit');
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    await reveal(tester, 'ai-report-error');
    await tester.tap(find.byKey(const Key('ai-report-error')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('report-name')), findsOneWidget);
    final emailField = tester.widget<TextField>(
        find.byKey(const Key('report-email')));
    expect(emailField.controller!.text, 'reader@example.test');
    expect(find.text('PRIVATE_DIAGNOSIS_CODE'), findsNothing);
    expect(find.text('Sensitive technical question'), findsNothing);
    expect(find.text('1|private-bearer'), findsNothing);

    await fill(tester);
    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pumpAndSettle();
    expect(reports, hasLength(1));
    final body = reports.single.toJson().toString();
    expect(body, isNot(contains('PRIVATE_DIAGNOSIS_CODE')));
    expect(body, isNot(contains('Sensitive technical question')));
    expect(body, isNot(contains('private-bearer')));
    expect(body, isNot(contains(uuid)));
    expect(find.textContaining('لم يتم التأكد من وصوله'), findsOneWidget);
  });

  testWidgets('rate limits surface a safe retry path', (tester) async {
    await pumpReport(tester, submit: (_) async {
      throw const DiagnosisReportHttpException(429);
    });
    await fill(tester);
    await tester.tap(find.byKey(const Key('report-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('rate limit reached'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('report-send')))
        .onPressed, isNotNull);
  });
}

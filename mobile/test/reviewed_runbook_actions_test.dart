import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:waleed_tech_companion/runbooks/reviewed_runbook_actions.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';

const runbookId = '00000000-0000-4000-8000-000000000777';
const bearer = '7|memory-only-mobile-token';

ReviewedRunbook reviewed({
  String title = 'Reviewed recovery plan',
  String body = '# Reviewed steps\n1. Verify the current backup.',
  int version = 4,
}) =>
    ReviewedRunbook(
      id: runbookId,
      title: title,
      markdown: body,
      version: version,
      validatedAt: DateTime.utc(2026, 11, 1, 12),
    );

void main() {
  Future<void> pumpDetail(
    WidgetTester tester, {
    bool arabic = false,
    String? initialBearer,
    Future<ReviewedRunbook> Function(String id)? loader,
    ReviewedRunbookLoginAction? loginAction,
    ReviewedRunbookSaveAction? saveAction,
    ReviewedRunbookShareAction? shareAction,
  }) async {
    await tester.pumpWidget(MaterialApp(
      home: ReviewedRunbookDetail(
        id: runbookId,
        arabic: arabic,
        initialBearer: initialBearer,
        loader: loader ?? (_) async => reviewed(),
        loginAction: loginAction,
        saveAction: saveAction,
        shareAction: shareAction,
      ),
    ));
    await tester.pumpAndSettle();
  }

  Future<void> reveal(WidgetTester tester, Key key) async {
    final target = find.byKey(key);
    final scrollable = find.descendant(
      of: find.byType(ListView),
      matching: find.byType(Scrollable),
    ).first;
    await tester.scrollUntilVisible(
      target,
      220,
      scrollable: scrollable,
    );
    await tester.ensureVisible(target);
    await tester.pumpAndSettle();
  }

  testWidgets('fresh reviewed detail exposes real copy save and share actions',
      (tester) async {
    String? sharedText;
    String? sharedTitle;
    await pumpDetail(
      tester,
      initialBearer: bearer,
      saveAction: (_, __) async {},
      shareAction: (text, title) async {
        sharedText = text;
        sharedTitle = title;
        return true;
      },
    );

    expect(find.byKey(const Key('online-runbook-body')), findsOneWidget);
    await reveal(tester, const Key('online-runbook-copy'));
    expect(find.byKey(const Key('online-runbook-copy')), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-save')), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-share')), findsOneWidget);

    await tester.tap(find.byKey(const Key('online-runbook-copy')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('online-runbook-action-message'), skipOffstage: false), findsOneWidget);
    expect(find.text('Reviewed steps copied.', skipOffstage: false), findsOneWidget);

    await tester.tap(find.byKey(const Key('online-runbook-share')));
    await tester.pumpAndSettle();
    expect(sharedTitle, 'Reviewed recovery plan');
    expect(sharedText, contains('Reviewed recovery plan'));
    expect(sharedText, contains('# Reviewed steps'));
    expect(sharedText, contains('Reviewed version: 4'));
    expect(sharedText, isNot(contains(bearer)));
    expect(find.text('Share options opened.', skipOffstage: false), findsOneWidget);
  });

  testWidgets('guest save authenticates once then uses real server save action',
      (tester) async {
    var loginCalls = 0;
    var saveCalls = 0;
    String? receivedEmail;
    String? receivedPassword;
    String? receivedBearer;
    String? receivedId;

    await pumpDetail(
      tester,
      loginAction: (email, password) async {
        loginCalls++;
        receivedEmail = email;
        receivedPassword = password;
        return const RunbookSession(token: bearer, expiresAt: null);
      },
      saveAction: (token, id) async {
        saveCalls++;
        receivedBearer = token;
        receivedId = id;
      },
      shareAction: (_, __) async => true,
    );

    await reveal(tester, const Key('online-runbook-save'));
    await tester.tap(find.byKey(const Key('online-runbook-save')));
    await tester.pumpAndSettle();

    expect(find.text('Sign in to save'), findsOneWidget);
    expect(saveCalls, 0);
    await tester.enterText(
      find.byKey(const Key('online-runbook-login-email')),
      'reader@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('online-runbook-login-password')),
      'PRIVATE_PASSWORD_NOT_FOR_UI',
    );
    await tester.tap(find.byKey(const Key('online-runbook-login-submit')));
    await tester.pumpAndSettle();

    expect(loginCalls, 1);
    expect(receivedEmail, 'reader@example.test');
    expect(receivedPassword, 'PRIVATE_PASSWORD_NOT_FOR_UI');
    expect(saveCalls, 1);
    expect(receivedBearer, bearer);
    expect(receivedId, runbookId);
    expect(find.text('PRIVATE_PASSWORD_NOT_FOR_UI'), findsNothing);
    expect(find.text(bearer), findsNothing);

    await reveal(tester, const Key('online-runbook-save'));
    expect(find.text('Saved'), findsOneWidget);
    final button = tester.widget<OutlinedButton>(
      find.byKey(const Key('online-runbook-save')),
    );
    expect(button.onPressed, isNull);
  });

  testWidgets('preauthenticated diagnosis save never re-prompts for credentials',
      (tester) async {
    var loginCalls = 0;
    var saveCalls = 0;
    await pumpDetail(
      tester,
      initialBearer: bearer,
      loginAction: (_, __) async {
        loginCalls++;
        return const RunbookSession(token: 'unexpected', expiresAt: null);
      },
      saveAction: (token, id) async {
        saveCalls++;
        expect(token, bearer);
        expect(id, runbookId);
      },
      shareAction: (_, __) async => true,
    );

    await reveal(tester, const Key('online-runbook-save'));
    await tester.tap(find.byKey(const Key('online-runbook-save')));
    await tester.pumpAndSettle();

    expect(loginCalls, 0);
    expect(saveCalls, 1);
    expect(find.byKey(const Key('online-runbook-login-email')), findsNothing);
    expect(find.text(bearer), findsNothing);
  });

  testWidgets('failed login is generic and does not expose password or backend detail',
      (tester) async {
    var saveCalls = 0;
    await pumpDetail(
      tester,
      loginAction: (_, __) async {
        throw const RunbookActionHttpException(401);
      },
      saveAction: (_, __) async {
        saveCalls++;
      },
      shareAction: (_, __) async => true,
    );

    await reveal(tester, const Key('online-runbook-save'));
    await tester.tap(find.byKey(const Key('online-runbook-save')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('online-runbook-login-email')),
      'reader@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('online-runbook-login-password')),
      'VERY_PRIVATE_PASSWORD',
    );
    await tester.tap(find.byKey(const Key('online-runbook-login-submit')));
    await tester.pumpAndSettle();

    expect(saveCalls, 0);
    expect(
      find.text('Could not sign in with those credentials.'),
      findsOneWidget,
    );
    expect(find.text('VERY_PRIVATE_PASSWORD'), findsNothing);
    expect(find.textContaining('401'), findsNothing);
  });

  testWidgets('withdrawn detail exposes no copy save or share controls',
      (tester) async {
    await pumpDetail(
      tester,
      loader: (_) async => throw const FormatException('PRIVATE_REVOKE_REASON'),
      loginAction: (_, __) async =>
          const RunbookSession(token: bearer, expiresAt: null),
      saveAction: (_, __) async {},
      shareAction: (_, __) async => true,
    );

    expect(
      find.byKey(const Key('online-runbook-detail-unavailable')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('online-runbook-copy')), findsNothing);
    expect(find.byKey(const Key('online-runbook-save')), findsNothing);
    expect(find.byKey(const Key('online-runbook-share')), findsNothing);
    expect(find.textContaining('PRIVATE_REVOKE_REASON'), findsNothing);
  });

  testWidgets('Arabic actions remain RTL and share only reviewed public content',
      (tester) async {
    String? shared;
    await pumpDetail(
      tester,
      arabic: true,
      initialBearer: bearer,
      saveAction: (_, __) async {},
      shareAction: (text, _) async {
        shared = text;
        return true;
      },
      loader: (_) async => reviewed(
        title: 'خطة استعادة مراجعة',
        body: '# خطوات مراجعة\n١. تحقق من النسخة الحالية.',
        version: 5,
      ),
    );

    expect(
      Directionality.of(tester.element(find.text('تفاصيل الحل'))),
      TextDirection.rtl,
    );
    await reveal(tester, const Key('online-runbook-share'));
    expect(find.text('نسخ الخطوات'), findsOneWidget);
    expect(find.text('حفظ'), findsOneWidget);
    expect(find.text('مشاركة'), findsOneWidget);

    await tester.tap(find.byKey(const Key('online-runbook-share')));
    await tester.pumpAndSettle();
    expect(shared, contains('خطة استعادة مراجعة'));
    expect(shared, contains('إصدار مراجع: 5'));
    expect(shared, isNot(contains(bearer)));
    expect(find.text('تم فتح خيارات المشاركة.'), findsOneWidget);
  });
}

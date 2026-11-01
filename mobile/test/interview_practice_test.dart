import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';
import 'package:waleed_tech_companion/interview/interview_practice.dart';
import 'package:waleed_tech_companion/learning/verified_learning_paths.dart';
import 'package:waleed_tech_companion/runbooks/reviewed_runbook_actions.dart';

const pathId = '00000000-0000-4000-8000-000000000555';
const sessionId = '00000000-0000-4000-8000-000000000999';
const bearer = '7|PRIVATE_MOBILE_TOKEN';

String startJson({bool valid = true}) => jsonEncode({
  'data': {
    'session_id': sessionId,
    'path_id': valid ? pathId : 'not-a-public-path',
    'question': 'Explain how to apply database recovery.',
    'context': 'Verify backup recovery before restoring access.',
    'status': 'awaiting_answer',
    'progress': {'answered': 0, 'total': 1},
    'feedback': null,
  },
});
String answerJson({bool valid = true}) => jsonEncode({
  'data': {
    'session_id': sessionId,
    'status': 'answered',
    'progress': {'answered': 1, 'total': 1},
    'feedback': {
      'type': valid ? 'guided_self_review' : 'ai_scored',
      'message': 'No AI evaluation was performed.',
      'review_source': 'Verify backup recovery before restoring access.',
    },
  },
});

VerifiedLearningPage tracks([int page = 1]) => VerifiedLearningPage(
  tracks: [
    VerifiedLearningTrack(
      id: page == 1 ? pathId : '00000000-0000-4000-8000-000000000556',
      slug: page == 1 ? 'recovery-track' : 'security-track',
      title: page == 1 ? 'Verified database lessons' : 'Verified security lessons',
      lessons: const [
        VerifiedLesson(title: 'Database recovery',
            description: 'Verify a restore rehearsal'),
        VerifiedLesson(title: 'Transaction isolation',
            description: 'Protect concurrent transactions'),
      ],
    ),
  ],
  currentPage: page,
  hasMorePages: page == 1,
);

void main() {
  test('strict parsing rejects untrusted session, score and mismatched IDs', () {
    final started = InterviewStarted.parse(startJson(), pathId);
    expect(started.id, sessionId);
    expect(started.context, contains('Verify backup'));
    expect(() => InterviewStarted.parse(startJson(valid: false), pathId),
        throwsFormatException);
    expect(() => InterviewStarted.parse(startJson(), 'invalid'),
        throwsFormatException);
    expect(() => InterviewStarted.parse('{"data":{},"meta":{}}', pathId),
        throwsFormatException);
    final feedback = InterviewFeedback.parse(answerJson(), sessionId);
    expect(feedback.message, contains('No AI'));
    expect(() => InterviewFeedback.parse(answerJson(), pathId),
        throwsFormatException);
    expect(() => InterviewFeedback.parse(answerJson(valid: false), sessionId),
        throwsFormatException);
    expect(() => InterviewFeedback.parse(answerJson().replaceFirst(
        '"status":"answered"', '"status":"awaiting_answer"'), sessionId),
        throwsFormatException);
  });

  testWidgets('home offers a real entry with honest unconfigured state',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.scrollUntilVisible(
        find.byKey(const Key('interview-practice-entry')), 140);
    await tester.ensureVisible(find.byKey(const Key('interview-practice-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('interview-practice-entry')));
    await tester.pumpAndSettle();
    expect(find.text('Interview trainer'), findsOneWidget);
    expect(find.byKey(const Key('interview-unconfigured')), findsOneWidget);
    expect(find.textContaining('privately to your account'), findsOneWidget);
  });

  Future<void> login(WidgetTester tester, String password) async {
    await tester.enterText(find.byKey(const Key('interview-email')),
        'reader@example.test');
    await tester.enterText(find.byKey(const Key('interview-password')), password);
    await tester.tap(find.byKey(const Key('interview-sign-in')));
    await tester.pumpAndSettle();
  }

  testWidgets('authenticated journey selects verified lesson, stores consented answer, reviews, logs out',
      (tester) async {
    var loginCalls = 0;
    var answers = 0;
    var logouts = 0;
    final visited = <int>[];
    await tester.pumpWidget(MaterialApp(home: InterviewPracticeScreen(
      arabic: false,
      loginAction: (email, password) async {
        loginCalls++;
        expect(email, 'reader@example.test');
        expect(password, 'LOCAL_SECRET_NOT_FOR_UI');
        return RunbookSession(token: bearer, expiresAt: DateTime.utc(2100, 1, 1));
      },
      tracksAction: (arabic, page) async {
        expect(arabic, false);
        visited.add(page);
        return tracks(page);
      },
      startAction: (token, arabic, id, lesson) async {
        expect(token, bearer);
        expect(arabic, false);
        expect(id, pathId);
        expect(lesson, 0);
        return InterviewStarted.parse(startJson(), id);
      },
      answerAction: (token, id, answer) async {
        answers++;
        expect(token, bearer);
        expect(id, sessionId);
        expect(answer, 'PRIVATE_CONSENTED_PRACTICE_ANSWER');
        return InterviewFeedback.parse(answerJson(), id);
      },
      logoutAction: (token) async {
        expect(token, bearer);
        logouts++;
      },
    )));
    await tester.pumpAndSettle();
    await login(tester, 'LOCAL_SECRET_NOT_FOR_UI');
    expect(loginCalls, 1);
    expect(visited, [1]);
    expect(find.text('Verified database lessons'), findsOneWidget);
    expect(find.text('Database recovery'), findsOneWidget);
    expect(find.text('LOCAL_SECRET_NOT_FOR_UI'), findsNothing);

    await tester.ensureVisible(find.byKey(const Key('interview-start-recovery-track-0')));
    await tester.tap(find.byKey(const Key('interview-start-recovery-track-0')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('interview-question')), findsOneWidget);
    expect(find.text('Explain how to apply database recovery.'), findsOneWidget);
    expect(find.textContaining('Your answer is sent to the server'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('interview-answer')),
        'PRIVATE_CONSENTED_PRACTICE_ANSWER');
    await tester.ensureVisible(find.byKey(const Key('interview-submit')));
    await tester.tap(find.byKey(const Key('interview-submit')));
    await tester.pumpAndSettle();
    expect(answers, 0);
    expect(find.byKey(const Key('interview-error')), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('interview-consent')));
    await tester.tap(find.byKey(const Key('interview-consent')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('interview-submit')));
    await tester.tap(find.byKey(const Key('interview-submit')));
    await tester.pumpAndSettle();
    expect(answers, 1);
    expect(find.byKey(const Key('interview-feedback')), findsOneWidget);
    expect(find.text('No AI evaluation was performed.'), findsOneWidget);
    expect(find.text('PRIVATE_CONSENTED_PRACTICE_ANSWER'), findsNothing);
    await tester.ensureVisible(find.byKey(const Key('interview-next')));
    await tester.tap(find.byKey(const Key('interview-next')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('interview-feedback')), findsNothing);

    await tester.ensureVisible(find.byKey(const Key('interview-more')));
    await tester.tap(find.byKey(const Key('interview-more')));
    await tester.pumpAndSettle();
    expect(visited, [1, 2]);
    expect(find.text('Verified security lessons'), findsOneWidget);
    // A newly paginated lesson list changes the sign-out button's viewport.
    // Settle the scroll/layout before the hit test; otherwise the button may
    // still be below the 800x600 widget-test viewport when tapped.
    await tester.ensureVisible(find.byKey(const Key('interview-sign-out')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('interview-sign-out')));
    await tester.pumpAndSettle();
    expect(logouts, 1);
    expect(find.byKey(const Key('interview-email')), findsOneWidget);
    expect(find.text(bearer), findsNothing);
  });

  testWidgets('Arabic RTL, server failure, retry and session expiry are safe',
      (tester) async {
    var launches = 0;
    var logins = 0;
    await tester.pumpWidget(MaterialApp(home: InterviewPracticeScreen(
      arabic: true,
      loginAction: (_, __) async {
        logins++;
        return RunbookSession(token: logins == 1 ? 'EXPIRED_SECRET' : bearer,
            expiresAt: logins == 1
                ? DateTime.utc(2000) : DateTime.utc(2100));
      },
      tracksAction: (_, __) async => tracks(),
      startAction: (token, arabic, id, _) async {
        expect(arabic, true);
        expect(token, bearer);
        launches++;
        if (launches == 1) {
          throw const FormatException('PRIVATE_BACKEND_TRACE');
        }
        return InterviewStarted.parse(startJson(), id);
      },
      answerAction: (_, id, __) async =>
          InterviewFeedback.parse(answerJson(), id),
      logoutAction: (_) async {},
    )));
    await tester.pumpAndSettle();
    await login(tester, 'FIRST_PASSWORD_SECRET');
    expect(find.byKey(const Key('interview-error')), findsOneWidget);
    expect(find.text('EXPIRED_SECRET'), findsNothing);
    expect(find.byKey(const Key('interview-email')), findsOneWidget);
    await login(tester, 'SECOND_PASSWORD_SECRET');
    expect(Directionality.of(tester.element(find.text('تدريب المقابلات'))),
        TextDirection.rtl);
    await tester.ensureVisible(find.byKey(const Key('interview-start-recovery-track-0')));
    await tester.tap(find.byKey(const Key('interview-start-recovery-track-0')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('interview-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_BACKEND_TRACE'), findsNothing);
    await tester.tap(find.byKey(const Key('interview-start-recovery-track-0')));
    await tester.pumpAndSettle();
    expect(launches, 2);
    expect(find.byKey(const Key('interview-question')), findsOneWidget);
    expect(find.text(bearer), findsNothing);
    expect(find.textContaining('SECOND_PASSWORD_SECRET'), findsNothing);
  });
}

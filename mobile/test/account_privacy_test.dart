import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/account/account_privacy.dart';
import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';

const bearer = '1|privacy-test-token';
const profileId = '00000000-0000-4000-8000-000000000091';
const pathId = '00000000-0000-4000-8000-000000000092';
const interviewId = '00000000-0000-4000-8000-000000000093';
const pendingId = '00000000-0000-4000-8000-000000000094';

String matrixResponse({bool enabled = false, bool duplicate = false}) {
  final rows = <Map<String, dynamic>>[];
  for (final purpose in AccountConsentMatrix.purposes) {
    for (final channel in AccountConsentMatrix.channels) {
      rows.add({
        'purpose': purpose,
        'channel': channel,
        'enabled': enabled,
        'consented_at': enabled ? '2026-11-02T10:00:00+00:00' : null,
        'revoked_at': null,
      });
    }
  }
  if (duplicate) rows[5] = Map.of(rows[0]);
  return jsonEncode({'data': rows, 'meta': {
    'default_deny': true,
    'purposes': AccountConsentMatrix.purposes,
    'channels': AccountConsentMatrix.channels,
  }});
}

String exportResponse() => jsonEncode({
  'data': {
    'profile': {
      'id': profileId, 'email': 'reader@example.org',
      'status': 'active', 'created_at': '2026-11-01T00:00:00Z',
    },
    'saved_resources': [],
    // Actual account-export API always includes private_interviews, even [].
    // It exports only the account owner's writing, never withdrawn lessons.
    'private_interviews': [
      {
        'session_id': interviewId,
        'path_id': pathId,
        'lesson_index': 0,
        'locale': 'ar',
        'answer_text': 'PRIVATE_USER_AUTHORED_ANSWER',
        'answered_at': '2026-11-02 10:30:00',
        'created_at': '2026-11-02 10:00:00',
      },
      {
        'session_id': pendingId,
        'path_id': pathId,
        'lesson_index': 1,
        'locale': 'en',
        'answer_text': null,
        'answered_at': null,
        'created_at': '2026-11-01 09:00:00',
      },
    ],
    'notification_preferences': [],
  },
  'meta': {
    'exported_at': '2026-11-02T11:00:00Z',
    'secrets_excluded': true,
    'private_ai_prompts_excluded': true,
    'private_interview_answers_included': true,
    'foreign_user_data_excluded': true,
  },
});

Future<void> visibleTap(WidgetTester tester, String key) async {
  final target = find.byKey(Key(key));
  // Sliver lists build distant controls lazily; scroll until they exist
  // before asking the test harness to make them visible.
  if (target.evaluate().isEmpty) {
    await tester.scrollUntilVisible(
      target, 160, scrollable: find.byType(Scrollable).first,
    );
  }
  await tester.ensureVisible(target);
  await tester.pumpAndSettle();
  await tester.tap(target);
  await tester.pumpAndSettle();
}

void main() {
  test('consent is six explicit default-deny entries; invalid data fails closed', () {
    final matrix = AccountConsentMatrix.parse(matrixResponse());
    expect(matrix.entries.length, 6);
    expect(matrix.entries.every((e) => !e.enabled), isTrue);
    expect(() => AccountConsentMatrix.parse(matrixResponse(duplicate: true)),
        throwsFormatException);
    final unsafe = jsonDecode(matrixResponse()) as Map<String, dynamic>;
    (unsafe['data'] as List)[0]['token'] = 'private-key';
    expect(() => AccountConsentMatrix.parse(jsonEncode(unsafe)), throwsFormatException);
    (unsafe['data'] as List)[0].remove('token');
    (unsafe['data'] as List)[0]['enabled'] = true;
    expect(() => AccountConsentMatrix.parse(jsonEncode(unsafe)), throwsFormatException);
    unsafe['meta']['default_deny'] = false;
    expect(() => AccountConsentMatrix.parse(jsonEncode(unsafe)), throwsFormatException);
    expect(() => AccountConsentMatrix.parse('x' * 65537), throwsFormatException);
  });

  test('portable export excludes untrusted shapes and oversized data', () {
    final export = AccountPortableExport.parse(exportResponse());
    expect(export.savedCount, 0);
    expect(export.interviewCount, 2);
    expect(export.answerCount, 1);
    expect(export.raw, contains('reader@example.org'));
    expect(export.raw, contains('PRIVATE_USER_AUTHORED_ANSWER'));
    final malformed = jsonDecode(exportResponse()) as Map<String, dynamic>;
    malformed['data']['password_hash'] = 'secret';
    expect(() => AccountPortableExport.parse(jsonEncode(malformed)), throwsFormatException);
    malformed['data'].remove('password_hash');
    malformed['meta']['foreign_user_data_excluded'] = false;
    expect(() => AccountPortableExport.parse(jsonEncode(malformed)), throwsFormatException);
    expect(() => AccountPortableExport.parse('x' * (2 * 1024 * 1024 + 1)),
        throwsFormatException);
  });


  test('privacy archive rejects legacy missing interview field and unsafe flags', () {
    final missing = jsonDecode(exportResponse()) as Map<String, dynamic>;
    (missing['data'] as Map).remove('private_interviews');
    expect(() => AccountPortableExport.parse(jsonEncode(missing)),
        throwsFormatException);
    final noProof = jsonDecode(exportResponse()) as Map<String, dynamic>;
    (noProof['meta'] as Map)['private_interview_answers_included'] = false;
    expect(() => AccountPortableExport.parse(jsonEncode(noProof)),
        throwsFormatException);
    (noProof['meta'] as Map)['private_interview_answers_included'] = true;
    (noProof['meta'] as Map)['token'] = 'UNTRUSTED_SECRET';
    expect(() => AccountPortableExport.parse(jsonEncode(noProof)),
        throwsFormatException);
  });

  test('private interview archive cannot copy duplicate, foreign or unreviewed fields', () {
    final payload = jsonDecode(exportResponse()) as Map<String, dynamic>;
    final rows = (payload['data'] as Map)['private_interviews'] as List;
    rows[0]['lesson_context'] = 'NEVER_COPY_REVOKED_SOURCE';
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    rows[0].remove('lesson_context');
    rows[1]['session_id'] = interviewId;
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    rows[1]['session_id'] = pendingId;
    rows[0]['answer_text'] = 'AUTHORED_ANSWER';
    rows[0]['answered_at'] = null;
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    rows[0]['answer_text'] = null;
    rows[0]['answered_at'] = '2026-11-02 10:30:00';
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    rows[0]['answered_at'] = null;
    rows[0]['lesson_index'] = -1;
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    rows[0]['lesson_index'] = 0;
    rows[0]['locale'] = 'unreviewed-locale';
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
  });

  test('portable export validates nested saved and preference rows', () {
    final payload = jsonDecode(exportResponse()) as Map<String, dynamic>;
    final data = payload['data'] as Map;
    data['saved_resources'] = [
      {'resource_type': 'article', 'resource_id': pathId,
       'saved_at': '2026-11-02 11:00:00'},
    ];
    expect(AccountPortableExport.parse(jsonEncode(payload)).savedCount, 1);
    (data['saved_resources'] as List)[0]['url'] = 'https://private.test';
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    (data['saved_resources'] as List)[0].remove('url');
    data['notification_preferences'] = [
      {'purpose': 'editorial_updates', 'channel': 'email',
       'enabled': true, 'consented_at': null, 'revoked_at': null},
    ];
    expect(() => AccountPortableExport.parse(jsonEncode(payload)),
        throwsFormatException);
    (data['notification_preferences'] as List)[0]['consented_at'] =
        '2026-11-02T10:00:00Z';
    expect(AccountPortableExport.parse(jsonEncode(payload)).interviewCount, 2);
  });

  testWidgets('malformed private interview archive does not offer clipboard export',
      (tester) async {
    final payload = jsonDecode(exportResponse()) as Map<String, dynamic>;
    ((payload['data'] as Map)['private_interviews'] as List)[0]['bearer'] =
        'NEVER_COPY_ACCESS_TOKEN';
    await tester.pumpWidget(MaterialApp(home: MobileAccountPrivacyScreen(
      arabic: false,
      bearer: bearer,
      onDeleted: () => fail('No deletion'),
      onUnauthorized: () => fail('Not unauthorized'),
      readAction: (_) async => AccountConsentMatrix.parse(matrixResponse()),
      exportAction: (_) async => jsonEncode(payload),
    )));
    await tester.pumpAndSettle();
    await visibleTap(tester, 'privacy-export');
    expect(find.byKey(const Key('privacy-copy')), findsNothing);
    expect(find.textContaining('NEVER_COPY_ACCESS_TOKEN'), findsNothing);
    // The rejection notice sits above the export action in a scrollable
    // viewport; bring it into view before asserting its user-facing text.
    await tester.drag(find.byType(ListView), const Offset(0, 700));
    await tester.pumpAndSettle();
    expect(find.textContaining('Request not confirmed'), findsOneWidget);
  });

  for (final arabic in [false, true]) {
    testWidgets('consent round-trip and export require actual server receipt ' +
        (arabic ? 'AR' : 'EN'), (tester) async {
      var saves = 0;
      var exports = 0;
      var current = false;
      await tester.pumpWidget(MaterialApp(home: MobileAccountPrivacyScreen(
        arabic: arabic,
        bearer: bearer,
        onDeleted: () => fail('No deletion requested'),
        onUnauthorized: () => fail('Not unauthorized'),
        readAction: (token) async {
          expect(token, bearer);
          return AccountConsentMatrix.parse(matrixResponse(enabled: current));
        },
        saveAction: (token, purpose, channel, value) async {
          expect(token, bearer);
          expect(purpose, 'account_activity');
          expect(channel, 'email');
          saves++;
          if (saves == 1) throw const MobileAiHttpException(503);
          current = value;
          return AccountConsentMatrix.parse(matrixResponse(enabled: value));
        },
        exportAction: (token) async {
          expect(token, bearer);
          exports++;
          return exportResponse();
        },
      )));
      await tester.pumpAndSettle();

      final toggleKey = 'privacy-account_activity-email';
      expect(tester.widget<SwitchListTile>(find.byKey(Key(toggleKey))).value, false);
      await visibleTap(tester, toggleKey);
      expect(saves, 1);
      expect(tester.widget<SwitchListTile>(find.byKey(Key(toggleKey))).value, false);
      // Status is above the scroll viewport after tapping the switch;
      // the persisted false value is the essential fail-closed assertion.
      await visibleTap(tester, toggleKey);
      expect(saves, 2);
      expect(tester.widget<SwitchListTile>(find.byKey(Key(toggleKey))).value, true);
      await visibleTap(tester, 'privacy-export');
      expect(exports, 1);
      expect(find.byKey(const Key('privacy-copy')), findsOneWidget);
      expect(find.text('reader@example.org'), findsNothing);
      expect(find.text('PRIVATE_USER_AUTHORED_ANSWER'), findsNothing);
      expect(find.text(bearer), findsNothing);
    });
  }

  testWidgets('account deletion requires typed DELETE and reauthentication',
      (tester) async {
    var deletes = 0;
    var completed = 0;
    await tester.pumpWidget(MaterialApp(
      home: Builder(builder: (context) => Scaffold(body: Center(
        child: FilledButton(
          key: const Key('open-test-privacy'),
          onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
            builder: (_) => MobileAccountPrivacyScreen(
              arabic: false,
              bearer: bearer,
              onDeleted: () => completed++,
              onUnauthorized: () => fail('Unexpected authorization failure'),
              readAction: (_) async => AccountConsentMatrix.parse(matrixResponse()),
              deleteAction: (token, password) async {
                expect(token, bearer);
                expect(password, 'verified-password');
                deletes++;
              },
            ),
          )),
          child: const Text('Open privacy'),
        ),
      ))),
    ));
    await visibleTap(tester, 'open-test-privacy');
    await visibleTap(tester, 'privacy-delete-open');
    expect(tester.widget<FilledButton>(
      find.byKey(const Key('privacy-delete-confirm'))).onPressed, isNull);
    await tester.enterText(
        find.byKey(const Key('privacy-delete-password')), 'verified-password');
    await tester.enterText(find.byKey(const Key('privacy-delete-word')), 'NO');
    await tester.pump();
    expect(tester.widget<FilledButton>(
      find.byKey(const Key('privacy-delete-confirm'))).onPressed, isNull);
    await tester.enterText(find.byKey(const Key('privacy-delete-word')), 'DELETE');
    await tester.pump();
    expect(tester.widget<FilledButton>(
      find.byKey(const Key('privacy-delete-confirm'))).onPressed, isNotNull);
    await visibleTap(tester, 'privacy-delete-confirm');
    expect(deletes, 1);
    expect(completed, 1);
    expect(find.byKey(const Key('open-test-privacy')), findsOneWidget);
    expect(find.text('verified-password'), findsNothing);
  });

  testWidgets('unauthorized consent read revokes local screen and cannot retry',
      (tester) async {
    var denied = 0;
    await tester.pumpWidget(MaterialApp(home: MobileAccountPrivacyScreen(
      arabic: true,
      bearer: bearer,
      onDeleted: () => fail('No deletion'),
      onUnauthorized: () => denied++,
      readAction: (_) async => throw const MobileAiHttpException(401),
    )));
    await tester.pumpAndSettle();
    expect(denied, 1);
    expect(find.byKey(const Key('privacy-retry')), findsNothing);
    expect(find.text(bearer), findsNothing);
    expect(tester.widget<OutlinedButton>(
      find.byKey(const Key('privacy-delete-open'))).onPressed, isNull);
  });
}

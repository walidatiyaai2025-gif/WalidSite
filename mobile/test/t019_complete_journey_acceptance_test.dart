import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';
import 'package:waleed_tech_companion/articles/verified_articles.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';
import 'package:waleed_tech_companion/runbooks/verified_online_runbooks.dart';
import 'package:waleed_tech_companion/solutions/offline_solution_finder.dart';

void main() {
  const token = '1|t019-final-acceptance-token';
  const jobId = '11111111-1111-4111-8111-111111111111';
  const runbookId = '22222222-2222-4222-8222-222222222222';

  const offlineGuide = OfflineRunbook(
    id: 'database-recovery',
    titleEn: 'Database recovery checklist',
    titleAr: 'قائمة استعادة قاعدة البيانات',
    stepsEn: <String>[
      'Verify the latest safe backup',
      'Review database recovery state',
    ],
    stepsAr: <String>[
      'تحقق من أحدث نسخة احتياطية آمنة',
      'راجع حالة استعادة قاعدة البيانات',
    ],
  );

  const offlinePack = OfflineRunbookPack(
    version: 'acceptance-1',
    source: 'Bundled educational draft',
    lastSynced: null,
    verifiedAt: null,
    items: <OfflineRunbook>[offlineGuide],
  );

  Future<void> reveal(WidgetTester tester, Key key) async {
    final finder = find.byKey(key);
    final scrollable = find.descendant(
      of: find.byType(ListView),
      matching: find.byType(Scrollable),
    ).first;
    await tester.scrollUntilVisible(finder, 220, scrollable: scrollable);
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
  }

  Future<void> login(WidgetTester tester) async {
    await tester.enterText(
      find.byKey(const Key('ai-login-email')),
      'reader@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('ai-login-password')),
      'correct horse battery staple',
    );
    await tester.tap(find.byKey(const Key('ai-login-submit')));
    await tester.pumpAndSettle();
  }

  test('T019 parsers and origins fail closed around reviewed public content', () {
    expect(
      VerifiedArticleReader.validatedOrigin('https://api.example.org'),
      isNotNull,
    );
    for (final unsafe in <String>[
      'http://api.example.org',
      'https://127.0.0.1',
      'https://database.internal',
      'https://user:pass@example.org',
      'https://api.example.org:8443',
    ]) {
      expect(
        VerifiedArticleReader.validatedOrigin(unsafe),
        isNull,
        reason: 'Unsafe mobile origin was accepted: $unsafe',
      );
    }

    final completed = MobileAiJob.parse(
      '{"data":{"id":"$jobId","status":"completed","progress_percent":100,'
      '"result_url":"/api/v1/mobile/solutions/$runbookId",'
      '"retry_after_seconds":null}}',
    );
    expect(completed.reviewedRunbookId(), runbookId);

    final external = MobileAiJob.parse(
      '{"data":{"id":"$jobId","status":"completed","progress_percent":100,'
      '"result_url":"https://evil.example/solutions/$runbookId",'
      '"retry_after_seconds":null}}',
    );
    expect(external.reviewedRunbookId(), isNull);

    final reviewed = ReviewedRunbook.fromJson(<String, Object?>{
      'id': runbookId,
      'title': 'Reviewed SQL recovery',
      'markdown': '# Verified steps\n1. Inspect recovery state.',
      'version': 3,
      'validated_at': '2026-11-01T10:00:00Z',
    });
    expect(reviewed.validatedAt.isUtc, isTrue);
    expect(
      () => ReviewedRunbook.fromJson(<String, Object?>{
        'id': runbookId,
        'title': 'Unreviewed',
        'markdown': 'Body',
        'version': 1,
        'validated_at': '2026-11-01 10:00:00',
      }),
      throwsFormatException,
    );

    expect(
      OfflineSolutionSearch.find(offlinePack, 'backup', arabic: false),
      <OfflineRunbook>[offlineGuide],
    );
    expect(
      OfflineSolutionSearch.find(offlinePack, 'احتياطية', arabic: true),
      <OfflineRunbook>[offlineGuide],
    );
    expect(
      OfflineSolutionSearch.find(offlinePack, 'backup', arabic: true),
      isEmpty,
      reason: 'Hidden English translations must not leak into Arabic search',
    );
  });

  testWidgets(
      'MOBILE-03 to MOBILE-04 requires consent, opens reviewed result and saves with the same session',
      (tester) async {
    var diagnoses = 0;
    var saves = 0;
    String? receivedQuestion;
    String? receivedCode;

    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: token, expiresAt: null),
        diagnoseAction: (bearer, question, technology, codeSnippet) async {
          diagnoses++;
          expect(bearer, token);
          expect(technology, 'SQL Server');
          receivedQuestion = question;
          receivedCode = codeSnippet;
          return const MobileAiJob(
            id: jobId,
            status: 'completed',
            progressPercent: 100,
            resultUrl: '/api/v1/mobile/solutions/$runbookId',
            retryAfterSeconds: null,
          );
        },
        saveAction: (bearer, id) async {
          saves++;
          expect(bearer, token);
          expect(id, runbookId);
        },
        detailLoader: (id) async {
          expect(id, runbookId);
          return ReviewedRunbook(
            id: id,
            title: 'Reviewed SQL recovery',
            markdown: '# Verified steps\n1. Inspect recovery state.',
            version: 3,
            validatedAt: DateTime.utc(2026, 11, 1, 10),
          );
        },
      ),
    ));
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
      'RESTORE DATABASE private_sample WITH RECOVERY;',
    );

    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pump();
    expect(diagnoses, 0);
    expect(
      find.text('Consent is required before sending data for processing.'),
      findsOneWidget,
    );

    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    expect(diagnoses, 1);
    expect(receivedQuestion, 'Why did recovery stop after restart?');
    expect(receivedCode, 'RESTORE DATABASE private_sample WITH RECOVERY;');
    expect(find.text(token), findsNothing);

    await reveal(tester, const Key('ai-open-solution'));
    await tester.tap(find.byKey(const Key('ai-open-solution')));
    await tester.pumpAndSettle();

    expect(find.text('Reviewed SQL recovery'), findsOneWidget);
    expect(find.byKey(const Key('online-runbook-body')), findsOneWidget);
    expect(find.textContaining('private_sample'), findsNothing);
    expect(find.text(token), findsNothing);

    await reveal(tester, const Key('online-runbook-save'));
    await tester.tap(find.byKey(const Key('online-runbook-save')));
    await tester.pumpAndSettle();
    expect(saves, 1);
    expect(find.text(token), findsNothing);
  });

  testWidgets(
      'MOBILE-03 unavailable state recovers into MOBILE-05/06 local guidance without sending private input',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: AiTroubleshooterScreen(
        arabic: true,
        offlinePack: SynchronousFuture<OfflineRunbookPack>(offlinePack),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('ai-unconfigured')), findsOneWidget);
    expect(find.byKey(const Key('ai-offline-recovery')), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('ai-unconfigured')))),
      TextDirection.rtl,
    );

    await tester.tap(find.byKey(const Key('ai-offline-recovery')));
    await tester.pumpAndSettle();

    expect(find.text('البحث عن حلول محلية'), findsOneWidget);
    expect(find.byKey(const Key('solution-pack-provenance')), findsOneWidget);
    expect(find.byKey(const Key('solution-stale-warning')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('solution-search-input')),
      'استعادة قاعدة',
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('solution-database-recovery')),
      findsOneWidget,
    );
    await tester.ensureVisible(
      find.byKey(const Key('solution-database-recovery')),
    );
    await tester.tap(find.byKey(const Key('solution-database-recovery')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('offline-runbook-detail')), findsOneWidget);
    expect(find.text('تحقق من أحدث نسخة احتياطية آمنة'), findsOneWidget);
    expect(find.byKey(const Key('runbook-stale-warning')), findsOneWidget);
    expect(find.text(token), findsNothing);
  });

  testWidgets(
      'MOBILE-03 quota failure stays authenticated and offers manual offline recovery',
      (tester) async {
    var calls = 0;
    await tester.pumpWidget(MaterialApp(
      home: AiTroubleshooterScreen(
        arabic: false,
        loginAction: (_, __) async =>
            const MobileAiSession(token: token, expiresAt: null),
        diagnoseAction: (_, __, ___, ____) async {
          calls++;
          throw const MobileAiHttpException(429);
        },
        offlinePack: SynchronousFuture<OfflineRunbookPack>(offlinePack),
      ),
    ));
    await login(tester);
    await tester.enterText(find.byKey(const Key('ai-technology')), 'Flutter');
    await tester.enterText(
      find.byKey(const Key('ai-question')),
      'Why is this request throttled?',
    );
    await reveal(tester, const Key('ai-consent'));
    await tester.tap(find.byKey(const Key('ai-consent')));
    await reveal(tester, const Key('ai-diagnose-submit'));
    await tester.tap(find.byKey(const Key('ai-diagnose-submit')));
    await tester.pumpAndSettle();

    expect(calls, 1);
    expect(find.text('Usage limit reached. Try again later.'), findsOneWidget);
    expect(find.byKey(const Key('ai-login-email')), findsNothing);
    await reveal(tester, const Key('ai-offline-recovery'));
    expect(find.byKey(const Key('ai-offline-recovery')), findsOneWidget);
    expect(find.text(token), findsNothing);
  });

  testWidgets(
      'MOBILE-07 keeps verified article search local and opens source-backed reading in RTL',
      (tester) async {
    const articles = <VerifiedArticle>[
      VerifiedArticle(
        slug: 'arabic-recovery',
        title: 'دليل استعادة قواعد البيانات',
        body: 'خطوات موثقة لاستعادة قواعد البيانات بعد التحقق من النسخة الآمنة.',
        citations: <String>['https://docs.example.org/database/recovery'],
      ),
      VerifiedArticle(
        slug: 'arabic-network',
        title: 'دليل الشبكة',
        body: 'خطوات موثقة للاتصال.',
        citations: <String>['https://docs.example.org/network'],
      ),
    ];
    var loads = 0;

    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: VerifiedArticleLibrary(
          arabic: true,
          loader: () {
            loads++;
            return SynchronousFuture<List<VerifiedArticle>>(articles);
          },
          detailLoader: (slug) => SynchronousFuture<VerifiedArticle>(
            articles.singleWhere((article) => article.slug == slug),
          ),
        ),
      ),
    ));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('article-search-input')),
      'استعادة النسخة',
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('article-arabic-recovery')), findsOneWidget);
    expect(find.byKey(const Key('article-arabic-network')), findsNothing);
    expect(loads, 1, reason: 'Search terms must remain on device');

    await tester.tap(find.byKey(const Key('article-arabic-recovery')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('verified-article-body')), findsOneWidget);
    expect(find.text('https://docs.example.org/database/recovery'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('verified-article-body')))),
      TextDirection.rtl,
    );
    expect(loads, 1);
  });
}

import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/github/verified_github_portfolio.dart';
import 'package:waleed_tech_companion/main.dart';

Map<String, dynamic> verifiedRepo({
  String name = 'public-owner/verified-repo',
  String? description = 'Reviewed public engineering source',
  String? language = 'Dart',
}) => {
  'full_name': name,
  'url': 'https://github.com/' + name,
  'description': description,
  'language': language,
  'status': 'verified',
  'verified_at': DateTime.now().toUtc().subtract(
    const Duration(minutes: 2),
  ).toIso8601String(),
  'source': 'github_public_rest',
};

String feed(List<Map<String, dynamic>> data, {bool arabic = false}) =>
    jsonEncode({
      'data': data,
      'meta': {'lang': arabic ? 'ar' : 'en', 'total': data.length},
    });

void main() {
  test('GitHub origin accepts public HTTPS and rejects unsafe build settings', () {
    expect(
      VerifiedGithubPortfolioReader.validatedOrigin('https://api.example.org')
          ?.host,
      'api.example.org',
    );
    for (final value in [
      '', 'http://example.org', 'https://localhost',
      'https://127.0.0.1', 'https://internal.corp',
      'https://db.lan', 'https://example.org:8443',
      'https://user:secret@example.org',
      'https://example.org/private', 'https://example.org?token=secret',
    ]) {
      expect(VerifiedGithubPortfolioReader.validatedOrigin(value), isNull,
          reason: value);
    }
  });

  test('verified GitHub feed preserves genuine public facts and both locales', () {
    final en = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    expect(en, hasLength(1));
    expect(en.single.fullName, 'public-owner/verified-repo');
    expect(en.single.url.toString(),
        'https://github.com/public-owner/verified-repo');
    expect(en.single.description, 'Reviewed public engineering source');
    expect(en.single.language, 'Dart');
    expect(en.single.verifiedAt.isUtc, isTrue);
    expect(
      VerifiedGithubPortfolio.parse(feed([verifiedRepo()], arabic: true),
          arabic: true).single.fullName,
      'public-owner/verified-repo',
    );
    expect(
      VerifiedGithubPortfolio.parse(feed([]), arabic: false),
      isEmpty,
    );
  });

  test('malformed, unpublished, misdirected or private JSON fails closed', () {
    final cases = <String>[
      '{}',
      jsonEncode({'data': 'invalid', 'meta': {'lang': 'en', 'total': 0}}),
      feed([verifiedRepo()], arabic: true),
      jsonEncode({'data': [verifiedRepo()], 'meta': {'lang': 'en', 'total': 2}}),
      feed([verifiedRepo()..['status'] = 'private']),
      feed([verifiedRepo()..['status'] = 'unavailable']),
      feed([verifiedRepo()..['source'] = 'unreviewed']),
      feed([verifiedRepo()..['description'] = 'x' * 2001]),
      feed([verifiedRepo()..['language'] = 'x' * 61]),
      feed([verifiedRepo()..['full_name'] = 'owner/private/extra']),
      feed([verifiedRepo()..['verified_at'] = 'not-a-date']),
      feed([verifiedRepo()..['verified_at'] =
          DateTime.now().toUtc().add(const Duration(hours: 1)).toIso8601String()]),
      feed([verifiedRepo()..['secret'] = 'do-not-expose']),
      feed([verifiedRepo()..['url'] = 'http://github.com/public-owner/verified-repo']),
      feed([verifiedRepo()..['url'] = 'https://github.com@internal.local/public-owner/verified-repo']),
      feed([verifiedRepo()..['url'] = 'https://evil.example.org/public-owner/verified-repo']),
      feed([verifiedRepo()..['url'] = 'https://github.com:8443/public-owner/verified-repo']),
      feed([verifiedRepo()..['url'] = 'https://github.com/public-owner/verified-repo?token=private']),
      feed([verifiedRepo()..['url'] = 'https://github.com/public-owner/verified-repo#token']),
      feed([verifiedRepo()..['url'] = 'https://github.com/public-owner/other-repo']),
      feed([verifiedRepo()..['url'] = 'https://github.com/public-owner/%76erified-repo']),
      feed([verifiedRepo(), verifiedRepo()]),
      feed(List.generate(21, (i) => verifiedRepo(name: 'owner/repo-' + i.toString()))),
      'x' * (1024 * 1024 + 1),
    ];
    for (final bad in cases) {
      expect(
        () => VerifiedGithubPortfolio.parse(bad, arabic: false),
        throwsFormatException,
        reason: bad.length > 300 ? bad.substring(0, 300) : bad,
      );
    }
  });

  testWidgets('published GitHub cards open exactly the verified external URL',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    final opened = <Uri>[];
    var reads = 0;
    await tester.pumpWidget(MaterialApp(home: VerifiedGithubPortfolioScreen(
      arabic: false,
      loader: () {
        reads++;
        return SynchronousFuture(records);
      },
      linkLauncher: (url) async {
        opened.add(url);
        return true;
      },
    )));
    await tester.pumpAndSettle();

    expect(find.text('My GitHub'), findsOneWidget);
    expect(find.text('public-owner/verified-repo'), findsOneWidget);
    expect(find.text('Reviewed public engineering source'), findsOneWidget);
    expect(find.text('Dart'), findsOneWidget);
    expect(reads, 1);

    await tester.tap(find.byKey(
      const Key('github-repo-public-owner/verified-repo'),
    ));
    await tester.pumpAndSettle();
    expect(opened.map((u) => u.toString()).toList(), [
      'https://github.com/public-owner/verified-repo',
    ]);
    expect(reads, 1, reason: 'Tapping an external URL never re-queries the API');
  });

  testWidgets('Arabic screen is RTL, preserves source labels, and handles empty',
      (tester) async {
    final repos = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()], arabic: true), arabic: true,
    );
    await tester.pumpWidget(MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: VerifiedGithubPortfolioScreen(
          arabic: true,
          loader: () => SynchronousFuture(repos),
          linkLauncher: (_) async => true,
        ),
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('مشاريعي على GitHub'), findsOneWidget);
    expect(find.byKey(const Key('github-repo-public-owner/verified-repo')),
        findsOneWidget);
    expect(Directionality.of(tester.element(find.text('مشاريعي على GitHub'))),
        TextDirection.rtl);

    await tester.pumpWidget(MaterialApp(home: VerifiedGithubPortfolioScreen(
      arabic: true,
      loader: () => SynchronousFuture(<VerifiedGithubRepository>[]),
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-empty')), findsOneWidget);
  });

  testWidgets('failed fetch is generic and retry is explicitly user-triggered',
      (tester) async {
    var calls = 0;
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    await tester.pumpWidget(MaterialApp(home: VerifiedGithubPortfolioScreen(
      arabic: false,
      loader: () {
        calls++;
        if (calls == 1) {
          return Future.error(const FormatException('PRIVATE_API_KEY'));
        }
        return SynchronousFuture(records);
      },
    )));
    await tester.pumpAndSettle();
    expect(calls, 1);
    expect(find.byKey(const Key('github-error')), findsOneWidget);
    expect(find.textContaining('PRIVATE_API_KEY'), findsNothing);
    await tester.tap(find.byKey(const Key('github-retry')));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.byKey(const Key('github-repo-public-owner/verified-repo')),
        findsOneWidget);
  });

  testWidgets('external open failure has a localized error, never a false success',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()], arabic: true), arabic: true,
    );
    await tester.pumpWidget(MaterialApp(home: VerifiedGithubPortfolioScreen(
      arabic: true,
      loader: () => SynchronousFuture(records),
      linkLauncher: (_) async => false,
    )));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(
      const Key('github-repo-public-owner/verified-repo'),
    ));
    await tester.pump();
    expect(find.text('تعذّر فتح رابط المستودع الموثّق.'), findsOneWidget);
  });

  testWidgets('home links to MOBILE-10 without a guessed server origin',
      (tester) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.scrollUntilVisible(
      find.byKey(const Key('verified-github-entry')), 140,
    );
    await tester.ensureVisible(find.byKey(const Key('verified-github-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('verified-github-entry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-unconfigured')), findsOneWidget);
    expect(find.textContaining('not configured for this build'), findsOneWidget);
  });
}

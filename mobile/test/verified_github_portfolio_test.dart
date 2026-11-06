import 'dart:async';
import 'dart:convert';
import 'dart:io';

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

class _MemoryGithubFollowStore implements GithubFollowStore {
  _MemoryGithubFollowStore([Set<String>? initial])
      : values = Set<String>.of(initial ?? const {});

  Set<String> values;
  bool failRead = false;
  bool failWrite = false;
  int reads = 0;
  int writes = 0;

  @override
  Future<Set<String>> load() async {
    reads++;
    if (failRead) throw const FileSystemException('private storage failure');
    return Set<String>.of(values);
  }

  @override
  Future<void> save(Set<String> repositories) async {
    writes++;
    if (failWrite) throw const FileSystemException('private storage failure');
    values = Set<String>.of(repositories);
  }
}

/// Controlled IO boundaries reproduce out-of-order file operations without
/// requiring a real device or racing the operating system scheduler.
class _GatedGithubFollowStore extends _MemoryGithubFollowStore {
  _GatedGithubFollowStore({
    Set<String>? initial,
    this.readGate,
    this.writeGate,
  }) : super(initial);

  final Completer<void>? readGate;
  final Completer<void>? writeGate;

  @override
  Future<Set<String>> load() async {
    if (readGate != null) await readGate!.future;
    return super.load();
  }

  @override
  Future<void> save(Set<String> repositories) async {
    if (writeGate != null) await writeGate!.future;
    return super.save(repositories);
  }
}

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
    await tester.pumpWidget(const WaleedTechApp(initiallyStarted: true));
    await tester.scrollUntilVisible(
      find.byKey(const Key('verified-github-entry')), 140,
      scrollable: find.descendant(of: find.byKey(const Key('mobile-home-scroll-feed')), matching: find.byType(Scrollable)),
    );
    await tester.ensureVisible(find.byKey(const Key('verified-github-entry')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('verified-github-entry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-unconfigured')), findsOneWidget);
    expect(find.textContaining('not configured for this build'), findsOneWidget);
  });

  test('follow-list codec is bounded, normalized and rejects corrupt entries', () {
    final encoded = GithubFollowStoreCodec.encode({
      'Public-Owner/Repo-One', 'other-owner/repo_two',
    });
    expect(
      GithubFollowStoreCodec.decode(encoded),
      {'public-owner/repo-one', 'other-owner/repo_two'},
    );
    for (final invalid in [
      '',
      '{}',
      jsonEncode({'schema': 'wrong', 'repositories': []}),
      jsonEncode({'schema': 'walidsite.github-follows.v1', 'repositories': ['../secret/repo']}),
      jsonEncode({'schema': 'walidsite.github-follows.v1', 'repositories': ['owner/repo', 'OWNER/REPO']}),
      jsonEncode({'schema': 'walidsite.github-follows.v1', 'repositories': List.generate(101, (i) => 'owner/repo-$i')}),
      'x' * 16385,
    ]) {
      expect(() => GithubFollowStoreCodec.decode(invalid),
          throwsFormatException);
    }
    expect(() => GithubFollowStoreCodec.encode({'not a repository'}),
        throwsFormatException);
  });

  testWidgets('follow project persists locally and toggles without GitHub writes',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    final store = _MemoryGithubFollowStore();
    final opened = <Uri>[];
    await tester.pumpWidget(MaterialApp(home: VerifiedGithubPortfolioScreen(
      arabic: false,
      loader: () => SynchronousFuture(records),
      linkLauncher: (url) async { opened.add(url); return true; },
      followStore: store,
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-follow-local-note')), findsOneWidget);
    final key = const Key('github-follow-public-owner/verified-repo');
    expect(tester.widget<IconButton>(find.byKey(key)).tooltip,
        'Follow on this device');
    await tester.tap(find.byKey(key));
    await tester.pumpAndSettle();
    expect(store.values, {'public-owner/verified-repo'});
    expect(store.writes, 1);
    expect(tester.widget<IconButton>(find.byKey(key)).tooltip,
        'Unfollow on this device');
    await tester.tap(find.byKey(key));
    await tester.pumpAndSettle();
    expect(store.values, isEmpty);
    expect(store.writes, 2);
    expect(opened, isEmpty, reason: 'Following is local and never mutates GitHub');
  });

  testWidgets('existing follows restore and write failure never shows false follow',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()], arabic: true), arabic: true,
    );
    final store = _MemoryGithubFollowStore({'public-owner/verified-repo'})
      ..failWrite = true;
    await tester.pumpWidget(MaterialApp(home: Directionality(
      textDirection: TextDirection.rtl,
      child: VerifiedGithubPortfolioScreen(
        arabic: true,
        loader: () => SynchronousFuture(records),
        followStore: store,
      ),
    )));
    await tester.pumpAndSettle();
    final key = const Key('github-follow-public-owner/verified-repo');
    expect(tester.widget<IconButton>(find.byKey(key)).tooltip,
        'إلغاء المتابعة على هذا الجهاز');
    await tester.tap(find.byKey(key));
    await tester.pumpAndSettle();
    expect(store.values, {'public-owner/verified-repo'});
    expect(find.byKey(const Key('github-follow-error')), findsOneWidget);
    expect(tester.widget<IconButton>(find.byKey(key)).tooltip,
        'إلغاء المتابعة على هذا الجهاز');
  });

  testWidgets('corrupt local follow list fails closed and offers retry',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    final store = _MemoryGithubFollowStore()..failRead = true;
    await tester.pumpWidget(MaterialApp(home: VerifiedGithubPortfolioScreen(
      arabic: false,
      loader: () => SynchronousFuture(records),
      followStore: store,
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-follow-error')), findsOneWidget);
    expect(tester.widget<IconButton>(find.byKey(
      const Key('github-follow-public-owner/verified-repo'),
    )).onPressed, isNull);
    store.failRead = false;
    await tester.tap(find.byKey(const Key('github-follow-retry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-follow-error')), findsNothing);
    expect(tester.widget<IconButton>(find.byKey(
      const Key('github-follow-public-owner/verified-repo'),
    )).onPressed, isNotNull);
  });

  testWidgets('stale follow reads cannot overwrite the newly selected local store',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    final oldRead = Completer<void>();
    final oldStore = _GatedGithubFollowStore(
      initial: {'public-owner/verified-repo'},
      readGate: oldRead,
    );
    final newStore = _MemoryGithubFollowStore();
    Widget screen(GithubFollowStore store) => MaterialApp(
      home: VerifiedGithubPortfolioScreen(
        arabic: false,
        loader: () => SynchronousFuture(records),
        followStore: store,
      ),
    );

    await tester.pumpWidget(screen(oldStore));
    await tester.pump();
    final followKey = const Key('github-follow-public-owner/verified-repo');
    expect(tester.widget<IconButton>(find.byKey(followKey)).onPressed, isNull);

    // The second source returns first; late contents from the old source
    // must never mark the new source's repositories as followed.
    await tester.pumpWidget(screen(newStore));
    await tester.pumpAndSettle();
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'Follow on this device');
    oldRead.complete();
    await tester.pumpAndSettle();
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'Follow on this device');
    expect(find.byKey(const Key('github-follow-error')), findsNothing);
    await tester.tap(find.byKey(followKey));
    await tester.pumpAndSettle();
    expect(newStore.values, {'public-owner/verified-repo'});
    expect(oldStore.writes, 0);
  });

  testWidgets('stale read failures never disable the newer Arabic follow store',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()], arabic: true), arabic: true,
    );
    final oldRead = Completer<void>();
    final oldStore = _GatedGithubFollowStore(readGate: oldRead)
      ..failRead = true;
    final newStore = _MemoryGithubFollowStore({'public-owner/verified-repo'});
    Widget screen(GithubFollowStore store) => MaterialApp(
      home: VerifiedGithubPortfolioScreen(
        arabic: true,
        loader: () => SynchronousFuture(records),
        followStore: store,
      ),
    );

    await tester.pumpWidget(screen(oldStore));
    await tester.pump();
    await tester.pumpWidget(screen(newStore));
    await tester.pumpAndSettle();
    final followKey = const Key('github-follow-public-owner/verified-repo');
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'إلغاء المتابعة على هذا الجهاز');
    oldRead.complete();
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('github-follow-error')), findsNothing);
    expect(tester.widget<IconButton>(find.byKey(followKey)).onPressed, isNotNull);
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'إلغاء المتابعة على هذا الجهاز');
  });

  testWidgets('pending save to replaced store cannot claim follow in new store',
      (tester) async {
    final records = VerifiedGithubPortfolio.parse(
      feed([verifiedRepo()]), arabic: false,
    );
    final oldWrite = Completer<void>();
    final oldStore = _GatedGithubFollowStore(writeGate: oldWrite);
    final newStore = _MemoryGithubFollowStore();
    Widget screen(GithubFollowStore store) => MaterialApp(
      home: VerifiedGithubPortfolioScreen(
        arabic: false,
        loader: () => SynchronousFuture(records),
        followStore: store,
      ),
    );

    await tester.pumpWidget(screen(oldStore));
    await tester.pumpAndSettle();
    final followKey = const Key('github-follow-public-owner/verified-repo');
    await tester.tap(find.byKey(followKey));
    await tester.pump();
    expect(tester.widget<IconButton>(find.byKey(followKey)).onPressed, isNull,
        reason: 'The outgoing store is still saving');

    await tester.pumpWidget(screen(newStore));
    await tester.pumpAndSettle();
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'Follow on this device');
    expect(tester.widget<IconButton>(find.byKey(followKey)).onPressed, isNotNull);

    oldWrite.complete();
    await tester.pumpAndSettle();
    expect(oldStore.values, {'public-owner/verified-repo'});
    expect(newStore.values, isEmpty);
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'Follow on this device');
    expect(find.byKey(const Key('github-follow-error')), findsNothing);
    await tester.tap(find.byKey(followKey));
    await tester.pumpAndSettle();
    expect(newStore.values, {'public-owner/verified-repo'});
    expect(newStore.writes, 1);
    expect(tester.widget<IconButton>(find.byKey(followKey)).tooltip,
        'Unfollow on this device');
  });

}

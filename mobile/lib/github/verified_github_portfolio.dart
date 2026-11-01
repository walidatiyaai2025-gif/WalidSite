import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../articles/verified_articles.dart';

/// Verified GitHub REST facts only; never expose unknown or private projects.
class VerifiedGithubRepository {
  const VerifiedGithubRepository({
    required this.fullName,
    required this.url,
    required this.description,
    required this.language,
    required this.verifiedAt,
  });
  final String fullName;
  final Uri url;
  final String? description;
  final String? language;
  final DateTime verifiedAt;
}

class VerifiedGithubPortfolio {
  const VerifiedGithubPortfolio._();
  static final RegExp _name = RegExp(
    r'^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?/[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$',
  );

  /// The mobile app treats the public API as untrusted input too.
  static List<VerifiedGithubRepository> parse(String raw, {required bool arabic}) {
    if (raw.length > 1024 * 1024) {
      throw const FormatException('Oversized GitHub response');
    }
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic> ||
        decoded['data'] is! List ||
        decoded['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid GitHub portfolio');
    }
    final records = decoded['data'] as List<dynamic>;
    final meta = decoded['meta'] as Map<String, dynamic>;
    if (records.length > 20 ||
        meta['total'] != records.length ||
        meta['lang'] != (arabic ? 'ar' : 'en')) {
      throw const FormatException('Invalid GitHub response metadata');
    }
    final results = <VerifiedGithubRepository>[];
    final seen = <String>{};
    for (final item in records) {
      if (item is! Map<String, dynamic> ||
          item.length != 7 ||
          item.keys.toSet().difference({
            'full_name', 'url', 'description', 'language',
            'status', 'verified_at', 'source',
          }).isNotEmpty ||
          item['status'] != 'verified' ||
          item['source'] != 'github_public_rest' ||
          item['full_name'] is! String ||
          item['url'] is! String ||
          item['verified_at'] is! String ||
          (item['description'] != null &&
              (item['description'] is! String ||
                  (item['description'] as String).length > 2000)) ||
          (item['language'] != null &&
              (item['language'] is! String ||
                  (item['language'] as String).length > 60))) {
        throw const FormatException('Unverified GitHub record');
      }
      final name = item['full_name'] as String;
      final url = Uri.tryParse(item['url'] as String);
      final verifiedAt = DateTime.tryParse(item['verified_at'] as String);
      if (!_name.hasMatch(name) ||
          name.contains('..') ||
          !seen.add(name.toLowerCase()) ||
          url == null ||
          url.scheme != 'https' ||
          url.host.toLowerCase() != 'github.com' ||
          url.userInfo.isNotEmpty ||
          url.hasPort ||
          url.hasQuery ||
          url.hasFragment ||
          url.path.toLowerCase() != '/' + name.toLowerCase() ||
          url.toString().contains('%') ||
          verifiedAt == null ||
          verifiedAt.isAfter(DateTime.now().toUtc().add(const Duration(minutes: 5)))) {
        throw const FormatException('Unsafe GitHub link or verification');
      }
      results.add(VerifiedGithubRepository(
        fullName: name,
        url: url,
        description: item['description'] as String?,
        language: item['language'] as String?,
        verifiedAt: verifiedAt.toUtc(),
      ));
    }
    return List.unmodifiable(results);
  }
}

class VerifiedGithubPortfolioReader {
  const VerifiedGithubPortfolioReader();
  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const _maxBytes = 1024 * 1024;

  static Uri? validatedOrigin(String candidate) =>
      VerifiedArticleReader.validatedOrigin(candidate);
  static Uri? configuredOrigin() => validatedOrigin(apiOrigin);

  Future<List<VerifiedGithubRepository>> load(bool arabic) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('GitHub portfolio server is not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/github/portfolio',
      queryParameters: {'lang': arabic ? 'ar' : 'en'},
    );
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('GitHub portfolio unavailable');
      }
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > _maxBytes) {
          throw const FormatException('GitHub portfolio exceeds size limit');
        }
        bytes.add(chunk);
      }
      return VerifiedGithubPortfolio.parse(
        utf8.decode(bytes.takeBytes(), allowMalformed: false),
        arabic: arabic,
      );
    } finally {
      client.close(force: true);
    }
  }
}

typedef GithubLinkLauncher = Future<bool> Function(Uri url);

/// MOBILE-10: real public repository cards and links, with no made-up metrics.
class VerifiedGithubPortfolioScreen extends StatefulWidget {
  const VerifiedGithubPortfolioScreen({
    required this.arabic,
    this.loader,
    this.linkLauncher,
    super.key,
  });
  final bool arabic;
  final Future<List<VerifiedGithubRepository>> Function()? loader;
  final GithubLinkLauncher? linkLauncher;

  @override
  State<VerifiedGithubPortfolioScreen> createState() =>
      _VerifiedGithubPortfolioScreenState();
}

class _VerifiedGithubPortfolioScreenState
    extends State<VerifiedGithubPortfolioScreen> {
  late Future<List<VerifiedGithubRepository>> _repositories;
  bool get _configured =>
      widget.loader != null || VerifiedGithubPortfolioReader.configuredOrigin() != null;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void didUpdateWidget(covariant VerifiedGithubPortfolioScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.arabic != widget.arabic || oldWidget.loader != widget.loader) {
      _refresh();
    }
  }

  void _refresh() {
    _repositories = widget.loader?.call() ??
        (_configured
            ? const VerifiedGithubPortfolioReader().load(widget.arabic)
            : Future.value(<VerifiedGithubRepository>[]));
  }

  Future<void> _open(VerifiedGithubRepository repository) async {
    try {
      final launched = await (widget.linkLauncher?.call(repository.url) ??
          launchUrl(repository.url, mode: LaunchMode.externalApplication));
      if (!mounted) return;
      if (!launched) _showOpenError();
    } catch (_) {
      if (mounted) _showOpenError();
    }
  }

  void _showOpenError() {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(widget.arabic
          ? 'تعذّر فتح رابط المستودع الموثّق.'
          : 'Could not open the verified repository link.'),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'مشاريعي على GitHub' : 'My GitHub')),
      body: SafeArea(
        child: !_configured
            ? Center(child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar
                      ? 'خادم المستودعات الموثّقة غير مهيأ في هذا الإصدار.'
                      : 'Verified GitHub portfolio server is not configured for this build.',
                  key: const Key('github-unconfigured'),
                  textAlign: TextAlign.center,
                ),
              ))
            : FutureBuilder<List<VerifiedGithubRepository>>(
                future: _repositories,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator(
                      key: Key('github-loading'),
                    ));
                  }
                  if (snapshot.hasError) {
                    return Center(child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar
                            ? 'تعذّر تحميل المستودعات الموثّقة. تحقق من الاتصال.'
                            : 'Could not load verified repositories. Check your connection.',
                            key: const Key('github-error')),
                        const SizedBox(height: 12),
                        FilledButton(
                          key: const Key('github-retry'),
                          onPressed: () => setState(_refresh),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ));
                  }
                  final repositories = snapshot.data!;
                  if (repositories.isEmpty) {
                    return Center(child: Text(
                      ar
                          ? 'لا توجد مستودعات عامة تم التحقق منها حاليًا.'
                          : 'No independently verified public repositories are available.',
                      key: const Key('github-empty'),
                      textAlign: TextAlign.center,
                    ));
                  }
                  return ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Text(ar
                          ? 'مستودعات عامة تم التحقق منها عبر GitHub. لا تُعرض مستودعات خاصة أو إحصاءات تخمينية.'
                          : 'Public repositories verified through GitHub. No private projects or estimated metrics.'),
                      const SizedBox(height: 12),
                      for (final repository in repositories)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Card(child: ListTile(
                            key: Key('github-repo-' + repository.fullName),
                            leading: const Icon(Icons.code_outlined),
                            title: Text(repository.fullName),
                            subtitle: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                if (repository.description != null &&
                                    repository.description!.trim().isNotEmpty)
                                  Text(repository.description!),
                                if (repository.language != null)
                                  Text(repository.language!),
                                Text((ar ? 'تم التحقق: ' : 'Verified: ') +
                                    repository.verifiedAt.toIso8601String()),
                              ],
                            ),
                            trailing: const Icon(Icons.open_in_new),
                            onTap: () => _open(repository),
                          )),
                        ),
                    ],
                  );
                },
              ),
      ),
    );
  }
}

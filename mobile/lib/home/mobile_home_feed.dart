import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';
import '../runbooks/verified_online_runbooks.dart';
import '../theme/waleed_theme.dart';

class MobileHomeProject {
  const MobileHomeProject({
    required this.slug,
    required this.title,
    required this.summary,
    required this.role,
  });

  final String slug;
  final String title;
  final String summary;
  final String role;

  static MobileHomeProject fromJson(Object? value) {
    if (value is! Map<String, dynamic>) {
      throw const FormatException('Invalid home project');
    }
    const keys = {
      'slug', 'title', 'summary', 'role', 'evidence_urls', 'url',
    };
    if (value.length != keys.length ||
        value.keys.toSet().difference(keys).isNotEmpty) {
      throw const FormatException('Unexpected home project fields');
    }
    final slug = value['slug'];
    final title = value['title'];
    final summary = value['summary'];
    final role = value['role'];
    final evidence = value['evidence_urls'];
    final url = value['url'];
    if (slug is! String ||
        !RegExp(r'^[A-Za-z0-9-]{1,180}$').hasMatch(slug) ||
        title is! String || title.trim().isEmpty || title.length > 250 ||
        summary is! String || summary.trim().isEmpty || summary.length > 5000 ||
        role is! String || role.trim().isEmpty || role.length > 220 ||
        evidence is! List || evidence.isEmpty || evidence.length > 50 ||
        evidence.any((item) => item is! String || item.length > 2048) ||
        url is! String || url.length > 2048) {
      throw const FormatException('Unsafe home project');
    }
    return MobileHomeProject(
      slug: slug,
      title: title,
      summary: summary,
      role: role,
    );
  }
}

class MobileHomeArticle {
  const MobileHomeArticle({required this.slug, required this.title});

  final String slug;
  final String title;

  static MobileHomeArticle fromJson(Object? value) {
    if (value is! Map<String, dynamic>) {
      throw const FormatException('Invalid home article');
    }
    const keys = {'slug', 'title', 'url', 'published_at'};
    if (value.length != keys.length ||
        value.keys.toSet().difference(keys).isNotEmpty) {
      throw const FormatException('Unexpected home article fields');
    }
    final slug = value['slug'];
    final title = value['title'];
    final url = value['url'];
    final publishedAt = value['published_at'];
    final publishedTime =
        publishedAt is String ? DateTime.tryParse(publishedAt) : null;
    if (slug is! String ||
        !RegExp(r'^[A-Za-z0-9-]{1,180}$').hasMatch(slug) ||
        title is! String || title.trim().isEmpty || title.length > 250 ||
        url is! String || url.length > 2048 ||
        publishedAt is! String || publishedAt.length > 50 ||
        !RegExp(r'(?:Z|[+-][0-9]{2}:[0-9]{2})$').hasMatch(publishedAt) ||
        publishedTime == null || !publishedTime.isUtc ||
        publishedTime.isAfter(
            DateTime.now().toUtc().add(const Duration(minutes: 5)))) {
      throw const FormatException('Unsafe home article');
    }
    return MobileHomeArticle(slug: slug, title: title);
  }
}

class MobileHomeFeed {
  const MobileHomeFeed({
    required this.projects,
    required this.articles,
    required this.runbooks,
  });

  final List<MobileHomeProject> projects;
  final List<MobileHomeArticle> articles;
  final List<ReviewedRunbook> runbooks;

  static MobileHomeFeed parse(String body, bool arabic) {
    // The transport limit is in UTF-8 bytes, not Dart UTF-16 units.
    // Apply it to injected/direct parser calls as well as HTTP reads.
    if (body.length > 1024 * 1024 ||
        utf8.encode(body).length > 1024 * 1024) {
      throw const FormatException('Home response too large');
    }
    final decoded = jsonDecode(body);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 2 ||
        decoded['data'] is! Map<String, dynamic> ||
        decoded['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid home response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    final meta = decoded['meta'] as Map<String, dynamic>;
    const dataKeys = {
      'profile', 'featured_projects', 'reading_feed', 'offline_runbooks',
      'tools', 'saved_requires_auth',
    };
    if (data.length != dataKeys.length ||
        data.keys.toSet().difference(dataKeys).isNotEmpty ||
        meta.length != 1 ||
        meta['lang'] != (arabic ? 'ar' : 'en') ||
        (data['profile'] != null && data['profile'] is! Map<String, dynamic>) ||
        data['featured_projects'] is! List ||
        data['reading_feed'] is! List ||
        data['offline_runbooks'] is! List ||
        data['tools'] is! List ||
        (data['tools'] as List).isNotEmpty ||
        data['saved_requires_auth'] != true) {
      throw const FormatException('Unsupported home response');
    }

    final rawProjects = data['featured_projects'] as List;
    final rawArticles = data['reading_feed'] as List;
    final rawRunbooks = data['offline_runbooks'] as List;
    if (rawProjects.length > 3 ||
        rawArticles.length > 15 ||
        rawRunbooks.length > 15) {
      throw const FormatException('Unbounded home response');
    }

    final projects = rawProjects.map(MobileHomeProject.fromJson).toList();
    final articles = rawArticles.map(MobileHomeArticle.fromJson).toList();
    final runbooks = rawRunbooks.map(ReviewedRunbook.fromJson).toList();
    if (projects.map((e) => e.slug).toSet().length != projects.length ||
        articles.map((e) => e.slug).toSet().length != articles.length ||
        runbooks.map((e) => e.id.toLowerCase()).toSet().length != runbooks.length) {
      throw const FormatException('Duplicate home resource');
    }

    return MobileHomeFeed(
      projects: List.unmodifiable(projects),
      articles: List.unmodifiable(articles),
      runbooks: List.unmodifiable(runbooks),
    );
  }
}

class MobileHomeReader {
  const MobileHomeReader();

  static const _limit = 1024 * 1024;

  /// A slow-drip body must not keep the guest Home feed pending forever.
  @visibleForTesting
  static Future<T> withinDeadline<T>(
    Future<T> Function() operation, {
    Duration timeout = const Duration(seconds: 12),
  }) => Future<T>.sync(operation).timeout(timeout);

  static Uri? configuredOrigin() => VerifiedArticleReader.configuredOrigin();

  Future<MobileHomeFeed> load(bool arabic) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Mobile home API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/mobile/home',
      queryParameters: {'lang': arabic ? 'ar' : 'en'},
    );
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      return await withinDeadline(() async {
        final request = await client.getUrl(url);
        request.followRedirects = false;
        request.headers.set(HttpHeaders.acceptHeader, 'application/json');
        final response = await request.close();
        if (response.statusCode != HttpStatus.ok) {
          throw const HttpException('Mobile home unavailable');
        }
        final bytes = BytesBuilder(copy: false);
        await for (final chunk in response) {
          if (bytes.length + chunk.length > _limit) {
            throw const FormatException('Mobile home response too large');
          }
          bytes.add(chunk);
        }
        return MobileHomeFeed.parse(
          utf8.decode(bytes.takeBytes(), allowMalformed: false),
          arabic,
        );
      });
    } finally {
      // Abort pending sockets when the overall deadline fires.
      client.close(force: true);
    }
  }
}

typedef MobileHomeFetch = Future<MobileHomeFeed> Function(bool arabic);

class MobileHomeFeedPanel extends StatefulWidget {
  const MobileHomeFeedPanel({
    required this.arabic,
    required this.onOpenArticles,
    required this.onOpenRunbooks,
    this.onOpenArticle,
    this.onOpenRunbook,
    this.fetch,
    super.key,
  });

  final bool arabic;
  final VoidCallback onOpenArticles;
  final VoidCallback onOpenRunbooks;
  final ValueChanged<String>? onOpenArticle;
  final ValueChanged<String>? onOpenRunbook;
  final MobileHomeFetch? fetch;

  @override
  State<MobileHomeFeedPanel> createState() => _MobileHomeFeedPanelState();
}

class _MobileHomeFeedPanelState extends State<MobileHomeFeedPanel> {
  Future<MobileHomeFeed>? _feed;

  bool get _configured =>
      widget.fetch != null || MobileHomeReader.configuredOrigin() != null;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant MobileHomeFeedPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.arabic != widget.arabic || oldWidget.fetch != widget.fetch) {
      _load();
    }
  }

  void _load() {
    if (!_configured) {
      _feed = null;
      return;
    }
    _feed = (widget.fetch ?? const MobileHomeReader().load)(widget.arabic);
  }

  String label(String en, String ar) => widget.arabic ? ar : en;

  @override
  Widget build(BuildContext context) {
    if (!_configured) {
      return Card(
        key: const Key('mobile-home-unconfigured'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(label(
            'Verified live home feed is not configured. Navigation and bundled offline tools remain available.',
            'موجز الصفحة الرئيسية الموثق غير مهيأ. ما زال التنقل والأدوات المحلية المتاحة دون إنترنت يعملان.',
          )),
        ),
      );
    }

    return FutureBuilder<MobileHomeFeed>(
      future: _feed,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Card(
            key: Key('mobile-home-loading'),
            child: Padding(
              padding: EdgeInsets.all(20),
              child: Center(child: CircularProgressIndicator()),
            ),
          );
        }
        if (snapshot.hasError || !snapshot.hasData) {
          return Card(
            key: const Key('mobile-home-error'),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label(
                    'Could not verify the live home feed. Offline navigation remains available.',
                    'تعذر التحقق من موجز الصفحة الرئيسية. يظل التنقل دون إنترنت متاحًا.',
                  )),
                  const SizedBox(height: 10),
                  FilledButton(
                    key: const Key('mobile-home-retry'),
                    onPressed: () => setState(_load),
                    child: Text(label('Retry', 'إعادة المحاولة')),
                  ),
                ],
              ),
            ),
          );
        }

        final feed = snapshot.data!;
        final empty = feed.projects.isEmpty &&
            feed.articles.isEmpty &&
            feed.runbooks.isEmpty;
        return Card(
          key: const Key('mobile-home-feed'),
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label('Verified live feed', 'الموجز المباشر الموثق'),
                  style: const TextStyle(
                    color: WaleedPalette.primary,
                    fontSize: 20,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 8),
                if (empty)
                  Text(
                    label(
                      'No reviewed public items are available yet.',
                      'لا توجد عناصر عامة مراجعة متاحة حاليًا.',
                    ),
                    key: const Key('mobile-home-empty'),
                  ),
                if (feed.projects.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Text(label('Featured projects', 'مشاريع موثقة'),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  ...feed.projects.map((project) => ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.verified_outlined),
                    title: Text(project.title),
                    subtitle: Text(project.summary),
                  )),
                ],
                if (feed.articles.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(label('Latest reviewed articles', 'أحدث المقالات المراجعة'),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  ...feed.articles.take(3).map(
                    (article) => ListTile(
                      key: Key('mobile-home-article-${article.slug}'),
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: const Icon(Icons.article_outlined),
                      title: Text(article.title),
                      trailing: Icon(widget.arabic
                          ? Icons.chevron_left : Icons.chevron_right),
                      onTap: () => widget.onOpenArticle == null
                          ? widget.onOpenArticles()
                          : widget.onOpenArticle!(article.slug),
                    ),
                  ),
                  const SizedBox(height: 8),
                  TextButton.icon(
                    key: const Key('mobile-home-open-articles'),
                    onPressed: widget.onOpenArticles,
                    icon: const Icon(Icons.article_outlined),
                    label: Text(label('Open article library', 'فتح مكتبة المقالات')),
                  ),
                ],
                if (feed.runbooks.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(label('Reviewed runbooks', 'أدلة تشغيل مراجعة'),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  ...feed.runbooks.take(3).map(
                    (runbook) => ListTile(
                      key: Key('mobile-home-runbook-${runbook.id}'),
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: const Icon(Icons.menu_book_outlined),
                      title: Text(runbook.title),
                      trailing: Icon(widget.arabic
                          ? Icons.chevron_left : Icons.chevron_right),
                      onTap: () => widget.onOpenRunbook == null
                          ? widget.onOpenRunbooks()
                          : widget.onOpenRunbook!(runbook.id),
                    ),
                  ),
                  const SizedBox(height: 8),
                  TextButton.icon(
                    key: const Key('mobile-home-open-runbooks'),
                    onPressed: widget.onOpenRunbooks,
                    icon: const Icon(Icons.menu_book_outlined),
                    label: Text(label('Open reviewed runbooks', 'فتح أدلة التشغيل المراجعة')),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }
}

/// An exact Home article target, not a list-page approximation. The server is
/// consulted again so revoked articles cannot be opened from stale Home data.
class MobileHomeArticleRoute extends StatefulWidget {
  const MobileHomeArticleRoute({
    required this.arabic,
    required this.slug,
    this.fetch,
    super.key,
  });

  final bool arabic;
  final String slug;
  final Future<VerifiedArticle> Function(bool, String)? fetch;

  @override
  State<MobileHomeArticleRoute> createState() => _MobileHomeArticleRouteState();
}

class _MobileHomeArticleRouteState extends State<MobileHomeArticleRoute> {
  late Future<VerifiedArticle> _detail;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant MobileHomeArticleRoute oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.slug != widget.slug ||
        oldWidget.arabic != widget.arabic ||
        oldWidget.fetch != widget.fetch) {
      _load();
    }
  }

  void _load() {
    _detail = (widget.fetch ?? const VerifiedArticleReader().loadDetail)(
      widget.arabic,
      widget.slug,
    );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<VerifiedArticle>(
      future: _detail,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return Scaffold(
            appBar: AppBar(title: Text(widget.arabic ? 'مقال موثق' : 'Verified article')),
            body: const Center(child: CircularProgressIndicator(
              key: Key('mobile-home-article-loading'),
            )),
          );
        }
        if (snapshot.hasError ||
            !snapshot.hasData ||
            snapshot.data!.slug != widget.slug) {
          return Scaffold(
            appBar: AppBar(title: Text(widget.arabic ? 'مقال موثق' : 'Verified article')),
            body: Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    widget.arabic
                        ? 'المقال غير متاح أو لم يعد موثقًا.'
                        : 'This article is unavailable or no longer verified.',
                    key: const Key('mobile-home-article-error'),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    key: const Key('mobile-home-article-retry'),
                    onPressed: () => setState(_load),
                    child: Text(widget.arabic ? 'إعادة المحاولة' : 'Retry'),
                  ),
                ],
              ),
            ),
          );
        }
        return VerifiedArticleDetail(
          article: snapshot.data!,
          arabic: widget.arabic,
        );
      },
    );
  }
}

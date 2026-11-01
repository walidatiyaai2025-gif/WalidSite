import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

/// Reads ONLY published, evidence-checked articles from the existing public API.
/// No AI diagnosis, authentication or remote caching is implied by this screen.
class VerifiedArticle {
  const VerifiedArticle({
    required this.slug,
    required this.title,
    required this.body,
    required this.citations,
  });

  final String slug;
  final String title;
  final String body;
  final List<String> citations;

  static List<VerifiedArticle> parseList(String json) {
    final value = jsonDecode(json);
    if (value is! Map<String, dynamic> || value['data'] is! List) {
      throw const FormatException('Invalid article response');
    }

    final raw = value['data'] as List<dynamic>;
    if (raw.length > 12) {
      throw const FormatException('Unbounded article response');
    }

    final articles = <VerifiedArticle>[];
    final seen = <String>{};
    for (final item in raw) {
      if (item is! Map<String, dynamic>) {
        throw const FormatException('Invalid article');
      }
      final slug = item['slug'];
      final title = item['title'];
      final body = item['body_markdown'];
      final citations = item['citations'];
      if (slug is! String ||
          !RegExp(r'^[a-zA-Z0-9-]{1,180}$').hasMatch(slug) ||
          !seen.add(slug) ||
          title is! String ||
          title.trim().isEmpty ||
          title.length > 250 ||
          body is! String ||
          body.trim().isEmpty ||
          body.length > 60000 ||
          item['tested_status'] != 'documented' ||
          item['published_at'] is! String ||
          DateTime.tryParse(item['published_at'] as String) == null ||
          citations is! List ||
          citations.isEmpty ||
          citations.length > 20) {
        throw const FormatException('Unverified article in response');
      }
      final validatedCitations = <String>[];
      for (final citation in citations) {
        if (citation is! String || citation.length > 2048) {
          throw const FormatException('Unsafe citation');
        }
        // Treat API citations as untrusted input even when upstream says
        // the article was verified. Query strings can contain signed URLs,
        // private redirects or access credentials; never expose them in UI.
        final url = Uri.tryParse(citation);
        final host = url?.host.toLowerCase() ?? '';
        const privateSuffixes = [
          'localhost', 'local', 'internal', 'test', 'invalid', 'onion',
        ];
        if (url == null ||
            url.scheme != 'https' ||
            url.userInfo.isNotEmpty ||
            url.hasQuery ||
            url.hasFragment ||
            (url.hasPort && url.port != 443) ||
            !RegExp(
              r'^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$',
            ).hasMatch(host) ||
            privateSuffixes.any(
              (suffix) => host == suffix || host.endsWith('.$suffix'),
            ) ||
            InternetAddress.tryParse(host) != null) {
          throw const FormatException('Unsafe citation');
        }
        validatedCitations.add(citation);
      }
      articles.add(VerifiedArticle(
        slug: slug,
        title: title,
        body: body,
        citations: List.unmodifiable(validatedCitations),
      ));
    }
    return List.unmodifiable(articles);
  }
}


/**
 * A single server-verified public page. Pagination is untrusted input too:
 * never allow malformed metadata to claim more safe publications exist.
 */
class VerifiedArticlePage {
  const VerifiedArticlePage({
    required this.articles,
    required this.currentPage,
    required this.hasMorePages,
  });

  final List<VerifiedArticle> articles;
  final int currentPage;
  final bool hasMorePages;

  static VerifiedArticlePage parse(String json, int requestedPage) {
    if (requestedPage < 1 || requestedPage > 10000) {
      throw const FormatException('Invalid article page');
    }
    final decoded = jsonDecode(json);
    if (decoded is! Map<String, dynamic> ||
        decoded['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid pagination metadata');
    }
    final meta = decoded['meta'] as Map<String, dynamic>;
    final hasMore = meta['has_more_pages'];
    if (meta['current_page'] != requestedPage || hasMore is! bool) {
      throw const FormatException('Mismatched article page');
    }
    // Apply all existing evidence, URL, size and publication checks to every
    // page, not just the first response.
    final articles = VerifiedArticle.parseList(json);
    if (hasMore && (articles.isEmpty || requestedPage == 10000)) {
      throw const FormatException('Invalid article page continuation');
    }
    return VerifiedArticlePage(
      articles: articles,
      currentPage: requestedPage,
      hasMorePages: hasMore,
    );
  }
}

class VerifiedArticleReader {
  const VerifiedArticleReader();

  // Explicit build-time deployment configuration, never an invented endpoint.
  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const _limit = 2 * 1024 * 1024;

  static Uri? configuredOrigin() => validatedOrigin(apiOrigin);

  /// Validate the supplied build configuration before ever creating a
  /// network client. Public reading must not silently contact a loopback,
  /// literal-IP or private intranet destination on a user's device.
  /// This is deliberately pure so releases can test the boundary without
  /// requiring an actual remote service or an injected production URL.
  static Uri? validatedOrigin(String candidate) {
    final uri = Uri.tryParse(candidate);
    if (uri == null ||
        uri.scheme != 'https' ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        (uri.path.isNotEmpty && uri.path != '/') ||
        (uri.hasPort && uri.port != 443)) {
      return null;
    }

    final host = uri.host.toLowerCase();
    const privateSuffixes = [
      'localhost', 'local', 'internal', 'test', 'invalid', 'onion',
    ];
    if (InternetAddress.tryParse(host) != null ||
        !RegExp(
          r'^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}

  Future<List<VerifiedArticle>> load(bool arabic) async =>
      (await loadPage(arabic, 1)).articles;

  Future<VerifiedArticlePage> loadPage(bool arabic, int page) async {
    if (page < 1 || page > 10000) {
      throw const FormatException('Invalid article page');
    }
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Article API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/articles',
      queryParameters: {'lang': arabic ? 'ar' : 'en', 'page': '$page'},
    );

    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Article service unavailable');
      }

      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > _limit) {
          throw const FormatException('Article response exceeds size limit');
        }
        buffer.add(chunk);
      }
      return VerifiedArticlePage.parse(utf8.decode(buffer.takeBytes()), page);
    } finally {
      client.close(force: true);
    }
  }
}

class VerifiedArticleLibrary extends StatefulWidget {
  const VerifiedArticleLibrary({
    required this.arabic,
    this.loader,
    this.pageLoader,
    super.key,
  });

  final bool arabic;

  // Compatibility for existing offline/first-page widget tests.
  final Future<List<VerifiedArticle>> Function()? loader;

  // Injectable verified page source for deterministic pagination tests.
  final Future<VerifiedArticlePage> Function(int page)? pageLoader;

  @override
  State<VerifiedArticleLibrary> createState() => _VerifiedArticleLibraryState();
}

class _VerifiedArticleLibraryState extends State<VerifiedArticleLibrary> {
  late Future<VerifiedArticlePage> _firstPage;
  List<VerifiedArticle> _additional = const [];
  String _query = '';
  String? _nextError;
  bool _loadingNext = false;
  bool _loadedAdditional = false;
  bool _hasMoreAfterAdditional = false;
  int _nextPage = 2;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void didUpdateWidget(covariant VerifiedArticleLibrary oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.arabic != oldWidget.arabic ||
        widget.loader != oldWidget.loader ||
        widget.pageLoader != oldWidget.pageLoader) {
      _query = '';
      _refresh();
    }
  }

  void _refresh() {
    _generation++;
    _additional = const [];
    _nextError = null;
    _loadingNext = false;
    _loadedAdditional = false;
    _hasMoreAfterAdditional = false;
    _nextPage = 2;

    if (widget.pageLoader != null) {
      _firstPage = widget.pageLoader!(1);
    } else if (widget.loader != null) {
      _firstPage = widget.loader!().then(
        (items) => VerifiedArticlePage(
          articles: items,
          currentPage: 1,
          hasMorePages: false,
        ),
      );
    } else if (VerifiedArticleReader.configuredOrigin() != null) {
      _firstPage = const VerifiedArticleReader().loadPage(widget.arabic, 1);
    } else {
      // The unavailable view is authoritative when no origin was provided.
      // Never start a failing Future during initState for an unconfigured app.
      _firstPage = Future<VerifiedArticlePage>.value(
        const VerifiedArticlePage(
          articles: <VerifiedArticle>[],
          currentPage: 1,
          hasMorePages: false,
        ),
      );
    }
  }

  Future<void> _loadNext() async {
    if (_loadingNext || _nextPage > 10000) return;
    final generation = _generation;
    setState(() {
      _loadingNext = true;
      _nextError = null;
    });

    try {
      final first = await _firstPage;
      final requested = _nextPage;
      final next = await (widget.pageLoader != null
          ? widget.pageLoader!(requested)
          : const VerifiedArticleReader().loadPage(widget.arabic, requested));
      if (generation != _generation || !mounted) return;
      if (next.currentPage != requested ||
          (next.hasMorePages && next.articles.isEmpty)) {
        throw const FormatException('Inconsistent article pagination');
      }
      final ids = <String>{
        for (final article in first.articles) article.slug,
        for (final article in _additional) article.slug,
      };
      if (next.articles.any((article) => !ids.add(article.slug))) {
        throw const FormatException('Duplicate article across pages');
      }
      setState(() {
        _additional = List.unmodifiable([..._additional, ...next.articles]);
        _loadedAdditional = true;
        _hasMoreAfterAdditional = next.hasMorePages;
        _nextPage = requested + 1;
        _loadingNext = false;
      });
    } catch (_) {
      if (!mounted || generation != _generation) return;
      setState(() {
        // Keep previously verified articles and retry exactly the same page.
        _nextError = widget.arabic
            ? 'تعذر تحميل الصفحة التالية. لم تُفقد المقالات المحملة.'
            : 'Could not load the next page. Previously loaded articles are safe.';
        _loadingNext = false;
      });
    }
  }

  Widget _paginationFooter(bool arabic) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_nextError != null)
            Text(
              _nextError!,
              key: const Key('article-load-more-error'),
              textAlign: TextAlign.center,
            ),
          const SizedBox(height: 8),
          OutlinedButton(
            key: const Key('article-load-more'),
            onPressed: _loadingNext ? null : _loadNext,
            child: _loadingNext
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(_nextError != null
                    ? (arabic ? 'إعادة محاولة تحميل الصفحة' : 'Retry next page')
                    : (arabic ? 'تحميل مقالات موثقة إضافية' : 'Load more verified articles')),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    final configured = widget.loader != null ||
        widget.pageLoader != null ||
        VerifiedArticleReader.configuredOrigin() != null;

    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'المقالات التقنية' : 'Technical articles')),
      body: !configured
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar
                      ? 'خادم المقالات غير مهيأ في هذا الإصدار. لا توجد بيانات محلية بديلة.'
                      : 'Article server is not configured for this build. No offline articles are available.',
                  textAlign: TextAlign.center,
                ),
              ),
            )
          : FutureBuilder<VerifiedArticlePage>(
              future: _firstPage,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar
                            ? 'تعذّر تحميل المقالات الموثقة. تحقق من الاتصال.'
                            : 'Verified articles are unavailable. Check your connection.'),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed: () => setState(_refresh),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ),
                  );
                }
                final first = snapshot.data!;
                final articles = <VerifiedArticle>[
                  ...first.articles,
                  ..._additional,
                ];
                if (articles.isEmpty) {
                  return Center(child: Text(ar
                      ? 'لا توجد مقالات منشورة وموثقة حاليًا.'
                      : 'No source-verified articles have been published yet.'));
                }

                // Search stays entirely on device. Do not send entered terms
                // to the article service; the helper explains loaded-only scope.
                final query = _query.trim().toLowerCase();
                final matches = query.isEmpty
                    ? articles
                    : articles
                        .where((article) =>
                            article.title.toLowerCase().contains(query) ||
                            article.body.toLowerCase().contains(query))
                        .toList(growable: false);
                final hasMore = _loadedAdditional
                    ? _hasMoreAfterAdditional
                    : first.hasMorePages;
                final showFooter = hasMore || _loadingNext || _nextError != null;

                return Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                      child: TextField(
                        key: const Key('article-search-input'),
                        maxLength: 120,
                        textInputAction: TextInputAction.search,
                        decoration: InputDecoration(
                          labelText: ar
                              ? 'البحث في المقالات الموثقة'
                              : 'Search verified articles',
                          prefixIcon: const Icon(Icons.search),
                          border: const OutlineInputBorder(),
                          helperText: ar
                              ? 'البحث محلي في المقالات المحملة فقط'
                              : 'Searches loaded articles on this device only',
                        ),
                        onChanged: (value) => setState(() => _query = value),
                      ),
                    ),
                    Expanded(
                      child: matches.isEmpty
                          ? ListView(
                              children: [
                                Padding(
                                  padding: const EdgeInsets.all(24),
                                  child: Text(
                                    ar
                                        ? 'لا توجد مقالات موثقة تطابق البحث.'
                                        : 'No verified articles match your search.',
                                    key: const Key('article-search-empty'),
                                    textAlign: TextAlign.center,
                                  ),
                                ),
                                if (showFooter) _paginationFooter(ar),
                              ],
                            )
                          : ListView.builder(
                              itemCount: matches.length + (showFooter ? 1 : 0),
                              padding: const EdgeInsets.all(16),
                              itemBuilder: (context, index) {
                                if (index == matches.length) {
                                  return _paginationFooter(ar);
                                }
                                final article = matches[index];
                                return Card(
                                  child: ListTile(
                                    key: Key('article-' + article.slug),
                                    title: Text(article.title),
                                    subtitle: Text(ar
                                        ? 'موثق بمراجع منشورة — لا يُثبت الاختبار العملي'
                                        : 'Published citations — not a claim of hands-on testing'),
                                    trailing: Icon(ar
                                        ? Icons.chevron_left
                                        : Icons.chevron_right),
                                    onTap: () => Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => VerifiedArticleDetail(
                                          article: article,
                                          arabic: ar,
                                        ),
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                    ),
                  ],
                );
              },
            ),
    );
  }
}

class VerifiedArticleDetail extends StatelessWidget {
  const VerifiedArticleDetail({
    required this.article,
    required this.arabic,
    super.key,
  });

  final VerifiedArticle article;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(article.title)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            arabic
                ? 'محتوى منشور موثق بمراجع؛ ليس تقرير اختبار عملي.'
                : 'Published source-verified reading, not a tested execution report.',
          ),
          const SizedBox(height: 16),
          SelectableText(article.body, key: const Key('verified-article-body')),
          const SizedBox(height: 24),
          Text(arabic ? 'المراجع' : 'Citations',
              style: Theme.of(context).textTheme.titleLarge),
          for (final citation in article.citations)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: SelectableText(citation),
            ),
        ],
      ),
    );
  }
}
,
        ).hasMatch(host) ||
        privateSuffixes.any(
          (suffix) => host == suffix || host.endsWith('.$suffix'),
        )) {
      return null;
    }
    return uri;
  }

  Future<List<VerifiedArticle>> load(bool arabic) async =>
      (await loadPage(arabic, 1)).articles;

  Future<VerifiedArticlePage> loadPage(bool arabic, int page) async {
    if (page < 1 || page > 10000) {
      throw const FormatException('Invalid article page');
    }
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Article API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/articles',
      queryParameters: {'lang': arabic ? 'ar' : 'en', 'page': '$page'},
    );

    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Article service unavailable');
      }

      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > _limit) {
          throw const FormatException('Article response exceeds size limit');
        }
        buffer.add(chunk);
      }
      return VerifiedArticlePage.parse(utf8.decode(buffer.takeBytes()), page);
    } finally {
      client.close(force: true);
    }
  }
}

class VerifiedArticleLibrary extends StatefulWidget {
  const VerifiedArticleLibrary({
    required this.arabic,
    this.loader,
    this.pageLoader,
    super.key,
  });

  final bool arabic;

  // Compatibility for existing offline/first-page widget tests.
  final Future<List<VerifiedArticle>> Function()? loader;

  // Injectable verified page source for deterministic pagination tests.
  final Future<VerifiedArticlePage> Function(int page)? pageLoader;

  @override
  State<VerifiedArticleLibrary> createState() => _VerifiedArticleLibraryState();
}

class _VerifiedArticleLibraryState extends State<VerifiedArticleLibrary> {
  late Future<VerifiedArticlePage> _firstPage;
  List<VerifiedArticle> _additional = const [];
  String _query = '';
  String? _nextError;
  bool _loadingNext = false;
  bool _loadedAdditional = false;
  bool _hasMoreAfterAdditional = false;
  int _nextPage = 2;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void didUpdateWidget(covariant VerifiedArticleLibrary oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.arabic != oldWidget.arabic ||
        widget.loader != oldWidget.loader ||
        widget.pageLoader != oldWidget.pageLoader) {
      _query = '';
      _refresh();
    }
  }

  void _refresh() {
    _generation++;
    _additional = const [];
    _nextError = null;
    _loadingNext = false;
    _loadedAdditional = false;
    _hasMoreAfterAdditional = false;
    _nextPage = 2;

    if (widget.pageLoader != null) {
      _firstPage = widget.pageLoader!(1);
    } else if (widget.loader != null) {
      _firstPage = widget.loader!().then(
        (items) => VerifiedArticlePage(
          articles: items,
          currentPage: 1,
          hasMorePages: false,
        ),
      );
    } else if (VerifiedArticleReader.configuredOrigin() != null) {
      _firstPage = const VerifiedArticleReader().loadPage(widget.arabic, 1);
    } else {
      // The unavailable view is authoritative when no origin was provided.
      // Never start a failing Future during initState for an unconfigured app.
      _firstPage = Future<VerifiedArticlePage>.value(
        const VerifiedArticlePage(
          articles: <VerifiedArticle>[],
          currentPage: 1,
          hasMorePages: false,
        ),
      );
    }
  }

  Future<void> _loadNext() async {
    if (_loadingNext || _nextPage > 10000) return;
    final generation = _generation;
    setState(() {
      _loadingNext = true;
      _nextError = null;
    });

    try {
      final first = await _firstPage;
      final requested = _nextPage;
      final next = await (widget.pageLoader != null
          ? widget.pageLoader!(requested)
          : const VerifiedArticleReader().loadPage(widget.arabic, requested));
      if (generation != _generation || !mounted) return;
      if (next.currentPage != requested ||
          (next.hasMorePages && next.articles.isEmpty)) {
        throw const FormatException('Inconsistent article pagination');
      }
      final ids = <String>{
        for (final article in first.articles) article.slug,
        for (final article in _additional) article.slug,
      };
      if (next.articles.any((article) => !ids.add(article.slug))) {
        throw const FormatException('Duplicate article across pages');
      }
      setState(() {
        _additional = List.unmodifiable([..._additional, ...next.articles]);
        _loadedAdditional = true;
        _hasMoreAfterAdditional = next.hasMorePages;
        _nextPage = requested + 1;
        _loadingNext = false;
      });
    } catch (_) {
      if (!mounted || generation != _generation) return;
      setState(() {
        // Keep previously verified articles and retry exactly the same page.
        _nextError = widget.arabic
            ? 'تعذر تحميل الصفحة التالية. لم تُفقد المقالات المحملة.'
            : 'Could not load the next page. Previously loaded articles are safe.';
        _loadingNext = false;
      });
    }
  }

  Widget _paginationFooter(bool arabic) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_nextError != null)
            Text(
              _nextError!,
              key: const Key('article-load-more-error'),
              textAlign: TextAlign.center,
            ),
          const SizedBox(height: 8),
          OutlinedButton(
            key: const Key('article-load-more'),
            onPressed: _loadingNext ? null : _loadNext,
            child: _loadingNext
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(_nextError != null
                    ? (arabic ? 'إعادة محاولة تحميل الصفحة' : 'Retry next page')
                    : (arabic ? 'تحميل مقالات موثقة إضافية' : 'Load more verified articles')),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    final configured = widget.loader != null ||
        widget.pageLoader != null ||
        VerifiedArticleReader.configuredOrigin() != null;

    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'المقالات التقنية' : 'Technical articles')),
      body: !configured
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar
                      ? 'خادم المقالات غير مهيأ في هذا الإصدار. لا توجد بيانات محلية بديلة.'
                      : 'Article server is not configured for this build. No offline articles are available.',
                  textAlign: TextAlign.center,
                ),
              ),
            )
          : FutureBuilder<VerifiedArticlePage>(
              future: _firstPage,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar
                            ? 'تعذّر تحميل المقالات الموثقة. تحقق من الاتصال.'
                            : 'Verified articles are unavailable. Check your connection.'),
                        const SizedBox(height: 12),
                        OutlinedButton(
                          onPressed: () => setState(_refresh),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ),
                  );
                }
                final first = snapshot.data!;
                final articles = <VerifiedArticle>[
                  ...first.articles,
                  ..._additional,
                ];
                if (articles.isEmpty) {
                  return Center(child: Text(ar
                      ? 'لا توجد مقالات منشورة وموثقة حاليًا.'
                      : 'No source-verified articles have been published yet.'));
                }

                // Search stays entirely on device. Do not send entered terms
                // to the article service; the helper explains loaded-only scope.
                final query = _query.trim().toLowerCase();
                final matches = query.isEmpty
                    ? articles
                    : articles
                        .where((article) =>
                            article.title.toLowerCase().contains(query) ||
                            article.body.toLowerCase().contains(query))
                        .toList(growable: false);
                final hasMore = _loadedAdditional
                    ? _hasMoreAfterAdditional
                    : first.hasMorePages;
                final showFooter = hasMore || _loadingNext || _nextError != null;

                return Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                      child: TextField(
                        key: const Key('article-search-input'),
                        maxLength: 120,
                        textInputAction: TextInputAction.search,
                        decoration: InputDecoration(
                          labelText: ar
                              ? 'البحث في المقالات الموثقة'
                              : 'Search verified articles',
                          prefixIcon: const Icon(Icons.search),
                          border: const OutlineInputBorder(),
                          helperText: ar
                              ? 'البحث محلي في المقالات المحملة فقط'
                              : 'Searches loaded articles on this device only',
                        ),
                        onChanged: (value) => setState(() => _query = value),
                      ),
                    ),
                    Expanded(
                      child: matches.isEmpty
                          ? ListView(
                              children: [
                                Padding(
                                  padding: const EdgeInsets.all(24),
                                  child: Text(
                                    ar
                                        ? 'لا توجد مقالات موثقة تطابق البحث.'
                                        : 'No verified articles match your search.',
                                    key: const Key('article-search-empty'),
                                    textAlign: TextAlign.center,
                                  ),
                                ),
                                if (showFooter) _paginationFooter(ar),
                              ],
                            )
                          : ListView.builder(
                              itemCount: matches.length + (showFooter ? 1 : 0),
                              padding: const EdgeInsets.all(16),
                              itemBuilder: (context, index) {
                                if (index == matches.length) {
                                  return _paginationFooter(ar);
                                }
                                final article = matches[index];
                                return Card(
                                  child: ListTile(
                                    key: Key('article-' + article.slug),
                                    title: Text(article.title),
                                    subtitle: Text(ar
                                        ? 'موثق بمراجع منشورة — لا يُثبت الاختبار العملي'
                                        : 'Published citations — not a claim of hands-on testing'),
                                    trailing: Icon(ar
                                        ? Icons.chevron_left
                                        : Icons.chevron_right),
                                    onTap: () => Navigator.of(context).push(
                                      MaterialPageRoute<void>(
                                        builder: (_) => VerifiedArticleDetail(
                                          article: article,
                                          arabic: ar,
                                        ),
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                    ),
                  ],
                );
              },
            ),
    );
  }
}

class VerifiedArticleDetail extends StatelessWidget {
  const VerifiedArticleDetail({
    required this.article,
    required this.arabic,
    super.key,
  });

  final VerifiedArticle article;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(article.title)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            arabic
                ? 'محتوى منشور موثق بمراجع؛ ليس تقرير اختبار عملي.'
                : 'Published source-verified reading, not a tested execution report.',
          ),
          const SizedBox(height: 16),
          SelectableText(article.body, key: const Key('verified-article-body')),
          const SizedBox(height: 24),
          Text(arabic ? 'المراجع' : 'Citations',
              style: Theme.of(context).textTheme.titleLarge),
          for (final citation in article.citations)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: SelectableText(citation),
            ),
        ],
      ),
    );
  }
}

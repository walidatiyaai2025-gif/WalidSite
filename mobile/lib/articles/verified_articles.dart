import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../runbooks/offline_guide_text_search.dart';

/// Source-verified public category; never manufacture taxonomy for draft articles.
class VerifiedArticleCategory {
  const VerifiedArticleCategory({required this.slug, required this.name});
  final String slug;
  final String name;

  static final RegExp validSlug = RegExp(r'^[a-z0-9]+(?:-[a-z0-9]+)*$');

  static List<VerifiedArticleCategory> parse(Object? source) {
    if (source == null) return const [];
    if (source is! List || source.length > 30) {
      throw const FormatException('Invalid article categories');
    }
    final seen = <String>{};
    final categories = <VerifiedArticleCategory>[];
    for (final item in source) {
      if (item is! Map<String, dynamic> ||
          item.length != 2 ||
          item['slug'] is! String ||
          item['name'] is! String) {
        throw const FormatException('Invalid article category');
      }
      final slug = item['slug'] as String;
      final name = item['name'] as String;
      if (slug.length > 60 || !validSlug.hasMatch(slug) ||
          !seen.add(slug) || name.trim().isEmpty || name.length > 160) {
        throw const FormatException('Unsafe article category');
      }
      categories.add(VerifiedArticleCategory(slug: slug, name: name));
    }
    return List.unmodifiable(categories);
  }
}

/// Reads ONLY published, evidence-checked articles from the existing public API.
/// No AI diagnosis, authentication or remote caching is implied by this screen.
class VerifiedArticle {
  const VerifiedArticle({
    required this.slug,
    required this.title,
    required this.body,
    required this.citations,
    this.id,
    this.categories = const [],
  });

  /// Only the published server-issued UUID may authorize an account Save.
  /// Older fixtures without an ID cannot claim a resource was saved.
  final String? id;
  final List<VerifiedArticleCategory> categories;
  final String slug;
  final String title;
  final String body;
  final List<String> citations;

  // Keep benign public citation query parameters; never show signed links.
  // Match Laravel's reviewed-source parameter filter at each encoding depth.
  static bool _hasSafeCitationQuery(String rawQuery) {
    // A source can include PHP-style array keys: token[0], source[api_key],
    // or settings[auth][0]. These are credentials too, even if the signed
    // query key was percent-encoded several times. Do not display them.
    final credentialKey = RegExp(
      r'(?:^|[?&#;\[])(?:access[_-]?token|refresh[_-]?token|id[_-]?token|'
      r'token|api[_-]?key|key|auth(?:orization)?|password|passwd|secret|'
      r'signature|sig|session(?:id)?|jwt|bearer|client[_-]?secret|'
      r'private[_-]?key|code|ticket|awsaccesskeyid|'
      r'x[-_]amz[-_][a-z0-9_-]+|x[-_]goog[-_][a-z0-9_-]+)'
      r'\]?(?:\[[^\]\r\n]*\])*=' ,
      caseSensitive: false,
    );
    var query = rawQuery;
    for (var depth = 0; depth < 4; depth++) {
      if (credentialKey.hasMatch(query)) return false;
      final next = Uri.decodeComponent(
        query.replaceAll(RegExp(r'%(?![a-fA-F0-9]{2})'), '%25'),
      );
      if (next == query) return true;
      query = next;
    }
    return false;
  }

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
      final id = item['id'];
      final slug = item['slug'];
      final title = item['title'];
      final body = item['body_markdown'];
      final citations = item['citations'];
      if ((id != null &&
              (id is! String ||
                  !RegExp(r'^[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$')
                      .hasMatch(id))) ||
          slug is! String ||
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
        // Treat API citations as untrusted: allow benign queries, never keys.
        final url = Uri.tryParse(citation);
        final host = url?.host.toLowerCase() ?? '';
        // Links are displayed verbatim: never expose internal resolver
        // names from a compromised or mismatched public API response.
        const privateSuffixes = [
          'localhost', 'local', 'localdomain', 'internal', 'svc', 'lan',
          'home', 'arpa', 'corp', 'intranet', 'private', 'test',
          'invalid', 'onion',
        ];
        if (url == null ||
            url.scheme != 'https' ||
            url.userInfo.isNotEmpty ||
            url.hasFragment ||
            !_hasSafeCitationQuery(url.query) ||
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
        id: id as String?,
        slug: slug,
        title: title,
        body: body,
        citations: List.unmodifiable(validatedCitations),
        categories: VerifiedArticleCategory.parse(item['categories']),
      ));
    }
    return List.unmodifiable(articles);
  }

  /// Re-validate an exact detail against published evidence before display.
  static VerifiedArticle parseDetail(String body, String expectedSlug) {
    if (!RegExp(r'^[A-Za-z0-9-]{1,180}$').hasMatch(expectedSlug) ||
        body.length > 2 * 1024 * 1024) {
      throw const FormatException('Invalid article detail');
    }
    final payload = jsonDecode(body);
    if (payload is! Map<String, dynamic> ||
        payload.length != 1 ||
        payload['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid article detail');
    }
    final article = parseList(jsonEncode({'data': [payload['data']]})).single;
    if (article.slug != expectedSlug) {
      throw const FormatException('Mismatched article detail');
    }
    return article;
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
    this.categories = const [],
  });

  final List<VerifiedArticleCategory> categories;
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
      categories: VerifiedArticleCategory.parse(meta['categories']),
    );
  }
}

class VerifiedArticleReader {
  const VerifiedArticleReader()
      : _clientFactory = null,
        _testingOrigin = null,
        _wholeDeadline = const Duration(seconds: 12);

  // Test injection retains the same public HTTPS origin validation as the
  // production source; it cannot bypass origin restrictions to send traffic
  // to localhost or private addresses. No test settings enter the real app.
  @visibleForTesting
  const VerifiedArticleReader.fixture({
    required HttpClient Function() httpClientFactory,
    required String publicOrigin,
    Duration wholeDeadline = const Duration(milliseconds: 90),
  })  : _clientFactory = httpClientFactory,
        _testingOrigin = publicOrigin,
        _wholeDeadline = wholeDeadline;

  final HttpClient Function()? _clientFactory;
  final String? _testingOrigin;
  final Duration _wholeDeadline;

  Uri? _origin() => _testingOrigin == null
      ? configuredOrigin()
      : validatedOrigin(_testingOrigin);

  HttpClient _newClient() => (_clientFactory?.call() ?? HttpClient())
    ..connectionTimeout = const Duration(seconds: 8);

  // Explicit build-time deployment configuration, never an invented endpoint.
  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const _limit = 2 * 1024 * 1024;

  static Uri? configuredOrigin() => validatedOrigin(apiOrigin);

  // Shared by the production reader and deterministic slow-stream tests.
  // A timeout on individual chunks is insufficient when bytes keep arriving.
  @visibleForTesting
  static Future<T> withinWholeExchange<T>(
    Future<T> Function() exchange, {
    Duration deadline = const Duration(seconds: 12),
  }) => exchange().timeout(deadline);

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
    // Exact label suffix checks: private resolver zones are not safe
    // destinations even when presented as HTTPS fully-qualified hosts.
    const privateSuffixes = [
      'localhost', 'local', 'localdomain', 'internal', 'svc', 'lan',
      'home', 'arpa', 'corp', 'intranet', 'private', 'test',
      'invalid', 'onion',
    ];
    // Require a complete public FQDN; reject internal-only suffixes.
    final publicHostPattern = RegExp(
      r'^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}',
    );
    if (InternetAddress.tryParse(host) != null ||
        publicHostPattern.stringMatch(host) != host ||
        privateSuffixes.any(
          (suffix) => host == suffix || host.endsWith('.' + suffix),
        )) {
      return null;
    }
    return uri;
  }

  Future<List<VerifiedArticle>> load(bool arabic) async =>
      (await loadPage(arabic, 1)).articles;

  /// A Home preview can be revoked since its list was fetched.
  Future<VerifiedArticle> loadDetail(bool arabic, String slug) async {
    if (!RegExp(r'^[A-Za-z0-9-]{1,180}$').hasMatch(slug)) {
      throw const FormatException('Invalid article slug');
    }
    final origin = _origin();
    if (origin == null) {
      throw const FormatException('Article API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/articles/$slug',
      queryParameters: {'lang': arabic ? 'ar' : 'en'},
    );
    final client = _newClient();
    try {
      return await (() async {
        final request = await client.getUrl(url);
        request.followRedirects = false;
        request.headers.set(HttpHeaders.acceptHeader, 'application/json');
        final response = await request.close();
        if (response.statusCode != HttpStatus.ok) {
          throw const HttpException('Reviewed article unavailable');
        }
        final bytes = BytesBuilder(copy: false);
        await for (final chunk in response) {
          if (bytes.length + chunk.length > _limit) {
            throw const FormatException('Article response exceeds size limit');
          }
          bytes.add(chunk);
        }
        return VerifiedArticle.parseDetail(
          utf8.decode(bytes.takeBytes(), allowMalformed: false),
          slug,
        );
      })().timeout(_wholeDeadline);
    } finally {
      client.close(force: true);
    }
  }

  Future<VerifiedArticlePage> loadPage(bool arabic, int page, {String? category}) async {
    if (page < 1 || page > 10000 ||
        (category != null &&
            (category.length > 60 || !VerifiedArticleCategory.validSlug.hasMatch(category)))) {
      throw const FormatException('Invalid article page');
    }
    final origin = _origin();
    if (origin == null) {
      throw const FormatException('Article API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/articles',
      queryParameters: {
        'lang': arabic ? 'ar' : 'en',
        'page': '$page',
        if (category != null) 'category': category,
      },
    );

    final client = _newClient();
    try {
      // One wall-clock deadline covers connect, headers, every streamed byte,
      // UTF-8 decoding and parsing. Idle-per-chunk deadlines permit a slow
      // sender to keep a public article screen stalled indefinitely.
      return await withinWholeExchange(() async {
        final request = await client.getUrl(url);
        request.followRedirects = false;
        request.headers.set(HttpHeaders.acceptHeader, 'application/json');
        final response = await request.close();
        if (response.statusCode != HttpStatus.ok) {
          throw const HttpException('Article service unavailable');
        }

        final buffer = BytesBuilder(copy: false);
        await for (final chunk in response) {
          if (buffer.length + chunk.length > _limit) {
            throw const FormatException('Article response exceeds size limit');
          }
          buffer.add(chunk);
        }
        return VerifiedArticlePage.parse(
          utf8.decode(buffer.takeBytes(), allowMalformed: false), page,
        );
      }, deadline: _wholeDeadline);
    } finally {
      client.close(force: true);
    }
  }
}

typedef ArticleCategoryPageLoader = Future<VerifiedArticlePage> Function(
    int page, String? category);
typedef ArticleLoginAction = Future<String> Function(String email, String password);
typedef ArticleSaveAction = Future<void> Function(String bearer, String articleId);
/// Transfer a freshly authenticated, in-memory session only after the server
/// confirms a Save. This callback never persists or logs bearer credentials.
typedef ArticleSavedNavigation = Future<void> Function(BuildContext context, String bearer);

/// Authorization loss should clear any temporary login intent on this screen.
class ArticleSaveAuthorizationException implements Exception {
  const ArticleSaveAuthorizationException();
}

class VerifiedArticleLibrary extends StatefulWidget {
  const VerifiedArticleLibrary({
    required this.arabic,
    this.loader,
    this.pageLoader,
    this.categoryPageLoader,
    this.detailLoader,
    this.loginAction,
    this.saveAction,
    this.onViewSaved,
    super.key,
  });

  final bool arabic;

  // Compatibility for existing offline/first-page widget tests.
  final Future<List<VerifiedArticle>> Function()? loader;

  // Injectable verified page source for deterministic pagination tests.
  final Future<VerifiedArticlePage> Function(int page)? pageLoader;
  /// Must query the server using this selected category on EVERY page.
  final ArticleCategoryPageLoader? categoryPageLoader;
  final ArticleLoginAction? loginAction;
  final ArticleSaveAction? saveAction;
  final ArticleSavedNavigation? onViewSaved;

  // Injected only by widget tests. Production always refetches the current
  // publication through the verified server detail endpoint.
  final Future<VerifiedArticle> Function(String slug)? detailLoader;

  @override
  State<VerifiedArticleLibrary> createState() => _VerifiedArticleLibraryState();
}

class _VerifiedArticleLibraryState extends State<VerifiedArticleLibrary> {
  late Future<VerifiedArticlePage> _firstPage;
  List<VerifiedArticle> _additional = const [];
  String _query = '';
  String? _category;
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
        widget.pageLoader != oldWidget.pageLoader ||
        widget.categoryPageLoader != oldWidget.categoryPageLoader) {
      _query = '';
      _category = null;
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

    if (widget.categoryPageLoader != null) {
      _firstPage = widget.categoryPageLoader!(1, _category);
    } else if (widget.pageLoader != null) {
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
      _firstPage = const VerifiedArticleReader()
          .loadPage(widget.arabic, 1, category: _category);
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
      final next = await (widget.categoryPageLoader != null
          ? widget.categoryPageLoader!(requested, _category)
          : widget.pageLoader != null
              ? widget.pageLoader!(requested)
              : const VerifiedArticleReader()
                  .loadPage(widget.arabic, requested, category: _category));
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
        widget.categoryPageLoader != null ||
        VerifiedArticleReader.configuredOrigin() != null;
    final supportsCategories = widget.categoryPageLoader != null ||
        (widget.loader == null &&
            widget.pageLoader == null &&
            VerifiedArticleReader.configuredOrigin() != null);

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
                if (articles.isEmpty && (_category == null ||
                    !supportsCategories || first.categories.isEmpty)) {
                  return Center(child: Text(ar
                      ? 'لا توجد مقالات منشورة وموثقة حاليًا.'
                      : 'No source-verified articles have been published yet.'));
                }

                // Search stays entirely on device. Do not send entered terms
                // to the article service; the helper explains loaded-only scope.
                // Reuse the already-tested on-device guide matcher: Arabic
                // diacritics and Alef/Ya spellings are normalized, while all
                // typed keywords may match across the visible title and body.
                // Never send search terms to the server, index citations, or
                // show articles which have not passed the verified-page gate.
                final matches = OfflineGuideTextSearch.normalize(_query).isEmpty
                    ? articles
                    : articles.where((article) =>
                        OfflineGuideTextSearch.matches(
                          _query, [article.title, article.body],
                        )).toList(growable: false);
                final hasMore = _loadedAdditional
                    ? _hasMoreAfterAdditional
                    : first.hasMorePages;
                final showFooter = hasMore || _loadingNext || _nextError != null;

                return Column(
                  children: [
                    if (supportsCategories && first.categories.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                        child: DropdownButtonFormField<String>(
                          key: const Key('article-category-filter'),
                          initialValue: first.categories.any((item) => item.slug == _category)
                              ? _category : '',
                          decoration: InputDecoration(
                            labelText: ar ? 'تصنيف المقالات' : 'Article category',
                            border: const OutlineInputBorder(),
                          ),
                          items: [
                            DropdownMenuItem(
                              value: '',
                              child: Text(ar ? 'كل التصنيفات' : 'All categories'),
                            ),
                            for (final item in first.categories)
                              DropdownMenuItem(
                                value: item.slug,
                                child: Text(item.name),
                              ),
                          ],
                          onChanged: (value) {
                            final selected = value == null || value.isEmpty
                                ? null : value;
                            if (selected == _category) return;
                            setState(() {
                              _category = selected;
                              _query = '';
                              _refresh();
                            });
                          },
                        ),
                      ),
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
                                        builder: (_) => VerifiedArticleFreshDetail(
                                          slug: article.slug,
                                          arabic: ar,
                                          loader: widget.detailLoader,
                                          loginAction: widget.loginAction,
                                          saveAction: widget.saveAction,
                                          onViewSaved: widget.onViewSaved,
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
    return Directionality(
      textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
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
      ),
    );
  }
}

/// MOBILE-07 detail must check the current publication on every open, not
/// trust a list row that may have been withdrawn since it was fetched.
class VerifiedArticleFreshDetail extends StatefulWidget {
  const VerifiedArticleFreshDetail({
    required this.slug,
    required this.arabic,
    this.loader,
    this.loginAction,
    this.saveAction,
    this.onViewSaved,
    super.key,
  });

  final String slug;
  final bool arabic;
  final Future<VerifiedArticle> Function(String slug)? loader;
  final ArticleLoginAction? loginAction;
  final ArticleSaveAction? saveAction;
  final ArticleSavedNavigation? onViewSaved;

  @override
  State<VerifiedArticleFreshDetail> createState() =>
      _VerifiedArticleFreshDetailState();
}

class _VerifiedArticleFreshDetailState extends State<VerifiedArticleFreshDetail> {
  late Future<VerifiedArticle> _detail;
  bool _saving = false;
  bool _saved = false;
  String? _savedBearer;
  bool _openingSaved = false;
  String? _saveMessage;
  int _saveEpoch = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void didUpdateWidget(covariant VerifiedArticleFreshDetail oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.slug != oldWidget.slug ||
        widget.arabic != oldWidget.arabic ||
        widget.loader != oldWidget.loader ||
        widget.loginAction != oldWidget.loginAction ||
        widget.saveAction != oldWidget.saveAction) {
      _saveEpoch++;
      _saving = false;
      _saved = false;
      _savedBearer = null;
      _openingSaved = false;
      _saveMessage = null;
      _refresh();
    }
  }

  void _refresh() {
    // Schedule the request after FutureBuilder has subscribed to the new
    // future. A fast 404/rejection must be rendered as the safe unavailable
    // state, not surface as an unhandled error during a Retry tap.
    final request = Future<VerifiedArticle>(() async {
      final article = await (widget.loader != null
          ? widget.loader!(widget.slug)
          : const VerifiedArticleReader().loadDetail(
              widget.arabic, widget.slug));
      if (article.slug != widget.slug) {
        throw const FormatException('Mismatched article detail');
      }
      return article;
    });
    // A very fast denied/revoked reply may beat the next widget frame during
    // a Retry tap. Attach an immediate error observer so that no transient
    // unhandled error escapes while FutureBuilder subscribes; the UI still
    // observes this original request and displays its safe failure state.
    unawaited(request.then<void>((_) {}, onError: (Object _, StackTrace __) {}));
    _detail = request;
  }

  Future<void> _saveVerified(VerifiedArticle article) async {
    final articleId = article.id;
    if (_saving || _saved || articleId == null ||
        widget.loginAction == null || widget.saveAction == null) return;
    final epoch = _saveEpoch;
    // Claim synchronously: two taps may not open two logins or send two POSTs.
    setState(() {
      _saving = true;
      _saveMessage = null;
    });
    try {
      final bearer = await showDialog<String>(
        context: context,
        barrierDismissible: false,
        builder: (_) => _ArticleSaveLoginDialog(
          arabic: widget.arabic,
          login: widget.loginAction!,
        ),
      );
      if (!mounted || epoch != _saveEpoch || bearer == null) return;
      // A current published detail, not a stale catalog row, supplies this ID.
      await widget.saveAction!(bearer, articleId);
      if (!mounted || epoch != _saveEpoch) return;
      setState(() {
        _saved = true;
        _savedBearer = bearer;
        _saveMessage = widget.arabic
            ? 'تم حفظ المقال الموثق في حسابك.'
            : 'Verified article saved to your account.';
      });
    } catch (error) {
      if (!mounted || epoch != _saveEpoch) return;
      setState(() {
        _savedBearer = null;
        _saveMessage = error is TimeoutException
            ? (widget.arabic
                ? 'لم يصل تأكيد الحفظ. افتح محفوظات حسابك للتحقق قبل إعادة المحاولة.'
                : 'Save confirmation was not received. Check your account Saved list before retrying.')
            : error is ArticleSaveAuthorizationException
                ? (widget.arabic
                    ? 'انتهت صلاحية الجلسة. سجّل الدخول مجددًا للحفظ.'
                    : 'Session no longer authorized. Sign in again to save.')
                : (widget.arabic
                    ? 'تعذر تأكيد حفظ هذا المقال. لم يُعرض على أنه محفوظ.'
                    : 'Article save could not be confirmed. It is not marked saved.');
      });
    } finally {
      if (mounted && epoch == _saveEpoch) {
        setState(() => _saving = false);
      }
    }
  }

  Future<void> _viewSaved() async {
    final bearer = _savedBearer;
    if (!_saved || _openingSaved || bearer == null ||
        widget.onViewSaved == null) return;
    // Account screen takes exclusive ownership of this ephemeral session.
    // Never keep a second reusable copy after the route returns or fails.
    setState(() {
      _openingSaved = true;
      _savedBearer = null;
    });
    try {
      await widget.onViewSaved!(context, bearer);
    } catch (_) {
      // A failed navigation cannot convert a Save into a new API attempt.
    } finally {
      if (mounted) setState(() => _openingSaved = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final arabic = widget.arabic;
    return Directionality(
      textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        appBar: AppBar(title: Text(
          arabic ? 'المقال الموثق' : 'Verified article',
        )),
        body: FutureBuilder<VerifiedArticle>(
          future: _detail,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError || !snapshot.hasData) {
              return Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        arabic
                            ? 'المقال غير متاح حاليًا أو سُحب نشره. تحقق من الاتصال.'
                            : 'This article is unavailable or may have been withdrawn.',
                        key: const Key('verified-article-unavailable'),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 12),
                      OutlinedButton(
                        key: const Key('verified-article-retry'),
                        onPressed: () => setState(_refresh),
                        child: Text(arabic ? 'إعادة التحقق' : 'Retry verification'),
                      ),
                    ],
                  ),
                ),
              );
            }
            final article = snapshot.data!;
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Text(article.title, style: Theme.of(context).textTheme.titleLarge),
                if (article.id != null &&
                    widget.loginAction != null && widget.saveAction != null) ...[
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    key: const Key('verified-article-save'),
                    onPressed: _saving || _saved ? null : () => _saveVerified(article),
                    icon: Icon(_saved ? Icons.bookmark_added : Icons.bookmark_add_outlined),
                    label: Text(_saving
                        ? (arabic ? 'جارٍ التحقق...' : 'Confirming...')
                        : _saved
                            ? (arabic ? 'محفوظ' : 'Saved')
                            : (arabic ? 'احفظ في حسابي' : 'Save to my account')),
                  ),
                  if (_saved && _savedBearer != null &&
                      widget.onViewSaved != null)
                    OutlinedButton.icon(
                      key: const Key('verified-article-view-saved'),
                      onPressed: _openingSaved ? null : _viewSaved,
                      icon: const Icon(Icons.bookmarks_outlined),
                      label: Text(arabic ? 'عرض محفوظاتي' : 'View Saved'),
                    ),
                  if (_saveMessage != null)
                    Semantics(
                      liveRegion: true,
                      child: Text(_saveMessage!, key: const Key('verified-article-save-status')),
                    ),
                ],
                const SizedBox(height: 16),
                Text(arabic
                    ? 'محتوى منشور موثق بمراجع؛ ليس تقرير اختبار عملي.'
                    : 'Published source-verified reading, not a tested execution report.'),
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
            );
          },
        ),
      ),
    );
  }
}

class _ArticleSaveLoginDialog extends StatefulWidget {
  const _ArticleSaveLoginDialog({required this.arabic, required this.login});
  final bool arabic;
  final ArticleLoginAction login;

  @override
  State<_ArticleSaveLoginDialog> createState() => _ArticleSaveLoginDialogState();
}

class _ArticleSaveLoginDialogState extends State<_ArticleSaveLoginDialog> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _signIn() async {
    if (_busy) return;
    final email = _email.text.trim();
    if (email.isEmpty || email.length > 254 ||
        !_email.text.contains('@') || _password.text.isEmpty) {
      setState(() => _error = widget.arabic
          ? 'أدخل بريدًا إلكترونيًا وكلمة مرور صالحين.'
          : 'Enter a valid email and password.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final bearer = await widget.login(email, _password.text);
      if (bearer.isEmpty || bearer.length > 8192) {
        throw const FormatException('Invalid login response');
      }
      if (!mounted) return;
      Navigator.of(context).pop(bearer);
    } catch (_) {
      if (mounted) {
        setState(() => _error = widget.arabic
            ? 'تعذر تسجيل الدخول. تحقق من البيانات أو الاتصال.'
            : 'Could not sign in. Check your credentials or connection.');
      }
    } finally {
      _password.clear();
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return AlertDialog(
      title: Text(ar ? 'تسجيل الدخول لحفظ المقال' : 'Sign in to save this article'),
      content: SizedBox(
        width: 360,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const Key('article-save-login-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.username],
              decoration: InputDecoration(labelText: ar ? 'البريد الإلكتروني' : 'Email'),
            ),
            TextField(
              key: const Key('article-save-login-password'),
              controller: _password,
              obscureText: true,
              autofillHints: const [AutofillHints.password],
              decoration: InputDecoration(labelText: ar ? 'كلمة المرور' : 'Password'),
              onSubmitted: (_) => _signIn(),
            ),
            if (_error != null)
              Text(_error!, key: const Key('article-save-login-error')),
          ],
        ),
      ),
      actions: [
        TextButton(
          key: const Key('article-save-login-cancel'),
          onPressed: _busy ? null : () => Navigator.of(context).pop(),
          child: Text(ar ? 'إلغاء' : 'Cancel'),
        ),
        FilledButton(
          key: const Key('article-save-login-submit'),
          onPressed: _busy ? null : _signIn,
          child: Text(ar ? 'تسجيل الدخول والحفظ' : 'Sign in & save'),
        ),
      ],
    );
  }
}

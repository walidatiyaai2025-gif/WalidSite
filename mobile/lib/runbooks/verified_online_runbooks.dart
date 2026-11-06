import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';

import '../articles/verified_articles.dart';
import 'offline_guide_text_search.dart';
import 'reviewed_runbook_actions.dart';
import 'offline_runbooks.dart';
import 'signed_runbook_sync.dart';

/// Categories are version-reviewed labels, never inferred from drafts.
class ReviewedRunbookCategory {
  const ReviewedRunbookCategory({required this.slug, required this.name});
  final String slug;
  final String name;
  static final RegExp validSlug = RegExp(r'^[a-z0-9]+(?:-[a-z0-9]+)*$');

  static List<ReviewedRunbookCategory> parse(Object? source) {
    if (source == null) return const [];
    if (source is! List || source.length > 200) {
      throw const FormatException('Invalid reviewed category list');
    }
    final seen = <String>{};
    final categories = <ReviewedRunbookCategory>[];
    for (final entry in source) {
      if (entry is! Map<String, dynamic> || entry.length != 2 ||
          entry['slug'] is! String || entry['name'] is! String) {
        throw const FormatException('Invalid reviewed category');
      }
      final slug = entry['slug'] as String;
      final name = entry['name'] as String;
      if (slug.length > 60 || !validSlug.hasMatch(slug) || !seen.add(slug) ||
          name.trim().isEmpty || name.length > 160 ||
          name.runes.any((ch) => ch < 32 || ch == 127)) {
        throw const FormatException('Untrusted reviewed category');
      }
      categories.add(ReviewedRunbookCategory(slug: slug, name: name));
    }
    return List.unmodifiable(categories);
  }
}

/// This is the live public *reviewed* catalog. It is not a cryptographically
/// signed offline download; the independent Ed25519 pack has its own UI.
class ReviewedRunbook {
  const ReviewedRunbook({
    required this.id,
    required this.title,
    required this.markdown,
    required this.version,
    required this.validatedAt,
  });

  final String id;
  final String title;
  final String markdown;
  final int version;
  final DateTime validatedAt;

  static final _uuid = RegExp(
    r'^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
  );

  static ReviewedRunbook fromJson(Object? value) {
    if (value is! Map<String, dynamic> ||
        value.length != 5 ||
        value.keys.toSet().difference({
          'id', 'title', 'markdown', 'version', 'validated_at',
        }).isNotEmpty) {
      throw const FormatException('Invalid reviewed runbook record');
    }
    final id = value['id'];
    final title = value['title'];
    final body = value['markdown'];
    final version = value['version'];
    final timestamp = value['validated_at'];
    final at = timestamp is String ? DateTime.tryParse(timestamp) : null;
    if (id is! String || !_uuid.hasMatch(id) ||
        title is! String || title.trim().isEmpty || title.length > 220 ||
        // Review titles are UI identity labels, never formatting directives.
        // Block C0/DEL and Unicode bidi overrides/isolates that can disguise
        // the published title or reverse the displayed publication identity.
        title.runes.any((ch) =>
            ch < 32 || ch == 127 ||
            (ch >= 0x202a && ch <= 0x202e) ||
            (ch >= 0x2066 && ch <= 0x2069)) ||
        body is! String || body.trim().isEmpty || body.length > 65536 ||
        utf8.encode(body).length > 65536 ||
        version is! int || version < 1 || version > 1000000000 ||
        timestamp is! String || timestamp.length > 50 ||
        !RegExp(r'(?:Z|[+-][0-9]{2}:[0-9]{2})$').hasMatch(timestamp) ||
        at == null || !at.isUtc) {
      throw const FormatException('Unreviewed runbook response');
    }
    return ReviewedRunbook(
      id: id,
      title: title,
      markdown: body,
      version: version,
      validatedAt: at.toUtc(),
    );
  }
}

class ReviewedRunbookPage {
  const ReviewedRunbookPage({
    required this.items,
    required this.currentPage,
    required this.hasMorePages,
    this.categories = const [],
  });

  final List<ReviewedRunbook> items;
  final List<ReviewedRunbookCategory> categories;
  final int currentPage;
  final bool hasMorePages;

  static ReviewedRunbookPage parse(String body, int requestedPage) {
    // The transport caps raw UTF-8 bytes, not Dart UTF-16 code units.
    // Keep that invariant for directly invoked parsers and injected loaders.
    // Check the cheap code-unit bound first to avoid encoding huge strings.
    if (body.length > 1024 * 1024 ||
        utf8.encode(body).length > 1024 * 1024 ||
        requestedPage < 1 || requestedPage > 10000) {
      throw const FormatException('Invalid runbook page size');
    }
    final json = jsonDecode(body);
    if (json is! Map<String, dynamic> ||
        json['data'] is! List ||
        json['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid runbook page');
    }
    final data = json['data'] as List<dynamic>;
    final meta = json['meta'] as Map<String, dynamic>;
    if (data.length > 20 ||
        meta['current_page'] != requestedPage ||
        meta['has_more_pages'] is! bool ||
        (meta['has_more_pages'] == true && (data.isEmpty || requestedPage == 10000))) {
      throw const FormatException('Invalid runbook pagination');
    }
    final items = <ReviewedRunbook>[];
    final ids = <String>{};
    for (final item in data) {
      final reviewed = ReviewedRunbook.fromJson(item);
      if (!ids.add(reviewed.id.toLowerCase())) {
        throw const FormatException('Duplicate runbook identifier');
      }
      items.add(reviewed);
    }
    return ReviewedRunbookPage(
      items: List.unmodifiable(items),
      currentPage: requestedPage,
      hasMorePages: meta['has_more_pages'] as bool,
      categories: ReviewedRunbookCategory.parse(meta['categories']),
    );
  }
}

class ReviewedRunbookReader {
  const ReviewedRunbookReader();

  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static Uri? validatedOrigin(String input) =>
      VerifiedArticleReader.validatedOrigin(input);
  static Uri? configuredOrigin() => validatedOrigin(apiOrigin);

  /// A per-chunk timeout resets whenever bytes arrive. Enforce one total
  /// network deadline so a slow-drip server cannot pin runbook detail views.
  @visibleForTesting
  static Future<T> withinDeadline<T>(
    Future<T> Function() operation, {
    Duration timeout = const Duration(seconds: 12),
  }) => Future<T>.sync(operation).timeout(timeout);

  Future<String> _fetch(Uri url) => fetchHttp(url);

  /// Exercise the exact production transport against disposable loopback
  /// servers without weakening the build-time HTTPS origin validation used
  /// by loadPage/loadDetail. The injected URL/client are test-only.
  @visibleForTesting
  static Future<String> fetchHttp(
    Uri url, {
    HttpClient Function()? clientFactory,
    Duration timeout = const Duration(seconds: 12),
  }) async {
    if (timeout <= Duration.zero) {
      throw const FormatException('Invalid reviewed runbook deadline');
    }
    final client = (clientFactory?.call() ?? HttpClient())
      ..connectionTimeout = const Duration(seconds: 8);
    try {
      return await withinDeadline(() async {
        final request = await client.getUrl(url);
        request.followRedirects = false;
        request.headers.set(HttpHeaders.acceptHeader, 'application/json');
        final response = await request.close();
        if (response.statusCode != HttpStatus.ok) {
          throw const HttpException('Reviewed runbook unavailable');
        }
        final bytes = BytesBuilder(copy: false);
        await for (final chunk in response) {
          if (bytes.length + chunk.length > 1024 * 1024) {
            throw const FormatException('Runbook payload is too large');
          }
          bytes.add(chunk);
        }
        return utf8.decode(bytes.takeBytes(), allowMalformed: false);
      }, timeout: timeout);
    } finally {
      // Stop any ongoing response stream after the single whole-request
      // deadline; a drip-feed cannot reset it at the chunk boundary.
      client.close(force: true);
    }
  }

  Future<ReviewedRunbookPage> loadPage(
    int page, {String? category, bool arabic = false}
  ) async {
    if (page < 1 || page > 10000 ||
        (category != null &&
            (category.length > 60 ||
                !ReviewedRunbookCategory.validSlug.hasMatch(category)))) {
      throw const FormatException('Invalid reviewed runbook filter');
    }
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Reviewed runbook API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/mobile/runbooks',
      queryParameters: {
        'page': '$page',
        'lang': arabic ? 'ar' : 'en',
        if (category != null) 'category': category,
      },
    );
    return ReviewedRunbookPage.parse(await _fetch(url), page);
  }

  Future<ReviewedRunbook> loadDetail(String id) async {
    if (!ReviewedRunbook._uuid.hasMatch(id)) {
      throw const FormatException('Invalid runbook identifier');
    }
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Reviewed runbook API not configured');
    }
    final url = origin.replace(path: '/api/v1/mobile/solutions/' + id);
    final raw = await _fetch(url);
    final response = jsonDecode(raw);
    if (response is! Map<String, dynamic> || response['data'] == null) {
      throw const FormatException('Invalid runbook detail');
    }
    final item = ReviewedRunbook.fromJson(response['data']);
    if (item.id.toLowerCase() != id.toLowerCase()) {
      throw const FormatException('Mismatched runbook identifier');
    }
    return item;
  }
}

/// Download only the selected still-published revision from an Ed25519
/// publisher pack. Revalidate the exact UUID, version, body and review time
/// before the repository is allowed to replace trusted offline bytes.
@visibleForTesting
Future<({OfflineRunbookPack pack, OfflineRunbook item})> downloadReviewedPublication({
  required ReviewedRunbook listed,
  required Future<ReviewedRunbook> Function(String) reload,
  required OfflineRunbookRepository repository,
}) async {
  if (!repository.source.configured) {
    throw const FormatException('Signed publisher is not configured');
  }
  final current = await reload(listed.id);
  if (current.id.toLowerCase() != listed.id.toLowerCase() ||
      current.version != listed.version ||
      current.markdown != listed.markdown ||
      !current.validatedAt.isAtSameMomentAs(listed.validatedAt)) {
    throw const FormatException('Published runbook changed since listing');
  }
  final pack = await repository.syncPublished(PublishedRunbookRevision(
    id: current.id,
    version: current.version,
    markdown: current.markdown,
    validatedAt: current.validatedAt,
  ));
  final matches = pack.items.where((offline) =>
      offline.id == current.id.toLowerCase() &&
      offline.publishedVersion == current.version &&
      offline.publishedMarkdown == current.markdown &&
      offline.publishedValidatedAt?.isAtSameMomentAs(current.validatedAt) ==
          true).toList();
  if (!pack.signedVerified || matches.length != 1) {
    throw const FormatException('Published revision absent from signed pack');
  }
  return (pack: pack, item: matches.single);
}

/// MOBILE-05: live reviewed catalog with deterministic load-more and local
/// search. Never silently substitute an unsigned offline draft after failure.
typedef ReviewedRunbookCategoryPageLoader =
    Future<ReviewedRunbookPage> Function(int page, String? category);

class ReviewedRunbookLibrary extends StatefulWidget {
  const ReviewedRunbookLibrary({
    required this.arabic,
    this.pageLoader,
    this.categoryPageLoader,
    this.detailLoader,
    this.offlineRepository,
    super.key,
  });

  final bool arabic;
  final Future<ReviewedRunbookPage> Function(int page)? pageLoader;
  final ReviewedRunbookCategoryPageLoader? categoryPageLoader;
  final Future<ReviewedRunbook> Function(String id)? detailLoader;
  final OfflineRunbookRepository? offlineRepository;

  @override
  State<ReviewedRunbookLibrary> createState() => _ReviewedRunbookLibraryState();
}

class _ReviewedRunbookLibraryState extends State<ReviewedRunbookLibrary> {
  late Future<ReviewedRunbookPage> _first;
  List<ReviewedRunbook> _extra = const [];
  int _nextPage = 2;
  int _generation = 0;
  bool _loadingMore = false;
  bool _hasMore = false;
  String _query = '';
  String? _category;
  String? _moreError;
  final OfflineRunbookRepository _defaultOfflineRepository =
      OfflineRunbookRepository();
  String? _downloadingId;
  String? _downloadMessageId;
  String? _downloadMessage;
  OfflineRunbookRepository get _repository =>
      widget.offlineRepository ?? _defaultOfflineRepository;

  bool get _configured => widget.categoryPageLoader != null ||
      widget.pageLoader != null ||
      ReviewedRunbookReader.configuredOrigin() != null;

  bool get _supportsCategoryFilter => widget.categoryPageLoader != null ||
      (widget.pageLoader == null &&
          ReviewedRunbookReader.configuredOrigin() != null);

  Future<ReviewedRunbookPage> _fetch(int page) =>
      widget.categoryPageLoader?.call(page, _category) ??
      widget.pageLoader?.call(page) ??
      const ReviewedRunbookReader().loadPage(
        page, category: _category, arabic: widget.arabic,
      );

  @override
  void initState() {
    super.initState();
    _reset();
  }

  @override
  void didUpdateWidget(covariant ReviewedRunbookLibrary oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.arabic != widget.arabic ||
        oldWidget.pageLoader != widget.pageLoader ||
        oldWidget.categoryPageLoader != widget.categoryPageLoader) {
      if (oldWidget.pageLoader != widget.pageLoader ||
          oldWidget.categoryPageLoader != widget.categoryPageLoader) {
        _category = null;
      }
      _reset();
    }
  }

  void _reset() {
    _generation++;
    _extra = const [];
    _nextPage = 2;
    _hasMore = false;
    _moreError = null;
    _loadingMore = false;
    _downloadingId = null;
    _downloadMessageId = null;
    _downloadMessage = null;
    _query = '';
    _first = _configured ? _fetch(1) : Future.value(
      const ReviewedRunbookPage(
        items: [], currentPage: 1, hasMorePages: false,
      ),
    );
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || _nextPage > 10000) return;
    final generation = _generation;
    final page = _nextPage;
    setState(() {
      _loadingMore = true;
      _moreError = null;
    });
    try {
      final batch = await _fetch(page);
      final first = await _first;
      if (!mounted || generation != _generation) return;
      if (batch.currentPage != page) {
        throw const FormatException('Unexpected runbook page');
      }
      final ids = <String>{
        for (final item in first.items) item.id.toLowerCase(),
        for (final item in _extra) item.id.toLowerCase(),
      };
      for (final item in batch.items) {
        if (!ids.add(item.id.toLowerCase())) {
          throw const FormatException('Repeated reviewed runbook');
        }
      }
      setState(() {
        _extra = [..._extra, ...batch.items];
        _nextPage++;
        _hasMore = batch.hasMorePages;
      });
    } catch (_) {
      if (!mounted || generation != _generation) return;
      setState(() => _moreError = widget.arabic
          ? 'تعذر تحميل المزيد. حاول مرة أخرى.'
          : 'Could not load more. Try again.');
    } finally {
      if (mounted && generation == _generation) {
        setState(() => _loadingMore = false);
      }
    }
  }

  Future<void> _download(ReviewedRunbook item) async {
    // A selected item claims the operation before the first async boundary.
    if (_downloadingId != null || !_repository.source.configured) return;
    final generation = _generation;
    setState(() {
      _downloadingId = item.id;
      _downloadMessageId = item.id;
      _downloadMessage = null;
    });
    try {
      final selected = await downloadReviewedPublication(
        listed: item,
        reload: widget.detailLoader ??
            const ReviewedRunbookReader().loadDetail,
        repository: _repository,
      );
      if (!mounted || generation != _generation) return;
      setState(() => _downloadMessage = widget.arabic
          ? 'تم التحقق من النسخة الموقّعة وحفظها دون إنترنت.'
          : 'Signed published revision verified and stored offline.');
      await Navigator.of(context).push<void>(MaterialPageRoute<void>(
        builder: (_) => OfflineRunbookDetail(
          item: selected.item, pack: selected.pack, arabic: widget.arabic,
        ),
      ));
    } catch (_) {
      if (!mounted || generation != _generation) return;
      setState(() => _downloadMessage = widget.arabic
          ? 'تعذّر توثيق التنزيل. احتُفظ بالنسخة المحلية السابقة.'
          : 'Download could not be verified. Previous offline content was kept.');
    } finally {
      if (mounted && generation == _generation) {
        setState(() => _downloadingId = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'الأدلة المنشورة' : 'Verified runbooks')),
      body: SafeArea(
        child: !_configured
            ? Center(child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar ? 'لم يتم إعداد خادم الأدلة المنشورة بعد. الأدلة المحلية متاحة في قسم منفصل.'
                      : 'Verified runbook server is not configured. Local offline guides are available separately.',
                  key: const Key('online-runbook-unconfigured'),
                  textAlign: TextAlign.center,
                ),
              ))
            : FutureBuilder<ReviewedRunbookPage>(
                future: _first,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator(
                      key: Key('online-runbook-loading'),
                    ));
                  }
                  if (snapshot.hasError) {
                    return Center(child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar ? 'تعذر تحميل الأدلة المنشورة.'
                            : 'Could not load verified runbooks.'),
                        const SizedBox(height: 12),
                        FilledButton(
                          key: const Key('online-runbook-retry'),
                          onPressed: () => setState(_reset),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ));
                  }
                  final first = snapshot.data!;
                  if (_nextPage == 2 && _extra.isEmpty) {
                    _hasMore = first.hasMorePages;
                  }
                  final items = [...first.items, ..._extra];
                  final visible = items.where((item) =>
                      OfflineGuideTextSearch.matches(
                        _query, [item.title, item.markdown],
                      )).toList(growable: false);
                  return ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Text(ar
                          ? 'يُعاد التحقق من اعتماد الدليل عند فتحه. هذه ليست نسخة دون إنترنت.'
                          : 'Each guide is rechecked before opening. This is not an offline signed copy.'),
                      const SizedBox(height: 12),
                      if (_supportsCategoryFilter && first.categories.isNotEmpty) ...[
                        DropdownButtonFormField<String>(
                          key: const Key('online-runbook-category-filter'),
                          initialValue: first.categories.any((category) =>
                              category.slug == _category) ? _category : '',
                          decoration: InputDecoration(
                            labelText: ar ? 'تصنيف الأدلة' : 'Runbook category',
                            border: const OutlineInputBorder(),
                          ),
                          items: [
                            DropdownMenuItem(
                              value: '',
                              child: Text(ar ? 'كل التصنيفات' : 'All categories'),
                            ),
                            for (final category in first.categories)
                              DropdownMenuItem(
                                value: category.slug,
                                child: Text(category.name),
                              ),
                          ],
                          onChanged: _downloadingId != null ? null : (value) {
                            final selected = value == null || value.isEmpty
                                ? null : value;
                            if (selected == _category) return;
                            setState(() {
                              _category = selected;
                              _reset();
                            });
                          },
                        ),
                        const SizedBox(height: 12),
                      ],
                      TextField(
                        key: const Key('online-runbook-search'),
                        onChanged: (value) => setState(() => _query = value),
                        decoration: InputDecoration(
                          labelText: ar ? 'ابحث في الأدلة' : 'Search runbooks',
                          prefixIcon: const Icon(Icons.search),
                          border: const OutlineInputBorder(),
                        ),
                      ),
                      const SizedBox(height: 14),
                      if (!_repository.source.configured)
                        Text(
                          ar ? 'التنزيل الموقّع غير مهيأ. لن تُحفظ نسخة غير موثوقة.'
                              : 'Signed downloads are not configured; no untrusted copy is cached.',
                          key: const Key('online-runbook-download-unconfigured'),
                        ),
                      if (items.isEmpty)
                        Text(
                          ar
                              ? (_category == null
                                  ? 'لا توجد أدلة منشورة ومعتمدة حاليًا.'
                                  : 'لا توجد أدلة معتمدة ضمن هذا التصنيف.')
                              : (_category == null
                                  ? 'No reviewed runbooks have been published yet.'
                                  : 'No reviewed runbooks in this category.'),
                          key: const Key('online-runbook-empty'),
                        )
                      else if (visible.isEmpty)
                        Text(
                          ar ? 'لا توجد نتائج مطابقة في الأدلة المحمّلة.'
                              : 'No matching results in loaded runbooks.',
                          key: const Key('online-runbook-search-empty'),
                        ),
                      for (final item in visible)
                        Card(child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            ListTile(
                              key: Key('online-runbook-' + item.id),
                              leading: const Icon(Icons.fact_check_outlined),
                              title: Text(item.title),
                              subtitle: Text(ar
                                  ? 'الإصدار ' + item.version.toString()
                                  : 'Version ' + item.version.toString()),
                              trailing: Icon(ar
                                  ? Icons.chevron_left : Icons.chevron_right),
                              onTap: () => Navigator.of(context).push(
                                MaterialPageRoute<void>(builder: (_) =>
                                  ReviewedRunbookDetail(
                                    id: item.id, arabic: ar,
                                    loader: widget.detailLoader,
                                    offlineRepository: _repository,
                                  ),
                                ),
                              ),
                            ),
                            Padding(
                              padding: const EdgeInsetsDirectional.only(
                                  start: 12, end: 12, bottom: 8),
                              child: Align(
                                alignment: AlignmentDirectional.centerEnd,
                                child: OutlinedButton.icon(
                                  key: Key('online-runbook-download-' + item.id),
                                  onPressed: _downloadingId != null ||
                                          !_repository.source.configured
                                      ? null : () => _download(item),
                                  icon: const Icon(Icons.download_for_offline_outlined),
                                  label: Text(_downloadingId == item.id
                                      ? (ar ? 'جارٍ التوثيق...' : 'Verifying...')
                                      : (ar ? 'تنزيل النسخة الموقّعة'
                                          : 'Download signed revision')),
                                ),
                              ),
                            ),
                            if (_downloadMessageId == item.id &&
                                _downloadMessage != null)
                              Padding(
                                padding: const EdgeInsetsDirectional.only(
                                    start: 12, end: 12, bottom: 12),
                                child: Text(
                                  _downloadMessage!,
                                  key: const Key('online-runbook-download-status'),
                                ),
                              ),
                          ],
                        )),
                      if (_moreError != null)
                        Text(_moreError!, key: const Key('online-runbook-more-error')),
                      if (_hasMore)
                        FilledButton(
                          key: const Key('online-runbook-more'),
                          onPressed: _loadingMore ? null : _loadMore,
                          child: Text(_loadingMore
                              ? (ar ? 'جارٍ التحميل...' : 'Loading...')
                              : (ar ? 'عرض المزيد' : 'Load more')),
                        ),
                    ],
                  );
                },
              ),
      ),
    );
  }
}

/// MOBILE-04: never display previously listed body as verified detail.
/// Always fetch a fresh UUID-pinned public response before rendering.
typedef ReviewedRunbookLoginAction = Future<RunbookSession> Function(
  String email,
  String password,
);
typedef ReviewedRunbookSaveAction = Future<void> Function(
  String token,
  String runbookId,
);
typedef ReviewedRunbookShareAction = Future<bool> Function(
  String text,
  String title,
);

class ReviewedRunbookDetail extends StatefulWidget {
  const ReviewedRunbookDetail({
    required this.id,
    required this.arabic,
    this.loader,
    this.initialBearer,
    this.loginAction,
    this.saveAction,
    this.shareAction,
    this.offlineRepository,
    super.key,
  });

  final String id;
  final bool arabic;
  final Future<ReviewedRunbook> Function(String id)? loader;
  final String? initialBearer;
  final ReviewedRunbookLoginAction? loginAction;
  final ReviewedRunbookSaveAction? saveAction;
  final ReviewedRunbookShareAction? shareAction;
  final OfflineRunbookRepository? offlineRepository;

  @override
  State<ReviewedRunbookDetail> createState() => _ReviewedRunbookDetailState();
}

class _ReviewedRunbookDetailState extends State<ReviewedRunbookDetail> {
  late Future<ReviewedRunbook> _future;
  String? _bearer;
  bool _saving = false;
  bool _sharing = false;
  bool _downloading = false;
  String? _downloadMessage;
  final OfflineRunbookRepository _defaultOfflineRepository =
      OfflineRunbookRepository();
  bool _saved = false;
  String? _actionMessage;
  int _detailEpoch = 0;

  static String? _validatedBearer(String? token) =>
      token != null && token.isNotEmpty && token.length <= 8192
          ? token
          : null;

  @override
  void initState() {
    super.initState();
    _bearer = _validatedBearer(widget.initialBearer);
    _refresh();
  }

  @override
  void didUpdateWidget(covariant ReviewedRunbookDetail oldWidget) {
    super.didUpdateWidget(oldWidget);
    final guideChanged =
        oldWidget.id != widget.id || oldWidget.loader != widget.loader;
    final accountChanged = oldWidget.initialBearer != widget.initialBearer;
    if (guideChanged || accountChanged) {
      // A different account viewing the SAME guide is as strong an isolation
      // boundary as opening a different guide. Never transfer a saved flag,
      // pending bookmark, prior bearer or old asynchronous success to it.
      // Incrementing the epoch also discards late share/login completions.
      _detailEpoch++;
      _bearer = _validatedBearer(widget.initialBearer);
      _saving = false;
      _sharing = false;
      _downloading = false;
      _downloadMessage = null;
      _saved = false;
      _actionMessage = null;
      if (guideChanged) _refresh();
    }
  }

  void _refresh() {
    _future = widget.loader?.call(widget.id) ??
        const ReviewedRunbookReader().loadDetail(widget.id);
  }

  ReviewedRunbookLoginAction get _login => widget.loginAction ??
      (String email, String password) =>
          const ReviewedRunbookActionsClient().login(email, password);

  ReviewedRunbookSaveAction get _save => widget.saveAction ??
      (String token, String id) =>
          const ReviewedRunbookActionsClient().saveRunbook(token, id);

  String _actionFailure(Object error, {required bool login}) {
    final ar = widget.arabic;
    if (error is RunbookActionHttpException) {
      return switch (error.statusCode) {
        401 || 403 => login
            ? (ar
                ? 'تعذر تسجيل الدخول بهذه البيانات.'
                : 'Could not sign in with those credentials.')
            : (ar
                ? 'انتهت الجلسة. سجّل الدخول مرة أخرى للحفظ.'
                : 'Your session expired. Sign in again to save.'),
        429 => ar
            ? 'تم بلوغ حد المحاولات. حاول لاحقًا.'
            : 'Too many attempts. Try again later.',
        422 => ar
            ? 'تعذر حفظ هذا الدليل بالحالة الحالية.'
            : 'This reviewed guide could not be saved in its current state.',
        _ => ar
            ? 'تعذر إكمال الطلب بأمان.'
            : 'Could not complete the request safely.',
      };
    }
    return ar
        ? 'تعذر الوصول إلى خدمة الحساب بأمان.'
        : 'Could not reach the account service safely.';
  }

  Future<String?> _promptForBearer() {
    return showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _RunbookLoginDialog(
        arabic: widget.arabic,
        loginAction: _login,
      ),
    );
  }

  Future<void> _saveReviewed(ReviewedRunbook item) async {
    if (_saving || _saved) return;
    final epoch = _detailEpoch;

    // Claim the intent before opening the login dialog: two taps in the same
    // frame must not open two dialogs or submit two save POSTs.
    setState(() {
      _saving = true;
      _actionMessage = null;
    });
    try {
      var bearer = _bearer;
      if (bearer == null) {
        bearer = await _promptForBearer();
        if (!mounted || epoch != _detailEpoch || bearer == null) return;
        _bearer = bearer;
      }
      await _save(bearer, item.id);
      if (!mounted || epoch != _detailEpoch) return;
      setState(() {
        _saved = true;
        // Do not retain a bearer after its only required action.
        _bearer = null;
        _actionMessage = widget.arabic
            ? 'تم حفظ الدليل المراجع في حسابك.'
            : 'The reviewed guide was saved to your account.';
      });
    } catch (error) {
      if (!mounted || epoch != _detailEpoch) return;
      if (error is RunbookActionHttpException &&
          (error.statusCode == 401 || error.statusCode == 403)) {
        _bearer = null;
      }
      // A timed-out POST might already be committed. Never claim that
      // an unacknowledged save succeeded or failed.
      setState(() => _actionMessage = error is TimeoutException
          ? (widget.arabic
              ? 'لم يصل تأكيد الحفظ. تحقق من محفوظات حسابك قبل إعادة المحاولة.'
              : 'Save confirmation was not received. Check your saved resources before retrying.')
          : _actionFailure(error, login: false));
    } finally {
      if (mounted && epoch == _detailEpoch) {
        setState(() => _saving = false);
      }
    }
  }

  Future<void> _downloadReviewed(ReviewedRunbook item) async {
    if (_downloading) return;
    final epoch = _detailEpoch;
    final repository = widget.offlineRepository ?? _defaultOfflineRepository;
    if (!repository.source.configured) return;
    setState(() {
      _downloading = true;
      _downloadMessage = null;
    });
    try {
      final selected = await downloadReviewedPublication(
        listed: item,
        reload: widget.loader ?? const ReviewedRunbookReader().loadDetail,
        repository: repository,
      );
      if (!mounted || epoch != _detailEpoch) return;
      setState(() => _downloadMessage = widget.arabic
          ? 'تم التحقق من النسخة الموقعة وحفظها محليًا.'
          : 'Signed published revision verified and saved offline.');
      await Navigator.of(context).push(MaterialPageRoute<void>(
        builder: (_) => OfflineRunbookDetail(
          item: selected.item,
          pack: selected.pack,
          arabic: widget.arabic,
        ),
      ));
    } catch (_) {
      if (!mounted || epoch != _detailEpoch) return;
      setState(() => _downloadMessage = widget.arabic
          ? 'تعذر التحقق من التنزيل. النسخة المحلية السابقة لم تتغير.'
          : 'Download verification failed. Prior offline content was kept.');
    } finally {
      if (mounted && epoch == _detailEpoch) {
        setState(() => _downloading = false);
      }
    }
  }

  String _shareText(ReviewedRunbook item) {
    final versionLabel = widget.arabic
        ? 'إصدار مراجع: ${item.version}'
        : 'Reviewed version: ${item.version}';
    return '${item.title}\n\n${item.markdown}\n\n$versionLabel';
  }

  Future<void> _shareReviewed(
    ReviewedRunbook item,
    BuildContext buttonContext,
  ) async {
    if (_sharing) return;
    final epoch = _detailEpoch;
    setState(() {
      _sharing = true;
      _actionMessage = null;
    });
    try {
      final text = _shareText(item);
      final custom = widget.shareAction;
      final opened = custom != null
          ? await custom(text, item.title)
          : await _platformShare(text, item.title, buttonContext);
      if (!mounted || epoch != _detailEpoch) return;
      setState(() {
        _actionMessage = opened
            ? (widget.arabic
                ? 'تم فتح خيارات المشاركة.'
                : 'Share options opened.')
            : (widget.arabic
                ? 'المشاركة غير متاحة على هذا الجهاز.'
                : 'Sharing is unavailable on this device.');
      });
    } catch (_) {
      if (!mounted || epoch != _detailEpoch) return;
      setState(() {
        _actionMessage = widget.arabic
            ? 'تعذر فتح المشاركة بأمان.'
            : 'Could not open sharing safely.';
      });
    } finally {
      if (mounted && epoch == _detailEpoch) {
        setState(() => _sharing = false);
      }
    }
  }

  Future<bool> _platformShare(
    String text,
    String title,
    BuildContext buttonContext,
  ) async {
    final box = buttonContext.findRenderObject() as RenderBox?;
    final origin = box == null
        ? null
        : box.localToGlobal(Offset.zero) & box.size;
    final result = await SharePlus.instance.share(ShareParams(
      text: text,
      title: title,
      subject: title,
      sharePositionOrigin: origin,
    ));
    return result.status != ShareResultStatus.unavailable;
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Directionality(
      textDirection: ar ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        appBar: AppBar(title: Text(ar ? 'تفاصيل الحل' : 'Solution detail')),
        body: SafeArea(
          child: FutureBuilder<ReviewedRunbook>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator(
                  key: Key('online-runbook-detail-loading'),
                ));
              }
              if (snapshot.hasError ||
                  snapshot.data?.id.toLowerCase() != widget.id.toLowerCase()) {
                return Center(child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      ar
                          ? 'هذا الدليل غير متاح أو سُحب اعتماده.'
                          : 'This runbook is unavailable or its approval was withdrawn.',
                      key: const Key('online-runbook-detail-unavailable'),
                    ),
                    const SizedBox(height: 12),
                    FilledButton(
                      key: const Key('online-runbook-detail-retry'),
                      onPressed: () => setState(_refresh),
                      child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                    ),
                  ],
                ));
              }
              final item = snapshot.data!;
              return Semantics(
                identifier: 'walidsite.mobile.04',
                child: ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  Text(
                    item.title,
                    style: Theme.of(context).textTheme.headlineSmall,
                  ),
                  const SizedBox(height: 10),
                  Text(
                    (ar ? 'إصدار تمت مراجعته: ' : 'Reviewed version: ') +
                        item.version.toString(),
                    key: const Key('online-runbook-review-version'),
                  ),
                  Text(
                    (ar ? 'تاريخ الاعتماد (UTC): ' : 'Approved at (UTC): ') +
                        item.validatedAt.toIso8601String(),
                  ),
                  const SizedBox(height: 18),
                  SelectableText(
                    item.markdown,
                    key: const Key('online-runbook-body'),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    key: const Key('online-runbook-copy'),
                    onPressed: () async {
                      final epoch = _detailEpoch;
                      await Clipboard.setData(
                        ClipboardData(text: item.markdown),
                      );
                      if (!mounted || epoch != _detailEpoch) return;
                      setState(() {
                        _actionMessage = ar
                            ? 'تم نسخ الخطوات المراجعة.'
                            : 'Reviewed steps copied.';
                      });
                    },
                    icon: const Icon(Icons.copy_outlined),
                    label: Text(ar ? 'نسخ الخطوات' : 'Copy steps'),
                  ),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    key: const Key('online-runbook-save'),
                    onPressed: _saving || _saved
                        ? null
                        : () => _saveReviewed(item),
                    icon: Icon(_saved
                        ? Icons.bookmark_added_outlined
                        : Icons.bookmark_add_outlined),
                    label: Text(_saved
                        ? (ar ? 'تم الحفظ' : 'Saved')
                        : _saving
                            ? (ar ? 'جارٍ الحفظ...' : 'Saving...')
                            : (ar ? 'حفظ' : 'Save')),
                  ),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    key: const Key('online-runbook-download'),
                    onPressed: _downloading ||
                            !(widget.offlineRepository ??
                                _defaultOfflineRepository).source.configured
                        ? null
                        : () => _downloadReviewed(item),
                    icon: const Icon(Icons.download_for_offline_outlined),
                    label: Text(_downloading
                        ? (ar ? 'جارٍ التحقق من النسخة...' : 'Verifying download...')
                        : (ar ? 'تنزيل النسخة الموقّعة دون إنترنت'
                            : 'Download signed offline revision')),
                  ),
                  if (!(widget.offlineRepository ??
                      _defaultOfflineRepository).source.configured)
                    Text(
                      ar ? 'التنزيل الموقّع غير مهيأ؛ لن تُحفظ نسخة غير موثقة.'
                          : 'Signed download is not configured; no unverified copy is cached.',
                      key: const Key('online-runbook-download-unconfigured'),
                    ),
                  if (_downloadMessage != null)
                    Text(
                      _downloadMessage!,
                      key: const Key('online-runbook-download-status'),
                    ),
                  const SizedBox(height: 8),
                  Builder(builder: (buttonContext) => OutlinedButton.icon(
                    key: const Key('online-runbook-share'),
                    onPressed: _sharing
                        ? null
                        : () => _shareReviewed(item, buttonContext),
                    icon: const Icon(Icons.share_outlined),
                    label: Text(_sharing
                        ? (ar ? 'جارٍ فتح المشاركة...' : 'Opening share...')
                        : (ar ? 'مشاركة' : 'Share')),
                  )),
                  if (_actionMessage != null) ...[
                    const SizedBox(height: 10),
                    Semantics(
                      liveRegion: true,
                      child: Text(
                        _actionMessage!,
                        key: const Key('online-runbook-action-message'),
                      ),
                    ),
                  ],
                  const SizedBox(height: 8),
                  Text(ar
                      ? 'المصدر: نسخة عامة مُعتمدة من الخادم، وليست حزمة دون إنترنت موقّعة.'
                      : 'Source: server-reviewed public edition, not a signed offline package.'),
                ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _RunbookLoginDialog extends StatefulWidget {
  const _RunbookLoginDialog({
    required this.arabic,
    required this.loginAction,
  });

  final bool arabic;
  final ReviewedRunbookLoginAction loginAction;

  @override
  State<_RunbookLoginDialog> createState() => _RunbookLoginDialogState();
}

class _RunbookLoginDialogState extends State<_RunbookLoginDialog> {
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

  Future<void> _submit() async {
    if (_busy) return;
    final email = _email.text.trim();
    final password = _password.text;
    if (email.isEmpty || password.isEmpty) {
      setState(() => _error = widget.arabic
          ? 'أدخل البريد الإلكتروني وكلمة المرور.'
          : 'Enter email and password.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final session = await widget.loginAction(email, password);
      _password.clear();
      if (!mounted) return;
      // Do not pass an expired or malformed bearer to the save action.
      // The backend remains the authority for any subsequent revocation.
      final expired = session.expiresAt != null &&
          !session.expiresAt!.isAfter(DateTime.now().toUtc());
      if (expired || session.token.trim().isEmpty ||
          session.token.length > 8192) {
        setState(() => _error = expired
            ? (widget.arabic
                ? 'انتهت صلاحية الجلسة. سجّل الدخول مرة أخرى.'
                : 'Your session expired. Sign in again.')
            : (widget.arabic
                ? 'تعذر تسجيل الدخول بأمان. حاول مرة أخرى.'
                : 'Could not sign in safely. Try again.'));
        return;
      }
      Navigator.of(context).pop(session.token);
    } catch (error) {
      _password.clear();
      if (!mounted) return;
      setState(() {
        _error = error is RunbookActionHttpException
            ? switch (error.statusCode) {
                401 || 403 => widget.arabic
                    ? 'تعذر تسجيل الدخول بهذه البيانات.'
                    : 'Could not sign in with those credentials.',
                429 => widget.arabic
                    ? 'تم بلوغ حد المحاولات. حاول لاحقًا.'
                    : 'Too many attempts. Try again later.',
                _ => widget.arabic
                    ? 'تعذر تسجيل الدخول بأمان.'
                    : 'Could not sign in safely.',
              }
            : widget.arabic
                ? 'تعذر الوصول إلى خدمة تسجيل الدخول بأمان.'
                : 'Could not reach the sign-in service safely.';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Directionality(
      textDirection: ar ? TextDirection.rtl : TextDirection.ltr,
      child: AlertDialog(
        title: Text(ar ? 'تسجيل الدخول للحفظ' : 'Sign in to save'),
        content: SingleChildScrollView(child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(ar
                ? 'الحفظ مرتبط بحسابك. لن يتم حفظ كلمة المرور على الجهاز.'
                : 'Saving is tied to your account. Your password is not stored on this device.'),
            const SizedBox(height: 12),
            TextField(
              key: const Key('online-runbook-login-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              decoration: InputDecoration(
                labelText: ar ? 'البريد الإلكتروني' : 'Email',
                border: const OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('online-runbook-login-password'),
              controller: _password,
              obscureText: true,
              enableSuggestions: false,
              autocorrect: false,
              decoration: InputDecoration(
                labelText: ar ? 'كلمة المرور' : 'Password',
                border: const OutlineInputBorder(),
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 10),
              Text(
                _error!,
                key: const Key('online-runbook-login-error'),
              ),
            ],
          ],
        )),
        actions: [
          TextButton(
            key: const Key('online-runbook-login-cancel'),
            onPressed: _busy ? null : () => Navigator.of(context).pop(),
            child: Text(ar ? 'إلغاء' : 'Cancel'),
          ),
          FilledButton(
            key: const Key('online-runbook-login-submit'),
            onPressed: _busy ? null : _submit,
            child: Text(_busy
                ? (ar ? 'جارٍ تسجيل الدخول...' : 'Signing in...')
                : (ar ? 'تسجيل الدخول والحفظ' : 'Sign in and save')),
          ),
        ],
      ),
    );
  }
}

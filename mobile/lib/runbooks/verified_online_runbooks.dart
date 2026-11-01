import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../articles/verified_articles.dart';
import 'offline_guide_text_search.dart';

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
        body is! String || body.trim().isEmpty || body.length > 65536 ||
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
  });

  final List<ReviewedRunbook> items;
  final int currentPage;
  final bool hasMorePages;

  static ReviewedRunbookPage parse(String body, int requestedPage) {
    if (body.length > 1024 * 1024 ||
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
    );
  }
}

class ReviewedRunbookReader {
  const ReviewedRunbookReader();

  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static Uri? validatedOrigin(String input) =>
      VerifiedArticleReader.validatedOrigin(input);
  static Uri? configuredOrigin() => validatedOrigin(apiOrigin);

  Future<String> _fetch(Uri url) async {
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Reviewed runbook unavailable');
      }
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > 1024 * 1024) {
          throw const FormatException('Runbook payload is too large');
        }
        bytes.add(chunk);
      }
      return utf8.decode(bytes.takeBytes(), allowMalformed: false);
    } finally {
      client.close(force: true);
    }
  }

  Future<ReviewedRunbookPage> loadPage(int page) async {
    if (page < 1 || page > 10000) {
      throw const FormatException('Invalid runbook page');
    }
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Reviewed runbook API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/mobile/runbooks',
      queryParameters: {'page': '$page'},
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

/// MOBILE-05: live reviewed catalog with deterministic load-more and local
/// search. Never silently substitute an unsigned offline draft after failure.
class ReviewedRunbookLibrary extends StatefulWidget {
  const ReviewedRunbookLibrary({
    required this.arabic,
    this.pageLoader,
    this.detailLoader,
    super.key,
  });

  final bool arabic;
  final Future<ReviewedRunbookPage> Function(int page)? pageLoader;
  final Future<ReviewedRunbook> Function(String id)? detailLoader;

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
  String? _moreError;

  bool get _configured => widget.pageLoader != null ||
      ReviewedRunbookReader.configuredOrigin() != null;

  Future<ReviewedRunbookPage> _fetch(int page) =>
      widget.pageLoader?.call(page) ??
      const ReviewedRunbookReader().loadPage(page);

  @override
  void initState() {
    super.initState();
    _reset();
  }

  @override
  void didUpdateWidget(covariant ReviewedRunbookLibrary oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.arabic != widget.arabic ||
        oldWidget.pageLoader != widget.pageLoader) {
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
                      if (items.isEmpty)
                        Text(
                          ar ? 'لا توجد أدلة منشورة ومعتمدة حاليًا.'
                              : 'No reviewed runbooks have been published yet.',
                          key: const Key('online-runbook-empty'),
                        )
                      else if (visible.isEmpty)
                        Text(
                          ar ? 'لا توجد نتائج مطابقة في الأدلة المحمّلة.'
                              : 'No matching results in loaded runbooks.',
                          key: const Key('online-runbook-search-empty'),
                        ),
                      for (final item in visible)
                        Card(child: ListTile(
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
                                id: item.id,
                                arabic: ar,
                                loader: widget.detailLoader,
                              ),
                            ),
                          ),
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
class ReviewedRunbookDetail extends StatefulWidget {
  const ReviewedRunbookDetail({
    required this.id,
    required this.arabic,
    this.loader,
    super.key,
  });

  final String id;
  final bool arabic;
  final Future<ReviewedRunbook> Function(String id)? loader;

  @override
  State<ReviewedRunbookDetail> createState() => _ReviewedRunbookDetailState();
}

class _ReviewedRunbookDetailState extends State<ReviewedRunbookDetail> {
  late Future<ReviewedRunbook> _future;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  void _refresh() {
    _future = widget.loader?.call(widget.id) ??
        const ReviewedRunbookReader().loadDetail(widget.id);
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Scaffold(
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
            if (snapshot.hasError || snapshot.data?.id.toLowerCase() != widget.id.toLowerCase()) {
              return Center(child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(ar ? 'هذا الدليل غير متاح أو سُحب اعتماده.'
                      : 'This runbook is unavailable or its approval was withdrawn.',
                    key: const Key('online-runbook-detail-unavailable')),
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
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Text(item.title, style: Theme.of(context).textTheme.headlineSmall),
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
                    await Clipboard.setData(ClipboardData(text: item.markdown));
                  },
                  icon: const Icon(Icons.copy_outlined),
                  label: Text(ar ? 'نسخ الخطوات' : 'Copy steps'),
                ),
                const SizedBox(height: 8),
                Text(ar
                    ? 'المصدر: نسخة عامة مُعتمدة من الخادم، وليست حزمة دون إنترنت موقّعة.'
                    : 'Source: server-reviewed public edition, not a signed offline package.'),
              ],
            );
          },
        ),
      ),
    );
  }
}

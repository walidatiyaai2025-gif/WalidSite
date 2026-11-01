import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';

/// The canonical MOBILE-08 list is a public catalog, never a progress or
/// certification claim. All user-specific learning state remains server-side.
class VerifiedLesson {
  const VerifiedLesson({required this.title, required this.description});
  final String title;
  final String description;
}

class VerifiedLearningTrack {
  const VerifiedLearningTrack({
    required this.id,
    required this.slug,
    required this.title,
    required this.lessons,
  });

  final String id;
  final String slug;
  final String title;
  final List<VerifiedLesson> lessons;
}

class VerifiedLearningPage {
  const VerifiedLearningPage({
    required this.tracks,
    required this.currentPage,
    required this.hasMorePages,
  });

  final List<VerifiedLearningTrack> tracks;
  final int currentPage;
  final bool hasMorePages;

  static VerifiedLearningPage parse(String raw, int requestedPage) {
    if (raw.length > 2 * 1024 * 1024 ||
        requestedPage < 1 || requestedPage > 10000) {
      throw const FormatException('Invalid learning catalog response');
    }
    final json = jsonDecode(raw);
    if (json is! Map<String, dynamic> ||
        json['meta'] is! Map<String, dynamic> ||
        json['data'] is! List) {
      throw const FormatException('Invalid learning catalog response');
    }
    final meta = json['meta'] as Map<String, dynamic>;
    final more = meta['has_more_pages'];
    final records = json['data'] as List<dynamic>;
    if (meta['current_page'] != requestedPage ||
        more is! bool || records.length > 20 ||
        (more && (records.isEmpty || requestedPage == 10000))) {
      throw const FormatException('Invalid learning catalog page');
    }
    final tracks = <VerifiedLearningTrack>[];
    final seen = <String>{};
    // Track IDs and slugs are both unique within a verified API page.
    // A repeated slug produces identical navigation widget keys even when
    // the server sends different UUIDs. Reject the entire page, not a subset.
    final seenSlugs = <String>{};
    final uuid = RegExp(
      r'^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
    );
    final slugPattern = RegExp(r'^[a-z0-9](?:[a-z0-9-]{0,148}[a-z0-9])?$');
    for (final record in records) {
      if (record is! Map<String, dynamic> ||
          record.keys.toSet().difference({'id', 'slug', 'title', 'outline'}).isNotEmpty ||
          record.length != 4 ||
          record['id'] is! String ||
          !uuid.hasMatch(record['id'] as String) ||
          !seen.add(record['id'] as String) ||
          record['slug'] is! String ||
          !slugPattern.hasMatch(record['slug'] as String) ||
          !seenSlugs.add(record['slug'] as String) ||
          record['title'] is! String ||
          (record['title'] as String).trim().isEmpty ||
          (record['title'] as String).length > 220 ||
          record['outline'] is! List) {
        throw const FormatException('Unverified learning track');
      }
      final outline = record['outline'] as List<dynamic>;
      if (outline.isEmpty || outline.length > 40) {
        throw const FormatException('Invalid learning outline');
      }
      final lessons = <VerifiedLesson>[];
      for (final item in outline) {
        if (item is! Map<String, dynamic> ||
            item.length != 2 ||
            item.keys.toSet().difference({'title', 'description'}).isNotEmpty ||
            item['title'] is! String ||
            (item['title'] as String).trim().isEmpty ||
            (item['title'] as String).length > 220 ||
            item['description'] is! String ||
            (item['description'] as String).length > 1500) {
          throw const FormatException('Unverified learning lesson');
        }
        lessons.add(VerifiedLesson(
          title: item['title'] as String,
          description: item['description'] as String,
        ));
      }
      tracks.add(VerifiedLearningTrack(
        id: record['id'] as String,
        slug: record['slug'] as String,
        title: record['title'] as String,
        lessons: List.unmodifiable(lessons),
      ));
    }
    return VerifiedLearningPage(
      tracks: List.unmodifiable(tracks),
      currentPage: requestedPage,
      hasMorePages: more,
    );
  }
}

class VerifiedLearningReader {
  const VerifiedLearningReader();

  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');

  /// Reuse the existing mobile origin guard, with an additional denylist for
  /// intranet-only DNS suffixes. Fail before creating a network client.
  static Uri? validatedOrigin(String value) {
    final origin = VerifiedArticleReader.validatedOrigin(value);
    if (origin == null) return null;
    final host = origin.host.toLowerCase();
    const privateSuffixes = [
      'lan', 'svc', 'home', 'arpa', 'localdomain', 'localhost',
      'local', 'internal', 'invalid', 'test', 'onion',
    ];
    if (privateSuffixes.any(
      (suffix) => host == suffix || host.endsWith('.' + suffix),
    )) {
      return null;
    }
    return origin;
  }

  static Uri? configuredOrigin() => validatedOrigin(apiOrigin);

  Future<VerifiedLearningPage> loadPage(bool arabic, int page) async {
    if (page < 1 || page > 10000) {
      throw const FormatException('Invalid learning page');
    }
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Learning catalog is not configured');
    }
    final uri = origin.replace(
      path: '/api/v1/mobile/learn',
      queryParameters: {'lang': arabic ? 'ar' : 'en', 'page': '$page'},
    );
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(uri).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Learning catalog unavailable');
      }
      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > 2 * 1024 * 1024) {
          throw const FormatException('Learning catalog exceeds size limit');
        }
        buffer.add(chunk);
      }
      return VerifiedLearningPage.parse(
        utf8.decode(buffer.takeBytes(), allowMalformed: false), page,
      );
    } finally {
      client.close(force: true);
    }
  }
}

/// Real, bilingual navigation and lesson details; never show fake progress.
class VerifiedLearningLibrary extends StatefulWidget {
  const VerifiedLearningLibrary({required this.arabic, this.pageLoader, super.key});

  final bool arabic;
  final Future<VerifiedLearningPage> Function(int page)? pageLoader;

  @override
  State<VerifiedLearningLibrary> createState() => _VerifiedLearningLibraryState();
}

class _VerifiedLearningLibraryState extends State<VerifiedLearningLibrary> {
  late Future<VerifiedLearningPage> _firstPage;
  List<VerifiedLearningTrack> _additional = const [];
  int _nextPage = 2;
  int _generation = 0;
  bool _loadingMore = false;
  bool _more = false;
  String? _moreError;

  bool get _configured =>
      widget.pageLoader != null || VerifiedLearningReader.configuredOrigin() != null;

  Future<VerifiedLearningPage> _fetch(int page) =>
      widget.pageLoader?.call(page) ??
      const VerifiedLearningReader().loadPage(widget.arabic, page);

  @override
  void initState() {
    super.initState();
    _reset();
  }

  @override
  void didUpdateWidget(covariant VerifiedLearningLibrary oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.arabic != oldWidget.arabic ||
        widget.pageLoader != oldWidget.pageLoader) {
      _reset();
    }
  }

  void _reset() {
    _generation++;
    _additional = const [];
    _nextPage = 2;
    _more = false;
    _loadingMore = false;
    _moreError = null;
    _firstPage = _configured ? _fetch(1) : Future.value(
      const VerifiedLearningPage(tracks: [], currentPage: 1, hasMorePages: false),
    );
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_more || _nextPage > 10000) return;
    final token = _generation;
    final page = _nextPage;
    setState(() {
      _loadingMore = true;
      _moreError = null;
    });
    try {
      final response = await _fetch(page);
      if (!mounted || token != _generation) return;
      if (response.currentPage != page ||
          (response.hasMorePages && response.tracks.isEmpty)) {
        throw const FormatException('Mismatched learning page');
      }
      // The first published page also belongs to the already-visible set.
      // A replayed ID or slug must never create a second card, partially
      // append untrusted data, or move the pagination cursor forward.
      final first = await _firstPage;
      if (!mounted || token != _generation) return;
      final seenIds = <String>{
        for (final track in first.tracks) track.id,
        for (final track in _additional) track.id,
      };
      final seenSlugs = <String>{
        for (final track in first.tracks) track.slug,
        for (final track in _additional) track.slug,
      };
      for (final track in response.tracks) {
        if (!seenIds.add(track.id) || !seenSlugs.add(track.slug)) {
          throw const FormatException('Repeated learning track');
        }
      }
      setState(() {
        _additional = [..._additional, ...response.tracks];
        _nextPage++;
        _more = response.hasMorePages;
      });
    } catch (_) {
      if (!mounted || token != _generation) return;
      setState(() => _moreError = widget.arabic
          ? 'تعذر تحميل المزيد. حاول مرة أخرى.'
          : 'Could not load more. Try again.');
    } finally {
      if (mounted && token == _generation) {
        setState(() => _loadingMore = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Scaffold(
      appBar: AppBar(title: Text(ar ? 'مسارات التعلّم' : 'Learning paths')),
      body: SafeArea(
        child: !_configured
            ? Center(child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  ar ? 'مكتبة التعلّم غير متصلة بخادم موثوق بعد.'
                      : 'Learning catalog is not connected to a trusted server.',
                  key: const Key('learning-unconfigured'),
                  textAlign: TextAlign.center,
                ),
              ))
            : FutureBuilder<VerifiedLearningPage>(
                future: _firstPage,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator(
                      key: Key('learning-loading'),
                    ));
                  }
                  if (snapshot.hasError) {
                    return Center(child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(ar ? 'تعذر تحميل المسارات.' : 'Could not load learning paths.'),
                        const SizedBox(height: 12),
                        FilledButton(
                          key: const Key('learning-retry'),
                          onPressed: () => setState(_reset),
                          child: Text(ar ? 'إعادة المحاولة' : 'Retry'),
                        ),
                      ],
                    ));
                  }
                  final first = snapshot.data!;
                  final tracks = [...first.tracks, ..._additional];
                  _more = _additional.isEmpty && _nextPage == 2
                      ? first.hasMorePages
                      : _more;
                  if (tracks.isEmpty) {
                    return Center(child: Text(
                      ar ? 'لا توجد مسارات تعلّم منشورة حاليًا.'
                          : 'No published learning paths yet.',
                      key: const Key('learning-empty'),
                    ));
                  }
                  return ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Text(
                        ar ? 'محتوى منشور للمطالعة، دون ادعاء تقدّم أو شهادة.'
                            : 'Published lessons for reading, without claimed progress or certificates.',
                      ),
                      const SizedBox(height: 12),
                      for (final track in tracks)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Card(child: ListTile(
                            key: Key('learning-track-' + track.slug),
                            leading: const Icon(Icons.school_outlined),
                            title: Text(track.title),
                            subtitle: Text(ar
                                ? '${track.lessons.length} دروس'
                                : '${track.lessons.length} lessons'),
                            trailing: Icon(ar
                                ? Icons.chevron_left : Icons.chevron_right),
                            onTap: () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => VerifiedLearningDetails(
                                  arabic: ar, track: track,
                                ),
                              ),
                            ),
                          )),
                        ),
                      if (_moreError != null)
                        Text(_moreError!, key: const Key('learning-more-error')),
                      if (_more)
                        FilledButton(
                          key: const Key('learning-more'),
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

class VerifiedLearningDetails extends StatelessWidget {
  const VerifiedLearningDetails({
    required this.arabic,
    required this.track,
    super.key,
  });

  final bool arabic;
  final VerifiedLearningTrack track;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(track.title)),
      body: SafeArea(child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            arabic ? 'الدروس المنشورة' : 'Published lessons',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 12),
          for (final lesson in track.lessons)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Card(child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(lesson.title,
                        style: Theme.of(context).textTheme.titleMedium),
                    if (lesson.description.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(lesson.description),
                    ],
                  ],
                ),
              )),
            ),
        ],
      )),
    );
  }
}

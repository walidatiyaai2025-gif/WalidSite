import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';

import '../articles/verified_articles.dart';
import 'offline_guide_text_search.dart';
import 'reviewed_runbook_actions.dart';

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
    super.key,
  });

  final String id;
  final bool arabic;
  final Future<ReviewedRunbook> Function(String id)? loader;
  final String? initialBearer;
  final ReviewedRunbookLoginAction? loginAction;
  final ReviewedRunbookSaveAction? saveAction;
  final ReviewedRunbookShareAction? shareAction;

  @override
  State<ReviewedRunbookDetail> createState() => _ReviewedRunbookDetailState();
}

class _ReviewedRunbookDetailState extends State<ReviewedRunbookDetail> {
  late Future<ReviewedRunbook> _future;
  String? _bearer;
  bool _saving = false;
  bool _sharing = false;
  bool _saved = false;
  String? _actionMessage;

  @override
  void initState() {
    super.initState();
    final token = widget.initialBearer;
    _bearer = token != null && token.isNotEmpty && token.length <= 8192
        ? token
        : null;
    _refresh();
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

    var bearer = _bearer;
    if (bearer == null) {
      bearer = await _promptForBearer();
      if (!mounted || bearer == null) return;
      _bearer = bearer;
    }

    setState(() {
      _saving = true;
      _actionMessage = null;
    });
    try {
      await _save(bearer, item.id);
      if (!mounted) return;
      setState(() {
        _saved = true;
        // Do not retain a bearer after its only required action.
        _bearer = null;
        _actionMessage = widget.arabic
            ? 'تم حفظ الدليل المراجع في حسابك.'
            : 'The reviewed guide was saved to your account.';
      });
    } catch (error) {
      if (!mounted) return;
      if (error is RunbookActionHttpException &&
          (error.statusCode == 401 || error.statusCode == 403)) {
        _bearer = null;
      }
      setState(() => _actionMessage =
          _actionFailure(error, login: false));
    } finally {
      if (mounted) setState(() => _saving = false);
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
      if (!mounted) return;
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
      if (!mounted) return;
      setState(() {
        _actionMessage = widget.arabic
            ? 'تعذر فتح المشاركة بأمان.'
            : 'Could not open sharing safely.';
      });
    } finally {
      if (mounted) setState(() => _sharing = false);
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
              return ListView(
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
                      await Clipboard.setData(
                        ClipboardData(text: item.markdown),
                      );
                      if (!mounted) return;
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

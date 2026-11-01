import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../ai/ai_troubleshooter.dart';
import '../articles/verified_articles.dart';
import '../runbooks/verified_online_runbooks.dart';

final _uuid = RegExp(
  r'^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
);
final _articlePath = RegExp(r'^/api/v1/public/articles/([A-Za-z0-9-]{1,180})$');
final _runbookPath = RegExp(
  r'^/api/v1/mobile/solutions/([a-fA-F0-9-]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})$',
);

/// Account identity is from the authenticated /mobile/me endpoint only.
class MobileAccount {
  const MobileAccount(this.id, this.email);
  final String id;
  final String email;

  static MobileAccount parse(String raw) {
    if (raw.length > 16384) throw const FormatException('Account too large');
    final json = jsonDecode(raw);
    if (json is! Map<String, dynamic> ||
        json.length != 1 || json['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid account response');
    }
    final data = json['data'] as Map<String, dynamic>;
    final id = data['id'];
    final email = data['email'];
    if (data.length != 2 || id is! String || !_uuid.hasMatch(id) ||
        email is! String || email.isEmpty || email.length > 254 ||
        !email.contains('@')) {
      throw const FormatException('Invalid account fields');
    }
    return MobileAccount(id, email);
  }
}

class MobileSavedResource {
  const MobileSavedResource({
    required this.type,
    required this.id,
    required this.title,
    required this.detailPath,
    required this.savedAt,
  });

  final String type;
  final String id;
  final String title;
  final String detailPath;
  final DateTime savedAt;

  String? get articleSlug => _articlePath.firstMatch(detailPath)?.group(1);

  static MobileSavedResource parse(Object? input) {
    if (input is! Map<String, dynamic> || input.length != 5 ||
        input.keys.toSet().difference({
          'resource_type', 'resource_id', 'title', 'detail_url', 'saved_at',
        }).isNotEmpty) {
      throw const FormatException('Invalid saved item');
    }
    final type = input['resource_type'];
    final id = input['resource_id'];
    final title = input['title'];
    final path = input['detail_url'];
    final savedRaw = input['saved_at'];
    final savedAt = savedRaw is String ? DateTime.tryParse(savedRaw) : null;
    if ((type != 'article' && type != 'runbook') ||
        id is! String || !_uuid.hasMatch(id) ||
        title is! String || title.trim().isEmpty || title.length > 250 ||
        path is! String || path.length > 256 ||
        savedAt == null || savedRaw is! String || savedRaw.length > 60 ||
        !RegExp(r'(?:Z|[+-][0-9]{2}:[0-9]{2})$').hasMatch(savedRaw) ||
        (type == 'article' && !_articlePath.hasMatch(path)) ||
        (type == 'runbook' &&
            _runbookPath.firstMatch(path)?.group(1)?.toLowerCase() !=
                id.toLowerCase())) {
      throw const FormatException('Invalid saved resource data');
    }
    return MobileSavedResource(
      type: type as String, id: id, title: title,
      detailPath: path, savedAt: savedAt.toUtc(),
    );
  }
}

class MobileSavedPage {
  const MobileSavedPage(this.items, this.page, this.hasMore);
  final List<MobileSavedResource> items;
  final int page;
  final bool hasMore;

  static MobileSavedPage parse(String raw, int requestedPage) {
    if (raw.length > 512 * 1024 || requestedPage < 1 || requestedPage > 10000) {
      throw const FormatException('Saved catalog limit exceeded');
    }
    final json = jsonDecode(raw);
    if (json is! Map<String, dynamic> || json.length != 2 ||
        json['data'] is! List || json['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid saved page');
    }
    final meta = json['meta'] as Map<String, dynamic>;
    final rows = json['data'] as List<dynamic>;
    if (meta['current_page'] != requestedPage ||
        meta['has_more_pages'] is! bool || rows.length > 20 ||
        (meta['has_more_pages'] == true && (rows.isEmpty || requestedPage == 10000))) {
      throw const FormatException('Invalid saved pagination');
    }
    final seen = <String>{};
    final items = <MobileSavedResource>[];
    for (final rawItem in rows) {
      final item = MobileSavedResource.parse(rawItem);
      if (!seen.add('${item.type}:${item.id.toLowerCase()}')) {
        throw const FormatException('Duplicate saved resource');
      }
      items.add(item);
    }
    return MobileSavedPage(
      List.unmodifiable(items), requestedPage, meta['has_more_pages'] as bool,
    );
  }
}

typedef AccountLogin = Future<MobileAiSession> Function(String, String);
typedef AccountReadProfile = Future<MobileAccount> Function(String);
typedef AccountReadSaved = Future<MobileSavedPage> Function(String, bool, int);
typedef AccountRemoveSaved = Future<void> Function(String, MobileSavedResource);
typedef AccountRevoke = Future<void> Function(String);
typedef AccountReadArticle = Future<VerifiedArticle> Function(
  MobileSavedResource, bool,
);

/// Uses only an explicitly configured HTTPS origin and a short-lived bearer.
/// Tokens are held in memory by the screen and never written to disk.
class MobileAccountClient {
  const MobileAccountClient();

  static Uri? configuredOrigin() =>
      VerifiedArticleReader.validatedOrigin(MobileAiClient.apiOrigin);

  Future<String> _request(String method, String path, String bearer,
      {Map<String, String>? query}) async {
    final origin = configuredOrigin();
    if (origin == null || bearer.isEmpty || bearer.length > 8192 ||
        !path.startsWith('/api/v1/') || path.contains('?')) {
      throw const FormatException('Invalid account request');
    }
    final uri = origin.replace(path: path, queryParameters: query);
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.openUrl(method, uri)
          .timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $bearer');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw MobileAiHttpException(response.statusCode);
      }
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > 512 * 1024) {
          throw const FormatException('Account response too large');
        }
        bytes.add(chunk);
      }
      return utf8.decode(bytes.takeBytes(), allowMalformed: false);
    } finally {
      client.close(force: true);
    }
  }

  Future<MobileAccount> profile(String bearer) async =>
      MobileAccount.parse(await _request('GET', '/api/v1/mobile/me', bearer));

  Future<MobileSavedPage> saved(String bearer, bool arabic, int page) async {
    if (page < 1 || page > 10000) throw const FormatException('Invalid saved page');
    return MobileSavedPage.parse(await _request(
      'GET', '/api/v1/mobile/saved', bearer,
      query: {'lang': arabic ? 'ar' : 'en', 'page': '$page'},
    ), page);
  }

  Future<void> remove(String bearer, MobileSavedResource item) async {
    if (!_uuid.hasMatch(item.id) ||
        (item.type != 'article' && item.type != 'runbook')) {
      throw const FormatException('Invalid removal');
    }
    final result = jsonDecode(await _request(
      'DELETE', '/api/v1/mobile/saved/${item.type}/${item.id}', bearer,
    ));
    if (result is! Map<String, dynamic> ||
        result['data'] is! Map<String, dynamic> ||
        (result['data'] as Map<String, dynamic>)['removed'] != true) {
      throw const FormatException('Saved resource removal not confirmed');
    }
  }

  Future<void> logout(String bearer) async {
    final response = jsonDecode(await _request('POST', '/api/v1/auth/logout', bearer));
    if (response is! Map<String, dynamic> ||
        response['data'] is! Map<String, dynamic> ||
        (response['data'] as Map<String, dynamic>)['revoked'] != true) {
      throw const FormatException('Logout not confirmed');
    }
  }

  Future<VerifiedArticle> article(MobileSavedResource item, bool arabic) async {
    if (item.type != 'article' || item.articleSlug == null) {
      throw const FormatException('Invalid saved article');
    }
    final response = jsonDecode(await _request(
      'GET', item.detailPath, 'public-read',
      query: {'lang': arabic ? 'ar' : 'en'},
    ));
    if (response is! Map<String, dynamic> ||
        response['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid article detail');
    }
    final data = response['data'] as Map<String, dynamic>;
    if (data['id'] != item.id || data['slug'] != item.articleSlug) {
      throw const FormatException('Mismatched saved article');
    }
    final parsed = VerifiedArticle.parseList(jsonEncode({'data': [data]}));
    if (parsed.length != 1) throw const FormatException('Invalid saved article');
    return parsed.single;
  }
}

/// MOBILE-11 + MOBILE-12: one authenticated flow, never two local logins.
class MobileAccountJourney extends StatefulWidget {
  const MobileAccountJourney({
    required this.arabic,
    this.loginAction,
    this.profileAction,
    this.savedAction,
    this.removeAction,
    this.logoutAction,
    this.articleAction,
    super.key,
  });

  final bool arabic;
  final AccountLogin? loginAction;
  final AccountReadProfile? profileAction;
  final AccountReadSaved? savedAction;
  final AccountRemoveSaved? removeAction;
  final AccountRevoke? logoutAction;
  final AccountReadArticle? articleAction;

  @override
  State<MobileAccountJourney> createState() => _MobileAccountJourneyState();
}

class _MobileAccountJourneyState extends State<MobileAccountJourney> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  MobileAiSession? _session;
  MobileAccount? _profile;
  List<MobileSavedResource> _saved = const [];
  bool _hasMore = false;
  int _page = 1;
  int _epoch = 0;
  bool _busy = false;
  bool _loadingMore = false;
  bool _showProfile = false;
  String? _message;

  bool get _configured => widget.loginAction != null ||
      MobileAccountClient.configuredOrigin() != null;
  AccountLogin get _login => widget.loginAction ??
      const MobileAiClient().login;
  AccountReadProfile get _readProfile => widget.profileAction ??
      const MobileAccountClient().profile;
  AccountReadSaved get _readSaved => widget.savedAction ??
      const MobileAccountClient().saved;
  AccountRemoveSaved get _remove => widget.removeAction ??
      const MobileAccountClient().remove;
  AccountRevoke get _revoke => widget.logoutAction ??
      const MobileAccountClient().logout;
  AccountReadArticle get _article => widget.articleAction ??
      const MobileAccountClient().article;

  String _label(String en, String ar) => widget.arabic ? ar : en;
  bool get _validSession => _session != null &&
      (_session!.expiresAt == null ||
          _session!.expiresAt!.isAfter(DateTime.now().toUtc()));
  void _clearSession() {
    _epoch++;
    _session = null;
    _profile = null;
    _saved = const [];
    _page = 1;
    _hasMore = false;
    _password.clear();
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _signIn() async {
    if (_busy) return;
    setState(() { _busy = true; _message = null; });
    final token = ++_epoch;
    try {
      final session = await _login(_email.text.trim(), _password.text);
      if (!mounted || token != _epoch) return;
      if (session.token.isEmpty || session.token.length > 8192 ||
          (session.expiresAt != null &&
              !session.expiresAt!.isAfter(DateTime.now().toUtc()))) {
        throw const FormatException('Expired account session');
      }
      final profile = await _readProfile(session.token);
      final saved = await _readSaved(session.token, widget.arabic, 1);
      if (!mounted || token != _epoch) return;
      setState(() {
        _session = session;
        _profile = profile;
        _saved = saved.items;
        _page = 1;
        _hasMore = saved.hasMore;
        _password.clear();
      });
    } catch (_) {
      if (!mounted || token != _epoch) return;
      setState(() {
        _clearSession();
        _message = _label('Could not sign in or load your account. Check the connection.',
            'تعذر تسجيل الدخول أو تحميل الحساب. تحقق من الاتصال.');
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool _requireSession() {
    if (_validSession) return true;
    setState(() {
      _clearSession();
      _message = _label('Session expired. Sign in again.',
          'انتهت الجلسة. سجل الدخول مجددًا.');
    });
    return false;
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || !_requireSession()) return;
    final token = _epoch;
    setState(() { _loadingMore = true; _message = null; });
    try {
      final next = await _readSaved(_session!.token, widget.arabic, _page + 1);
      if (!mounted || token != _epoch) return;
      final seen = _saved.map((item) => '${item.type}:${item.id}').toSet();
      if (next.items.any((item) => !seen.add('${item.type}:${item.id}'))) {
        throw const FormatException('Repeated saved item');
      }
      setState(() {
        _saved = [..._saved, ...next.items];
        _page = next.page;
        _hasMore = next.hasMore;
      });
    } catch (error) {
      if (!mounted || token != _epoch) return;
      setState(() {
        if (error is MobileAiHttpException &&
            (error.statusCode == 401 || error.statusCode == 403)) {
          _clearSession();
        }
        _message = _label('Could not load more saved items.',
            'تعذر تحميل المزيد من المحفوظات.');
      });
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  Future<void> _delete(MobileSavedResource item) async {
    if (_busy || !_requireSession()) return;
    final token = _epoch;
    setState(() { _busy = true; _message = null; });
    try {
      await _remove(_session!.token, item);
      final first = await _readSaved(_session!.token, widget.arabic, 1);
      if (!mounted || token != _epoch) return;
      setState(() {
        _saved = first.items;
        _page = 1;
        _hasMore = first.hasMore;
        _message = _label('Removed from saved resources.',
            'تمت الإزالة من المحفوظات.');
      });
    } catch (error) {
      if (!mounted || token != _epoch) return;
      setState(() {
        if (error is MobileAiHttpException &&
            (error.statusCode == 401 || error.statusCode == 403)) {
          _clearSession();
        }
        _message = _label('Could not confirm removal. Try again.',
            'تعذر تأكيد الإزالة. حاول مجددًا.');
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _open(MobileSavedResource item) {
    if (!_requireSession()) return;
    final session = _session!;
    if (item.type == 'runbook') {
      Navigator.of(context).push(MaterialPageRoute<void>(
        builder: (_) => ReviewedRunbookDetail(
          id: item.id, arabic: widget.arabic,
          initialBearer: session.token,
        ),
      ));
      return;
    }
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => _SavedArticlePage(
        item: item, arabic: widget.arabic, load: _article,
      ),
    ));
  }

  Future<void> _signOut() async {
    if (_busy || !_requireSession()) return;
    final bearer = _session!.token;
    setState(() { _busy = true; _message = null; });
    var revoked = false;
    try {
      await _revoke(bearer);
      revoked = true;
    } catch (_) {
      // Never claim server token revocation on a network failure.
    } finally {
      if (mounted) {
        setState(() {
          _clearSession();
          _busy = false;
          _message = revoked
              ? _label('Signed out of this device.', 'تم تسجيل الخروج من الجهاز.')
              : _label('Local session cleared; server sign-out unconfirmed.',
                  'تم مسح الجلسة محليًا؛ لم يتم تأكيد إلغائها على الخادم.');
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    return Scaffold(
      appBar: AppBar(title: Text(_label('Saved & profile', 'المحفوظات والحساب'))),
      body: SafeArea(child: !_configured
          ? Center(child: Padding(
              padding: const EdgeInsets.all(20),
              child: Text(_label('Account services are not configured.',
                  'خدمات الحساب غير مهيأة.'), key: const Key('account-unconfigured')),
            ))
          : ListView(
              padding: const EdgeInsets.all(20),
              children: [
                if (_message != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(_message!, key: const Key('account-message')),
                  ),
                if (!_validSession || _profile == null) ...[
                  Text(_label('Sign in to access your own saved resources.',
                      'سجل الدخول لعرض محفوظات حسابك فقط.')),
                  const SizedBox(height: 16),
                  TextField(
                    key: const Key('account-email'),
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.email],
                    decoration: InputDecoration(labelText: _label('Email', 'البريد الإلكتروني')),
                  ),
                  TextField(
                    key: const Key('account-password'),
                    controller: _password,
                    obscureText: true,
                    autocorrect: false,
                    enableSuggestions: false,
                    decoration: InputDecoration(labelText: _label('Password', 'كلمة المرور')),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    key: const Key('account-login'),
                    onPressed: _busy ? null : _signIn,
                    child: Text(_busy
                        ? _label('Signing in...', 'جارٍ تسجيل الدخول...')
                        : _label('Sign in', 'تسجيل الدخول')),
                  ),
                  Text(_label('Credentials and session are not stored on this device.',
                      'لا تُحفظ بيانات الدخول أو الجلسة على هذا الجهاز.')),
                ] else ...[
                  Row(children: [
                    Expanded(child: OutlinedButton(
                      key: const Key('account-saved-tab'),
                      onPressed: () => setState(() => _showProfile = false),
                      child: Text(_label('Saved resources', 'المحفوظات')),
                    )),
                    const SizedBox(width: 8),
                    Expanded(child: OutlinedButton(
                      key: const Key('account-profile-tab'),
                      onPressed: () => setState(() => _showProfile = true),
                      child: Text(_label('My profile', 'ملفي الشخصي')),
                    )),
                  ]),
                  const SizedBox(height: 16),
                  if (_showProfile) ...[
                    Text(_profile!.email, key: const Key('account-profile-email')),
                    const SizedBox(height: 12),
                    Text(_label('Profile changes are not supported by this API yet.',
                        'تعديل الملف الشخصي غير متاح عبر واجهة API الحالية.')),
                    const SizedBox(height: 16),
                    FilledButton(
                      key: const Key('account-signout'),
                      onPressed: _busy ? null : _signOut,
                      child: Text(_label('Sign out', 'تسجيل الخروج')),
                    ),
                  ] else ...[
                    if (_saved.isEmpty)
                      Text(_label('No eligible saved resources.',
                          'لا توجد محفوظات منشورة متاحة.'),
                          key: const Key('account-saved-empty')),
                    for (final item in _saved)
                      Card(child: Column(children: [
                        ListTile(
                          title: Text(item.title),
                          subtitle: Text(item.type == 'article'
                              ? _label('Verified article', 'مقال موثق')
                              : _label('Reviewed runbook', 'دليل مراجَع')),
                        ),
                        Row(children: [
                          TextButton(
                            key: Key('account-open-${item.id}'),
                            onPressed: _busy ? null : () => _open(item),
                            child: Text(_label('Open', 'فتح')),
                          ),
                          TextButton(
                            key: Key('account-remove-${item.id}'),
                            onPressed: _busy ? null : () => _delete(item),
                            child: Text(_label('Remove', 'إزالة')),
                          ),
                        ]),
                      ])),
                    if (_hasMore)
                      FilledButton(
                        key: const Key('account-load-more'),
                        onPressed: _loadingMore || _busy ? null : _loadMore,
                        child: Text(_loadingMore
                            ? _label('Loading...', 'جارٍ التحميل...')
                            : _label('Load more', 'عرض المزيد')),
                      ),
                  ],
                ],
              ],
            )),
    );
  }
}

class _SavedArticlePage extends StatefulWidget {
  const _SavedArticlePage({
    required this.item, required this.arabic, required this.load,
  });
  final MobileSavedResource item;
  final bool arabic;
  final AccountReadArticle load;

  @override
  State<_SavedArticlePage> createState() => _SavedArticlePageState();
}

class _SavedArticlePageState extends State<_SavedArticlePage> {
  late Future<VerifiedArticle> _future;
  @override
  void initState() {
    super.initState();
    _future = widget.load(widget.item, widget.arabic);
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<VerifiedArticle>(
    future: _future,
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) {
        return const Scaffold(body: Center(child: CircularProgressIndicator()));
      }
      if (snapshot.hasError) {
        return Scaffold(
          appBar: AppBar(title: Text(widget.item.title)),
          body: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(widget.arabic ? 'تعذر فتح المقال الموثق.' :
                'Could not open the verified article.'),
            FilledButton(
              key: const Key('account-article-retry'),
              onPressed: () => setState(
                () => _future = widget.load(widget.item, widget.arabic),
              ),
              child: Text(widget.arabic ? 'إعادة المحاولة' : 'Retry'),
            ),
          ])),
        );
      }
      return VerifiedArticleDetail(article: snapshot.data!, arabic: widget.arabic);
    },
  );
}

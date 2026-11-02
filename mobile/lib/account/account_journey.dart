import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../ai/ai_troubleshooter.dart';
import 'account_privacy.dart';
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
typedef AccountUpdateProfile = Future<MobileAccount> Function(
  String bearer,
  String email,
  String currentPassword,
);
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

  Future<String> _request(String method, String path, String? bearer,
      {Map<String, String>? query, Map<String, Object?>? body}) async {
    final origin = configuredOrigin();
    if (origin == null || (bearer == null &&
            !(method == 'GET' && _articlePath.hasMatch(path))) ||
        (bearer != null && (bearer.isEmpty || bearer.length > 8192)) ||
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
      if (bearer != null) {
        request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $bearer');
      }
      if (body != null) {
        request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
        request.add(utf8.encode(jsonEncode(body)));
      }
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

  Future<MobileAccount> updateProfile(
    String bearer,
    String email,
    String currentPassword,
  ) async {
    final safeEmail = email.trim();
    if (safeEmail.isEmpty ||
        safeEmail.length > 254 ||
        !safeEmail.contains('@') ||
        currentPassword.isEmpty ||
        currentPassword.length > 4096) {
      throw const FormatException('Invalid account profile update');
    }
    return MobileAccount.parse(await _request(
      'PATCH',
      '/api/v1/mobile/me',
      bearer,
      body: {
        'email': safeEmail,
        'current_password': currentPassword,
      },
    ));
  }

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
      'GET', item.detailPath, null,
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

/// MOBILE-11 + MOBILE-12 share one secure account implementation while
/// exposing independently identifiable canonical Saved and Profile surfaces.
enum MobileAccountSection { saved, profile }

class MobileAccountJourney extends StatefulWidget {
  const MobileAccountJourney({
    required this.arabic,
    this.initialSection = MobileAccountSection.saved,
    this.loginAction,
    this.profileAction,
    this.profileUpdateAction,
    this.savedAction,
    this.removeAction,
    this.logoutAction,
    this.articleAction,
    super.key,
  });

  final bool arabic;
  final MobileAccountSection initialSection;
  final AccountLogin? loginAction;
  final AccountReadProfile? profileAction;
  final AccountUpdateProfile? profileUpdateAction;
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
  final _profileEmail = TextEditingController();
  final _profilePassword = TextEditingController();
  MobileAiSession? _session;
  MobileAccount? _profile;
  bool _accountReady = false;
  List<MobileSavedResource> _saved = const [];
  bool _hasMore = false;
  int _page = 1;
  int _epoch = 0;
  bool _busy = false;
  bool _busyLogout = false;
  bool _loadingMore = false;
  late bool _showProfile;
  bool _editingProfile = false;
  String? _message;

  bool get _configured => widget.loginAction != null ||
      MobileAccountClient.configuredOrigin() != null;
  AccountLogin get _login => widget.loginAction ??
      const MobileAiClient().login;
  AccountReadProfile get _readProfile => widget.profileAction ??
      const MobileAccountClient().profile;
  AccountUpdateProfile get _updateProfile => widget.profileUpdateAction ??
      const MobileAccountClient().updateProfile;
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

  // A rejected bearer is not a transient catalog outage. Never trap the
  // user behind a retry/sign-out flow that keeps replaying a revoked token.
  bool _authorizationLost(Object error) => error is MobileAiHttpException &&
      (error.statusCode == 401 || error.statusCode == 403);

  void _clearSession() {
    _epoch++;
    _session = null;
    _profile = null;
    _accountReady = false;
    _saved = const [];
    _page = 1;
    _hasMore = false;
    _showProfile = widget.initialSection == MobileAccountSection.profile;
    _editingProfile = false;
    _email.clear();
    _password.clear();
    _profileEmail.clear();
    _profilePassword.clear();
  }

  @override
  void initState() {
    super.initState();
    _showProfile = widget.initialSection == MobileAccountSection.profile;
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _profileEmail.dispose();
    _profilePassword.dispose();
    super.dispose();
  }

  // The server token exists as soon as login succeeds, even when profile
  // or saved-resource reads fail. Retain it for a safe retry or real revocation
  // rather than minting another token after a transient catalog outage.
  Future<void> _loadAccountData(MobileAiSession session, int epoch) async {
    final profile = await _readProfile(session.token);
    final saved = await _readSaved(session.token, widget.arabic, 1);
    if (!mounted || epoch != _epoch || _session?.token != session.token) return;
    if (saved.page != 1) throw const FormatException('Unexpected saved page');
    setState(() {
      _profile = profile;
      _profileEmail.text = profile.email;
      _saved = saved.items;
      _page = 1;
      _hasMore = saved.hasMore;
      _accountReady = true;
    });
  }

  Future<void> _signIn() async {
    if (_busy || _session != null) return;
    setState(() { _busy = true; _message = null; });
    final epoch = ++_epoch;
    try {
      final session = await _login(_email.text.trim(), _password.text);
      if (!mounted || epoch != _epoch) return;
      if (session.token.isEmpty || session.token.length > 8192 ||
          (session.expiresAt != null &&
              !session.expiresAt!.isAfter(DateTime.now().toUtc()))) {
        throw const FormatException('Expired account session');
      }
      setState(() {
        _session = session;
        _accountReady = false;
        _profile = null;
        _saved = const [];
        _page = 1;
        _hasMore = false;
        _email.clear();
        _password.clear();
      });
      await _loadAccountData(session, epoch);
    } catch (error) {
      if (!mounted || epoch != _epoch) return;
      setState(() {
        if (_session != null && _authorizationLost(error)) {
          // Profile and saved data have not both been verified. The server
          // rejected the bearer; clear all local identity and allow re-login.
          _clearSession();
          _message = _label('Session no longer authorized. Sign in again.',
              'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.');
        } else if (_session != null) {
          _profile = null;
          _accountReady = false;
          _saved = const [];
          _page = 1;
          _hasMore = false;
          _message = _label(
              'Signed in, but account data could not load. Retry or sign out.',
              'تم تسجيل الدخول لكن تعذر تحميل بيانات الحساب. أعد المحاولة أو سجل الخروج.');
        } else {
          _clearSession();
          _message = _label(
              'Could not sign in. Check the connection.',
              'تعذر تسجيل الدخول. تحقق من الاتصال.');
        }
      });
    } finally {
      _password.clear();
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _retryAccount() async {
    if (_busy || _accountReady || _session == null || !_requireSession()) return;
    final epoch = ++_epoch;
    final session = _session!;
    setState(() { _busy = true; _message = null; });
    try {
      await _loadAccountData(session, epoch);
    } catch (error) {
      if (!mounted || epoch != _epoch) return;
      setState(() {
        if (_authorizationLost(error)) {
          _clearSession();
          _message = _label('Session no longer authorized. Sign in again.',
              'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.');
        } else {
          _profile = null;
          _accountReady = false;
          _saved = const [];
          _message = _label(
              'Account data is still unavailable. Retry or sign out.',
              'بيانات الحساب ما زالت غير متاحة. أعد المحاولة أو سجل الخروج.');
        }
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

  void _openPrivacy() {
    if (_busy || !_requireSession()) return;
    final bearer = _session!.token;
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => MobileAccountPrivacyScreen(
        arabic: widget.arabic,
        bearer: bearer,
        onDeleted: () {
          if (!mounted) return;
          setState(() {
            _clearSession();
            _message = _label(
              'Account deletion confirmed. Your local session was cleared.',
              'تم تأكيد حذف الحساب ومسح الجلسة المحلية.',
            );
          });
        },
        onUnauthorized: () {
          if (!mounted) return;
          setState(() {
            _clearSession();
            _message = _label('Session no longer authorized. Sign in again.',
                'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.');
          });
        },
      ),
    ));
  }

  void _beginProfileEdit() {
    if (_busy || !_requireSession() || _profile == null) return;
    setState(() {
      _editingProfile = true;
      _profileEmail.text = _profile!.email;
      _profilePassword.clear();
      _message = null;
    });
  }

  void _cancelProfileEdit() {
    if (_busy) return;
    setState(() {
      _editingProfile = false;
      _profileEmail.text = _profile?.email ?? '';
      _profilePassword.clear();
      _message = null;
    });
  }

  Future<void> _saveProfile() async {
    if (_busy || !_requireSession() || _profile == null) return;
    final safeEmail = _profileEmail.text.trim();
    final currentPassword = _profilePassword.text;
    if (safeEmail.isEmpty ||
        safeEmail.length > 254 ||
        !safeEmail.contains('@') ||
        currentPassword.isEmpty) {
      setState(() {
        _message = _label(
          'Enter a valid email and your current password.',
          'أدخل بريدًا إلكترونيًا صالحًا وكلمة المرور الحالية.',
        );
      });
      return;
    }

    final epoch = _epoch;
    final bearer = _session!.token;
    final accountId = _profile!.id;
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final updated = await _updateProfile(
        bearer,
        safeEmail,
        currentPassword,
      );
      if (!mounted || epoch != _epoch || _session?.token != bearer) return;
      if (updated.id.toLowerCase() != accountId.toLowerCase()) {
        throw const FormatException('Mismatched account identity');
      }
      setState(() {
        _profile = updated;
        _profileEmail.text = updated.email;
        _email.text = updated.email;
        _profilePassword.clear();
        _editingProfile = false;
        _message = _label(
          'Account email updated. Other bearer sessions were revoked.',
          'تم تحديث بريد الحساب وإلغاء جلسات الأجهزة الأخرى.',
        );
      });
    } catch (error) {
      if (!mounted || epoch != _epoch || _session?.token != bearer) return;
      setState(() {
        _profilePassword.clear();
        if (error is MobileAiHttpException &&
            (error.statusCode == 401 || error.statusCode == 403)) {
          _clearSession();
          _message = _label(
            'Session no longer authorized. Sign in again.',
            'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.',
          );
        } else if (error is MobileAiHttpException && error.statusCode == 422) {
          _message = _label(
            'Check the email address and current password.',
            'تحقق من البريد الإلكتروني وكلمة المرور الحالية.',
          );
        } else {
          _message = _label(
            'Could not confirm the account update. Try again.',
            'تعذر تأكيد تحديث الحساب. حاول مجددًا.',
          );
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _signOut() async {
    if (_busy || !_requireSession()) return;
    final bearer = _session!.token;
    // Scrub the complete account view BEFORE waiting on the network. A slow
    // or failed logout cannot keep private saved cards or identity visible.
    setState(() {
      _clearSession();
      _busy = true;
      _busyLogout = true;
      _message = _label(
        'Local session cleared. Confirming server sign-out...',
        'تم مسح الجلسة محليًا. جارٍ تأكيد تسجيل الخروج على الخادم...',
      );
    });
    var revoked = false;
    try {
      await _revoke(bearer);
      revoked = true;
    } catch (_) {
      // Never claim server token revocation on a network failure.
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _busyLogout = false;
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
    return Semantics(
      identifier: _validSession && _accountReady && _profile != null
          ? (_showProfile ? 'walidsite.mobile.12' : 'walidsite.mobile.11')
          : 'walidsite.mobile.account.locked',
      child: Scaffold(
        appBar: AppBar(title: Text(_showProfile
          ? _label('Personal profile', 'الملف الشخصي')
          : _label('Saved resources', 'المحفوظات'))),
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
                if (!_validSession || _session == null) ...[
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
                        ? (_busyLogout
                            ? _label('Finishing sign-out...', 'جارٍ إكمال تسجيل الخروج...')
                            : _label('Signing in...', 'جارٍ تسجيل الدخول...'))
                        : _label('Sign in', 'تسجيل الدخول')),
                  ),
                  Text(_label('Credentials and session are not stored on this device.',
                      'لا تُحفظ بيانات الدخول أو الجلسة على هذا الجهاز.')),
                ] else if (!_accountReady || _profile == null) ...[
                  Text(_label(
                      'Signed in. Personal data is unavailable until both '
                      'your profile and saved items load successfully.',
                      'تم تسجيل الدخول. لن تُعرض بياناتك حتى يتم تحميل '
                      'الملف الشخصي والمحفوظات بنجاح.'),
                    key: const Key('account-load-unavailable')),
                  if (_busy)
                    const LinearProgressIndicator(key: Key('account-load-pending')),
                  FilledButton(
                    key: const Key('account-retry-data'),
                    onPressed: _busy ? null : _retryAccount,
                    child: Text(_label('Retry account data', 'إعادة تحميل بيانات الحساب')),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton(
                    key: const Key('account-signout'),
                    onPressed: _busy ? null : _signOut,
                    child: Text(_label('Sign out and revoke session',
                        'تسجيل الخروج وإلغاء الجلسة')),
                  ),
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
                    Text(_label(
                      'Edit only your private account email here. Public portfolio content is managed separately.',
                      'يمكن تعديل بريد الحساب الخاص فقط هنا. محتوى الملف العام يُدار بشكل منفصل.',
                    )),
                    const SizedBox(height: 12),
                    if (!_editingProfile)
                      OutlinedButton(
                        key: const Key('account-edit-profile'),
                        onPressed: _busy ? null : _beginProfileEdit,
                        child: Text(_label('Edit account email', 'تعديل بريد الحساب')),
                      )
                    else ...[
                      TextField(
                        key: const Key('account-edit-email'),
                        controller: _profileEmail,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.email],
                        decoration: InputDecoration(
                          labelText: _label('New email', 'البريد الإلكتروني الجديد'),
                        ),
                      ),
                      const SizedBox(height: 8),
                      TextField(
                        key: const Key('account-edit-password'),
                        controller: _profilePassword,
                        obscureText: true,
                        autocorrect: false,
                        enableSuggestions: false,
                        autofillHints: const [AutofillHints.password],
                        decoration: InputDecoration(
                          labelText: _label('Current password', 'كلمة المرور الحالية'),
                        ),
                      ),
                      const SizedBox(height: 8),
                      Row(children: [
                        Expanded(child: FilledButton(
                          key: const Key('account-edit-save'),
                          onPressed: _busy ? null : _saveProfile,
                          child: Text(_label('Save email', 'حفظ البريد')),
                        )),
                        const SizedBox(width: 8),
                        Expanded(child: OutlinedButton(
                          key: const Key('account-edit-cancel'),
                          onPressed: _busy ? null : _cancelProfileEdit,
                          child: Text(_label('Cancel', 'إلغاء')),
                        )),
                      ]),
                      const SizedBox(height: 8),
                      Text(_label(
                        'Saving requires your current password and revokes other bearer sessions.',
                        'الحفظ يتطلب كلمة المرور الحالية ويلغي جلسات الأجهزة الأخرى.',
                      )),
                    ],
                    const SizedBox(height: 12),
                    OutlinedButton(
                      key: const Key('account-privacy-entry'),
                      onPressed: _busy ? null : _openPrivacy,
                      child: Text(_label('Privacy, consent and my data',
                          'الخصوصية والموافقات وبياناتي')),
                    ),
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
      ),
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

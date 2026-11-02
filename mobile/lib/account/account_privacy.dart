import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../ai/ai_troubleshooter.dart';
import '../articles/verified_articles.dart';

class AccountConsent {
  const AccountConsent(this.purpose, this.channel, this.enabled);
  final String purpose;
  final String channel;
  final bool enabled;
}

class AccountConsentMatrix {
  const AccountConsentMatrix(this.entries);
  final List<AccountConsent> entries;

  static const purposes = ['account_activity', 'editorial_updates', 'product_updates'];
  static const channels = ['email', 'push'];

  static AccountConsentMatrix parse(String raw) {
    if (raw.length > 65536) throw const FormatException('Consent response too large');
    final response = jsonDecode(raw);
    if (response is! Map<String, dynamic> ||
        response['data'] is! List || response['meta'] is! Map<String, dynamic> ||
        (response['meta'] as Map<String, dynamic>)['default_deny'] != true) {
      throw const FormatException('Invalid consent response');
    }
    final rows = response['data'] as List<dynamic>;
    if (rows.length != 6) throw const FormatException('Incomplete consent matrix');
    final parsed = <AccountConsent>[];
    final seen = <String>{};
    for (final row in rows) {
      if (row is! Map<String, dynamic> ||
          row.length != 5 ||
          row.keys.toSet().difference({
            'purpose', 'channel', 'enabled', 'consented_at', 'revoked_at',
          }).isNotEmpty ||
          !purposes.contains(row['purpose']) || !channels.contains(row['channel']) ||
          row['enabled'] is! bool) {
        throw const FormatException('Invalid consent entry');
      }
      for (final key in ['consented_at', 'revoked_at']) {
        final value = row[key];
        if (value != null &&
            (value is! String || value.length > 60 || DateTime.tryParse(value) == null)) {
          throw const FormatException('Invalid consent timestamp');
        }
      }
      if (row['enabled'] == true &&
          (row['consented_at'] == null || row['revoked_at'] != null)) {
        throw const FormatException('Unverified notification opt-in');
      }
      final purpose = row['purpose'] as String;
      final channel = row['channel'] as String;
      if (!seen.add(purpose + ':' + channel)) {
        throw const FormatException('Duplicate consent entry');
      }
      parsed.add(AccountConsent(purpose, channel, row['enabled'] as bool));
    }
    return AccountConsentMatrix(List.unmodifiable(parsed));
  }
}

class AccountPortableExport {
  const AccountPortableExport(this.raw, this.savedCount);
  final String raw;
  final int savedCount;

  static AccountPortableExport parse(String raw) {
    if (raw.length > 2 * 1024 * 1024) {
      throw const FormatException('Export exceeds device safety limit');
    }
    final response = jsonDecode(raw);
    if (response is! Map<String, dynamic> || response.length != 2 ||
        response['data'] is! Map<String, dynamic> ||
        response['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid account export');
    }
    final data = response['data'] as Map<String, dynamic>;
    final meta = response['meta'] as Map<String, dynamic>;
    if (data.length != 3 ||
        data.keys.toSet().difference({
          'profile', 'saved_resources', 'notification_preferences',
        }).isNotEmpty ||
        data['profile'] is! Map<String, dynamic> ||
        data['saved_resources'] is! List ||
        data['notification_preferences'] is! List ||
        meta['secrets_excluded'] != true ||
        meta['private_ai_prompts_excluded'] != true ||
        meta['foreign_user_data_excluded'] != true) {
      throw const FormatException('Unsafe account export');
    }
    final profile = data['profile'] as Map<String, dynamic>;
    final saved = data['saved_resources'] as List<dynamic>;
    final prefs = data['notification_preferences'] as List<dynamic>;
    if (profile['id'] is! String ||
        !RegExp(r'^[0-9a-fA-F-]{36}$').hasMatch(profile['id'] as String) ||
        profile['email'] is! String ||
        saved.length > 10000 || prefs.length > 6) {
      throw const FormatException('Invalid account export identity');
    }
    return AccountPortableExport(raw, saved.length);
  }
}

class AccountPrivacyClient {
  const AccountPrivacyClient();

  Future<String> _request(String method, String path, String token,
      [Map<String, Object?>? payload]) async {
    final origin = VerifiedArticleReader.validatedOrigin(MobileAiClient.apiOrigin);
    if (origin == null || token.isEmpty || token.length > 8192 ||
        !const {
          '/api/v1/mobile/notification-preferences',
          '/api/v1/mobile/account/export',
          '/api/v1/mobile/account',
        }.contains(path)) {
      throw const FormatException('Privacy API not configured');
    }
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.openUrl(method, origin.replace(path: path))
          .timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set(HttpHeaders.authorizationHeader, 'Bearer ' + token);
      if (payload != null) {
        request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
        request.add(utf8.encode(jsonEncode(payload)));
      }
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw MobileAiHttpException(response.statusCode);
      }
      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > 2 * 1024 * 1024) {
          throw const FormatException('Privacy response too large');
        }
        buffer.add(chunk);
      }
      return utf8.decode(buffer.takeBytes(), allowMalformed: false);
    } finally {
      client.close(force: true);
    }
  }

  Future<AccountConsentMatrix> read(String token) async =>
      AccountConsentMatrix.parse(await _request(
        'GET', '/api/v1/mobile/notification-preferences', token));

  Future<AccountConsentMatrix> save(
      String token, String purpose, String channel, bool enabled) async {
    if (!AccountConsentMatrix.purposes.contains(purpose) ||
        !AccountConsentMatrix.channels.contains(channel)) {
      throw const FormatException('Invalid consent');
    }
    return AccountConsentMatrix.parse(await _request(
      'PUT', '/api/v1/mobile/notification-preferences', token,
      {'preferences': [{'purpose': purpose, 'channel': channel, 'enabled': enabled}]},
    ));
  }

  Future<String> export(String token) async =>
      _request('GET', '/api/v1/mobile/account/export', token);

  Future<void> delete(String token, String password) async {
    if (password.isEmpty || password.length > 4096) {
      throw const FormatException('Invalid password');
    }
    final response = jsonDecode(await _request(
      'DELETE', '/api/v1/mobile/account', token,
      {'password': password, 'confirmation': 'DELETE'},
    ));
    if (response is! Map<String, dynamic> ||
        response['data'] is! Map<String, dynamic> ||
        (response['data'] as Map<String, dynamic>)['deleted'] != true ||
        (response['data'] as Map<String, dynamic>)['tokens_revoked'] != true) {
      throw const FormatException('Deletion unconfirmed');
    }
  }
}

typedef PrivacyRead = Future<AccountConsentMatrix> Function(String);
typedef PrivacySet = Future<AccountConsentMatrix> Function(String, String, String, bool);
typedef PrivacyExport = Future<String> Function(String);
typedef PrivacyDelete = Future<void> Function(String, String);

class MobileAccountPrivacyScreen extends StatefulWidget {
  const MobileAccountPrivacyScreen({
    required this.arabic,
    required this.bearer,
    required this.onDeleted,
    required this.onUnauthorized,
    this.readAction,
    this.saveAction,
    this.exportAction,
    this.deleteAction,
    super.key,
  });
  final bool arabic;
  final String bearer;
  final VoidCallback onDeleted;
  final VoidCallback onUnauthorized;
  final PrivacyRead? readAction;
  final PrivacySet? saveAction;
  final PrivacyExport? exportAction;
  final PrivacyDelete? deleteAction;

  @override
  State<MobileAccountPrivacyScreen> createState() => _MobileAccountPrivacyState();
}

class _MobileAccountPrivacyState extends State<MobileAccountPrivacyScreen> {
  AccountConsentMatrix? _matrix;
  String? _portable;
  String? _notice;
  bool _busy = false;
  bool _loaded = false;
  bool _unauthorized = false;

  String label(String en, String ar) => widget.arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _load();
    });
  }

  void _handleError(Object error) {
    if (error is MobileAiHttpException &&
        (error.statusCode == 401 || error.statusCode == 403)) {
      _unauthorized = true;
      _matrix = null;
      _portable = null;
      widget.onUnauthorized();
      _notice = label('Session no longer authorized. Sign in again.',
          'لم تعد الجلسة مصرحًا بها. سجل الدخول مجددًا.');
    } else {
      _notice = label('Request not confirmed. Check connection and retry.',
          'لم يتأكد الطلب. تحقق من الاتصال وأعد المحاولة.');
    }
  }

  Future<void> _load() async {
    if (_busy || _unauthorized) return;
    setState(() { _busy = true; _notice = null; });
    try {
      final matrix = await (widget.readAction ?? const AccountPrivacyClient().read)(
          widget.bearer);
      if (mounted) setState(() { _matrix = matrix; _loaded = true; });
    } catch (e) {
      if (mounted) setState(() { _loaded = true; _handleError(e); });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save(AccountConsent item, bool enabled) async {
    if (_busy || _unauthorized) return;
    setState(() { _busy = true; _notice = null; });
    try {
      final result = await (widget.saveAction ?? const AccountPrivacyClient().save)(
          widget.bearer, item.purpose, item.channel, enabled);
      if (mounted) setState(() {
        _matrix = result;
        _notice = label('Preference confirmed by server.', 'أكد الخادم حفظ التفضيل.');
      });
    } catch (e) {
      if (mounted) setState(() => _handleError(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _export() async {
    if (_busy || _unauthorized) return;
    setState(() { _busy = true; _portable = null; _notice = null; });
    try {
      final raw = await (widget.exportAction ?? const AccountPrivacyClient().export)(
          widget.bearer);
      final parsed = AccountPortableExport.parse(raw);
      if (mounted) setState(() {
        _portable = parsed.raw;
        _notice = label('Export ready: ' + parsed.savedCount.toString() +
            ' saved items. Copy on a private device only.',
            'التصدير جاهز: ' + parsed.savedCount.toString() +
            ' عنصر محفوظ. انسخه على جهاز خاص فقط.');
      });
    } catch (e) {
      if (mounted) setState(() => _handleError(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _copy() async {
    if (_portable == null || _busy || _unauthorized) return;
    await Clipboard.setData(ClipboardData(text: _portable!));
    if (mounted) setState(() {
      _notice = label('Private JSON copied. The clipboard can retain it.',
          'نُسخ JSON الشخصي؛ قد تبقى البيانات في الحافظة.');
    });
  }

  Future<void> _deleteAccount() async {
    if (_busy || _unauthorized) return;
    final password = TextEditingController();
    final word = TextEditingController();
    try {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (dialogContext) => StatefulBuilder(
          builder: (dialogContext, redraw) => AlertDialog(
            title: Text(label('Delete account', 'حذف الحساب')),
            content: SingleChildScrollView(child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(label(
                  'Delete saved data, revoke all tokens and consent, anonymize profile. Enter password and type DELETE.',
                  'حذف المحفوظات وإلغاء الجلسات والموافقات وإخفاء هوية الحساب. أدخل كلمة المرور واكتب DELETE.',
                )),
                TextField(
                  key: const Key('privacy-delete-password'),
                  controller: password,
                  obscureText: true,
                  autocorrect: false,
                  enableSuggestions: false,
                  decoration: InputDecoration(labelText: label('Password', 'كلمة المرور')),
                  onChanged: (_) => redraw(() {}),
                ),
                TextField(
                  key: const Key('privacy-delete-word'),
                  controller: word,
                  autocorrect: false,
                  decoration: const InputDecoration(labelText: 'DELETE'),
                  onChanged: (_) => redraw(() {}),
                ),
              ],
            )),
            actions: [
              TextButton(
                key: const Key('privacy-delete-cancel'),
                onPressed: () => Navigator.of(dialogContext).pop(false),
                child: Text(label('Cancel', 'إلغاء')),
              ),
              FilledButton(
                key: const Key('privacy-delete-confirm'),
                onPressed: word.text == 'DELETE' && password.text.isNotEmpty
                    ? () => Navigator.of(dialogContext).pop(true) : null,
                child: Text(label('Permanently delete', 'حذف نهائي')),
              ),
            ],
          ),
        ),
      ) ?? false;
      if (!mounted || !confirmed) return;
      setState(() { _busy = true; _notice = null; });
      try {
        await (widget.deleteAction ?? const AccountPrivacyClient().delete)(
            widget.bearer, password.text);
        if (!mounted) return;
        _portable = null;
        _matrix = null;
        widget.onDeleted();
        Navigator.of(context).pop();
      } catch (e) {
        if (mounted) setState(() => _handleError(e));
      } finally {
        if (mounted) setState(() => _busy = false);
      }
    } finally {
      password.clear();
      word.clear();
      password.dispose();
      word.dispose();
    }
  }

  @override
  void dispose() {
    _portable = null;
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: widget.arabic ? TextDirection.rtl : TextDirection.ltr,
    child: Scaffold(
      appBar: AppBar(title: Text(label('Account privacy', 'خصوصية الحساب'))),
      body: SafeArea(child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(label(
            'Notification sending is not enabled here. Consent is optional and off by default.',
            'إرسال الإشعارات غير متاح هنا؛ الموافقة اختيارية ومعطلة افتراضيًا.',
          )),
          if (_notice != null)
            Text(_notice!, key: const Key('privacy-status')),
          if (_busy) const LinearProgressIndicator(key: Key('privacy-loading')),
          const SizedBox(height: 14),
          Text(label('Notification consent', 'موافقات الإشعارات'),
              style: Theme.of(context).textTheme.titleLarge),
          if (_loaded && _matrix == null && !_unauthorized)
            TextButton(
              key: const Key('privacy-retry'),
              onPressed: _busy ? null : _load,
              child: Text(label('Retry loading', 'إعادة التحميل')),
            ),
          for (final item in _matrix?.entries ?? <AccountConsent>[])
            SwitchListTile(
              key: Key('privacy-' + item.purpose + '-' + item.channel),
              title: Text(item.purpose.replaceAll('_', ' ') + ' · ' + item.channel),
              value: item.enabled,
              onChanged: _busy || _unauthorized ? null : (v) => _save(item, v),
            ),
          const Divider(),
          Text(label('Portable account data', 'بيانات الحساب القابلة للنقل'),
              style: Theme.of(context).textTheme.titleLarge),
          Text(label('Personal export excludes private AI prompts and credentials.',
              'يستبعد تصدير الحساب نصوص AI الخاصة والمفاتيح.')),
          OutlinedButton(
            key: const Key('privacy-export'),
            onPressed: _busy || _unauthorized ? null : _export,
            child: Text(label('Prepare JSON export', 'تجهيز تصدير JSON')),
          ),
          if (_portable != null)
            OutlinedButton(
              key: const Key('privacy-copy'),
              onPressed: _busy ? null : _copy,
              child: Text(label('Copy export to clipboard', 'نسخ التصدير للحافظة')),
            ),
          const Divider(),
          Text(label('Account deletion', 'حذف الحساب'),
              style: Theme.of(context).textTheme.titleLarge),
          OutlinedButton(
            key: const Key('privacy-delete-open'),
            onPressed: _busy || _unauthorized ? null : _deleteAccount,
            child: Text(label('Request permanent deletion', 'طلب الحذف النهائي')),
          ),
        ],
      )),
    ),
  );
}

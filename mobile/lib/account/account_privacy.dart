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
  const AccountPortableExport(
      this.raw, this.savedCount, this.interviewCount, this.answerCount);
  final String raw;
  final int savedCount;
  final int interviewCount;
  final int answerCount;

  // Only the current account owner's documented, bounded JSON archive may
  // reach the clipboard. Unknown fields can contain credentials or another
  // person's data; never blindly copy them from an untrusted response.
  static final _uuid = RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-'
    r'[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
  );

  static bool _timestamp(Object? value) =>
      value is String && value.length <= 60 &&
      DateTime.tryParse(value) != null;

  static bool _optionalTimestamp(Object? value) =>
      value == null || _timestamp(value);

  static bool _keys(Map<String, dynamic> row, Set<String> fields) =>
      row.length == fields.length &&
      row.keys.toSet().containsAll(fields);

  static AccountPortableExport parse(String raw) {
    if (raw.length > 2 * 1024 * 1024) {
      throw const FormatException('Export exceeds device safety limit');
    }
    final response = jsonDecode(raw);
    if (response is! Map<String, dynamic> ||
        !_keys(response, {'data', 'meta'}) ||
        response['data'] is! Map<String, dynamic> ||
        response['meta'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid account export');
    }

    final data = response['data'] as Map<String, dynamic>;
    final meta = response['meta'] as Map<String, dynamic>;
    if (!_keys(data, {
          'profile', 'saved_resources', 'private_interviews',
          'notification_preferences',
        }) ||
        !_keys(meta, {
          'exported_at', 'secrets_excluded',
          'private_ai_prompts_excluded', 'private_interview_answers_included',
          'foreign_user_data_excluded',
        }) ||
        data['profile'] is! Map<String, dynamic> ||
        data['saved_resources'] is! List ||
        data['private_interviews'] is! List ||
        data['notification_preferences'] is! List ||
        !_timestamp(meta['exported_at']) ||
        meta['secrets_excluded'] != true ||
        meta['private_ai_prompts_excluded'] != true ||
        meta['private_interview_answers_included'] != true ||
        meta['foreign_user_data_excluded'] != true) {
      throw const FormatException('Unsafe account export');
    }

    final profile = data['profile'] as Map<String, dynamic>;
    final saved = data['saved_resources'] as List<dynamic>;
    final interviews = data['private_interviews'] as List<dynamic>;
    final prefs = data['notification_preferences'] as List<dynamic>;
    if (!_keys(profile, {'id', 'email', 'status', 'created_at'}) ||
        profile['id'] is! String ||
        !_uuid.hasMatch(profile['id'] as String) ||
        profile['email'] is! String ||
        (profile['email'] as String).trim().isEmpty ||
        (profile['email'] as String).length > 254 ||
        profile['status'] != 'active' ||
        !_timestamp(profile['created_at']) ||
        saved.length > 10000 || interviews.length > 1000 ||
        prefs.length > 6) {
      throw const FormatException('Invalid account export identity');
    }

    final savedKeys = <String>{};
    for (final entry in saved) {
      if (entry is! Map<String, dynamic> ||
          !_keys(entry, {'resource_type', 'resource_id', 'saved_at'}) ||
          !const ['article', 'runbook'].contains(entry['resource_type']) ||
          entry['resource_id'] is! String ||
          !_uuid.hasMatch(entry['resource_id'] as String) ||
          !_timestamp(entry['saved_at']) ||
          !savedKeys.add('${entry['resource_type']}:${entry['resource_id']}')) {
        throw const FormatException('Unsafe saved-resource archive');
      }
    }

    final interviewKeys = <String>{};
    var answers = 0;
    for (final entry in interviews) {
      if (entry is! Map<String, dynamic> ||
          !_keys(entry, {
            'session_id', 'path_id', 'lesson_index', 'locale',
            'answer_text', 'answered_at', 'created_at',
          }) ||
          entry['session_id'] is! String ||
          !_uuid.hasMatch(entry['session_id'] as String) ||
          !interviewKeys.add(entry['session_id'] as String) ||
          entry['path_id'] is! String ||
          !_uuid.hasMatch(entry['path_id'] as String) ||
          entry['lesson_index'] is! int ||
          (entry['lesson_index'] as int) < 0 ||
          (entry['lesson_index'] as int) > 39 ||
          !const ['ar', 'en'].contains(entry['locale']) ||
          !_timestamp(entry['created_at']) ||
          !_optionalTimestamp(entry['answered_at'])) {
        throw const FormatException('Unsafe private interview archive');
      }
      final answer = entry['answer_text'];
      if (answer != null) {
        if (answer is! String || answer.length > 4000 ||
            answer.trim().isEmpty || !_timestamp(entry['answered_at'])) {
          throw const FormatException('Unsafe private interview answer');
        }
        answers++;
      } else if (entry['answered_at'] != null) {
        throw const FormatException('Unmatched private interview timestamp');
      }
    }

    final preferenceKeys = <String>{};
    for (final entry in prefs) {
      if (entry is! Map<String, dynamic> ||
          !_keys(entry, {
            'purpose', 'channel', 'enabled', 'consented_at', 'revoked_at',
          }) ||
          !AccountConsentMatrix.purposes.contains(entry['purpose']) ||
          !AccountConsentMatrix.channels.contains(entry['channel']) ||
          entry['enabled'] is! bool ||
          !_optionalTimestamp(entry['consented_at']) ||
          !_optionalTimestamp(entry['revoked_at']) ||
          (entry['enabled'] == true &&
              (entry['consented_at'] == null ||
               entry['revoked_at'] != null)) ||
          !preferenceKeys.add('${entry['purpose']}:${entry['channel']}')) {
        throw const FormatException('Unsafe notification preference archive');
      }
    }
    return AccountPortableExport(raw, saved.length, interviews.length, answers);
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
        _notice = label(
            'Export ready: ${parsed.savedCount} saved items, '
            '${parsed.interviewCount} interview sessions '
            '(${parsed.answerCount} private answers). '
            'Copy only on a trusted device.',
            'التصدير جاهز: ${parsed.savedCount} عنصر محفوظ، '
            '${parsed.interviewCount} جلسة مقابلة '
            '(${parsed.answerCount} إجابة خاصة). '
            'انسخه على جهاز موثوق فقط.');
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
    // The dialog owns its text controllers until its exit animation fully
    // unmounts. Disposing them as soon as showDialog resolves is too early.
    final password = await showDialog<String>(
      context: context,
      builder: (_) => _AccountDeleteConfirmation(arabic: widget.arabic),
    );
    if (!mounted || password == null) return;
    setState(() { _busy = true; _notice = null; });
    try {
      await (widget.deleteAction ?? const AccountPrivacyClient().delete)(
          widget.bearer, password);
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
          Text(label(
              'Export includes your privately authored interview answers '
              'and saved items. It excludes private AI prompts and credentials. '
              'Copies and device clipboards can retain sensitive information.',
              'يحتوي التصدير على إجابات المقابلات الخاصة التي كتبتها والعناصر '
              'المحفوظة، ويستبعد نصوص AI الخاصة والمفاتيح. '
              'قد تحتفظ النسخ وحافظة الجهاز بمعلومات حساسة.')),
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

class _AccountDeleteConfirmation extends StatefulWidget {
  const _AccountDeleteConfirmation({required this.arabic});
  final bool arabic;

  @override
  State<_AccountDeleteConfirmation> createState() =>
      _AccountDeleteConfirmationState();
}

class _AccountDeleteConfirmationState extends State<_AccountDeleteConfirmation> {
  final _password = TextEditingController();
  final _word = TextEditingController();

  String label(String en, String ar) => widget.arabic ? ar : en;

  @override
  void dispose() {
    _password.clear();
    _word.clear();
    _password.dispose();
    _word.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
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
          controller: _password,
          obscureText: true,
          autocorrect: false,
          enableSuggestions: false,
          decoration: InputDecoration(labelText: label('Password', 'كلمة المرور')),
          onChanged: (_) => setState(() {}),
        ),
        TextField(
          key: const Key('privacy-delete-word'),
          controller: _word,
          autocorrect: false,
          decoration: const InputDecoration(labelText: 'DELETE'),
          onChanged: (_) => setState(() {}),
        ),
      ],
    )),
    actions: [
      TextButton(
        key: const Key('privacy-delete-cancel'),
        onPressed: () => Navigator.of(context).pop(),
        child: Text(label('Cancel', 'إلغاء')),
      ),
      FilledButton(
        key: const Key('privacy-delete-confirm'),
        onPressed: _word.text == 'DELETE' && _password.text.isNotEmpty
            ? () => Navigator.of(context).pop(_password.text) : null,
        child: Text(label('Permanently delete', 'حذف نهائي')),
      ),
    ],
  );
}

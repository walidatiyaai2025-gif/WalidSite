import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';
import '../runbooks/signed_runbook_sync.dart';
import '../theme/waleed_theme.dart';

/// MOBILE-13 server capabilities are read-only; never claim server-side
/// locale persistence, dark mode, push subscriptions or invented quotas.
class MobilePreferenceCapabilities {
  const MobilePreferenceCapabilities(this.locale);
  final String locale;

  static MobilePreferenceCapabilities parse(String raw, bool requestedArabic) {
    if (raw.length > 65536) {
      throw const FormatException('Preferences response too large');
    }
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 || decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid preferences response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    const permitted = {
      'locale', 'default_locale', 'supported_locales', 'available_themes',
      'theme', 'locale_saved_on_server', 'requires_authentication',
    };
    if (data.length != permitted.length ||
        data.keys.toSet().difference(permitted).isNotEmpty ||
        data['locale'] != (requestedArabic ? 'ar' : 'en') ||
        (data['default_locale'] != 'en' && data['default_locale'] != 'ar') ||
        data['supported_locales'] is! List ||
        (data['supported_locales'] as List).length != 2 ||
        !(data['supported_locales'] as List).contains('ar') ||
        !(data['supported_locales'] as List).contains('en') ||
        data['available_themes'] is! List ||
        (data['available_themes'] as List).length != 1 ||
        (data['available_themes'] as List).single != 'light' ||
        data['theme'] != 'light' ||
        data['locale_saved_on_server'] != false ||
        data['requires_authentication'] != false) {
      throw const FormatException('Unsupported preference capabilities');
    }
    return MobilePreferenceCapabilities(data['locale'] as String);
  }
}

class MobilePreferenceReader {
  const MobilePreferenceReader();

  static Uri? configuredOrigin() => VerifiedArticleReader.validatedOrigin(
      const String.fromEnvironment('WALIDSITE_API_ORIGIN'));

  Future<MobilePreferenceCapabilities> load(bool arabic) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Preferences API is not configured');
    }
    final url = origin.replace(path: '/api/v1/mobile/preferences',
        queryParameters: {'lang': arabic ? 'ar' : 'en'});
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Preferences unavailable');
      }
      final buffer = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (buffer.length + chunk.length > 65536) {
          throw const FormatException('Preferences API exceeded response limit');
        }
        buffer.add(chunk);
      }
      return MobilePreferenceCapabilities.parse(
        utf8.decode(buffer.takeBytes(), allowMalformed: false), arabic,
      );
    } finally {
      client.close(force: true);
    }
  }
}

typedef PreferenceFetch = Future<MobilePreferenceCapabilities> Function(bool);
typedef SignedCacheClear = Future<void> Function();

/// Only local language and verified signed-download removal are actionable.
/// All unsupported server controls explicitly declare their unavailable state.
class MobilePreferencesScreen extends StatefulWidget {
  const MobilePreferencesScreen({
    required this.arabic,
    required this.onLanguageChanged,
    this.fetch,
    this.clearSignedCache,
    super.key,
  });

  final bool arabic;
  final ValueChanged<bool> onLanguageChanged;
  final PreferenceFetch? fetch;
  final SignedCacheClear? clearSignedCache;

  @override
  State<MobilePreferencesScreen> createState() => _MobilePreferencesScreenState();
}

class _MobilePreferencesScreenState extends State<MobilePreferencesScreen> {
  late bool _arabic;
  Future<MobilePreferenceCapabilities>? _capabilities;
  bool _clearing = false;
  String? _clearMessage;

  String label(String en, String ar) => _arabic ? ar : en;

  bool get _configured =>
      widget.fetch != null || MobilePreferenceReader.configuredOrigin() != null;

  Future<void> _read() async {
    if (!_configured) return;
    setState(() {
      _capabilities = (widget.fetch ?? const MobilePreferenceReader().load)(
        _arabic,
      );
    });
  }

  @override
  void initState() {
    super.initState();
    _arabic = widget.arabic;
    _read();
  }

  void _changeLanguage(bool arabic) {
    if (arabic == _arabic) return;
    setState(() {
      _arabic = arabic;
      _clearMessage = null;
      _capabilities = null;
    });
    widget.onLanguageChanged(arabic);
    _read();
  }

  Future<void> _clear() async {
    if (_clearing) return;
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(label('Delete downloaded guides?', 'حذف الأدلة المحمّلة؟')),
        content: Text(label(
          'Only the downloaded signed runbook snapshot is deleted. '
          'Bundled offline guides and server/account data are not affected.',
          'ستُحذف نسخة الأدلة الموقعة المحمّلة فقط. '
          'لن تتأثر الأدلة المرفقة بالتطبيق أو بيانات الخادم والحساب.',
        )),
        actions: [
          TextButton(
            key: const Key('preferences-clear-cancel'),
            onPressed: () => Navigator.pop(dialogContext, false),
            child: Text(label('Cancel', 'إلغاء')),
          ),
          FilledButton(
            key: const Key('preferences-clear-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(label('Delete download', 'حذف النسخة')),
          ),
        ],
      ),
    );
    if (accepted != true || !mounted) return;
    setState(() {
      _clearing = true;
      _clearMessage = null;
    });
    try {
      await (widget.clearSignedCache ??
          const AppSupportRunbookStore().clearDownloadedSnapshot)();
      if (!mounted) return;
      setState(() => _clearMessage = label(
        'Downloaded signed guide removed. Bundled guides remain available.',
        'حُذفت النسخة الموقّعة المحمّلة. الأدلة المرفقة ما زالت متاحة.',
      ));
    } catch (_) {
      if (!mounted) return;
      setState(() => _clearMessage = label(
        'Could not remove the download. Try again.',
        'تعذر حذف النسخة المحمّلة. حاول مجددًا.',
      ));
    } finally {
      if (mounted) setState(() => _clearing = false);
    }
  }

  Widget _panel(String key, String title, String subtitle, {Widget? action}) {
    return Card(
      key: Key('preferences-$key'),
      color: const Color(0xFFDFEFF7),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: const TextStyle(
              color: WaleedPalette.primary, fontWeight: FontWeight.bold,
              fontSize: 19)),
          const SizedBox(height: 8),
          Text(subtitle, style: const TextStyle(color: WaleedPalette.body)),
          if (action != null) ...[const SizedBox(height: 10), action],
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: _arabic ? TextDirection.rtl : TextDirection.ltr,
    child: Scaffold(
      appBar: AppBar(title: Text(label('Preferences', 'التفضيلات'))),
      body: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
          Text(label('Profile', 'الحساب'), style: const TextStyle(
              color: WaleedPalette.accent, fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          if (!_configured)
            Text(label('Server preferences unavailable; local controls still work.',
                'إعدادات الخادم غير مهيأة؛ التحكم المحلي ما زال يعمل.'),
                key: const Key('preferences-unconfigured'))
          else if (_capabilities != null)
            FutureBuilder<MobilePreferenceCapabilities>(
              future: _capabilities,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(child: CircularProgressIndicator(
                    key: Key('preferences-loading')));
                }
                if (snapshot.hasError) {
                  return Column(children: [
                    Text(label('Could not verify server preferences.',
                        'تعذر التحقق من تفضيلات الخادم.'),
                        key: const Key('preferences-error')),
                    FilledButton(
                      key: const Key('preferences-retry'),
                      onPressed: _read,
                      child: Text(label('Retry', 'إعادة المحاولة')),
                    ),
                  ]);
                }
                return Text(label('Server confirms English/Arabic and light theme only. '
                    'Language is not saved on the server.',
                    'الخادم يدعم العربية والإنجليزية والمظهر الفاتح فقط. '
                    'اللغة لا تُحفظ على الخادم.'),
                    key: const Key('preferences-verified'));
              },
            ),
          const SizedBox(height: 18),
          _panel('language', label('Language', 'اللغة'),
              label('Arabic / English · local to this session',
                  'العربية / الإنجليزية · خلال هذه الجلسة فقط'),
              action: SegmentedButton<bool>(
                key: const Key('preferences-language-picker'),
                segments: const [
                  ButtonSegment(value: false, label: Text('English')),
                  ButtonSegment(value: true, label: Text('العربية')),
                ],
                selected: {_arabic},
                onSelectionChanged: (selection) => _changeLanguage(selection.first),
              )),
          const SizedBox(height: 12),
          _panel('offline', label('Offline mode', 'وضع عدم الاتصال'),
            label('Bundled guides stay on device. Signed updates require '
                'a verified connection; Wi-Fi-only scheduling is unavailable.',
                'الأدلة المرفقة تعمل دون إنترنت. التحديثات الموقعة تتطلب اتصالًا موثوقًا؛ '
                'جدولة المزامنة عبر Wi-Fi فقط غير متاحة.'),
            action: OutlinedButton.icon(
              key: const Key('preferences-clear-cache'),
              onPressed: _clearing ? null : _clear,
              icon: const Icon(Icons.delete_outline),
              label: Text(_clearing
                ? label('Deleting...', 'جارٍ الحذف...')
                : label('Remove downloaded guide', 'حذف الدليل المحمّل')),
            )),
          if (_clearMessage != null) Text(_clearMessage!,
              key: const Key('preferences-clear-status')),
          const SizedBox(height: 12),
          _panel('alerts', label('Alerts', 'التنبيهات'),
              label('Push/topic subscriptions are not available.',
                  'اشتراكات التنبيهات حسب الموضوع غير متاحة حاليًا.')),
          const SizedBox(height: 12),
          _panel('ai', label('AI budget', 'ميزانية الذكاء الاصطناعي'),
              label('No personal allowance is advertised. Provider limits '
                  'are enforced on the server.',
                  'لا يوجد رصيد شخصي معلن. حدود المزودين تُفرض على الخادم.')),
          const SizedBox(height: 12),
          _panel('data', label('Data controls', 'التحكم في البيانات'),
              label('Server account export/deletion is not implemented. '
                  'Removing a downloaded guide does not delete server data.',
                  'تصدير بيانات الحساب أو حذفها على الخادم غير منفذ بعد. '
                  'حذف الدليل المحمّل لا يحذف بيانات الخادم.')),
          const SizedBox(height: 16),

            ],
          ),
        ),
      ),
    ),
  );
}

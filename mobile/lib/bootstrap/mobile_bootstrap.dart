import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../articles/verified_articles.dart';

class MobileBootstrapCapabilities {
  const MobileBootstrapCapabilities({
    required this.appName,
    required this.locale,
    required this.defaultLocale,
    required this.aiDiagnosisAvailable,
    required this.privacyUri,
  });

  final String appName;
  final String locale;
  final String defaultLocale;
  final bool aiDiagnosisAvailable;
  final Uri? privacyUri;

  static MobileBootstrapCapabilities parse(
    String body,
    bool arabic,
    Uri origin,
  ) {
    if (body.length > 128 * 1024) {
      throw const FormatException('Bootstrap response too large');
    }
    final decoded = jsonDecode(body);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid bootstrap response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    const keys = {
      'app_name',
      'locale',
      'default_locale',
      'supported_locales',
      'guest_entry_allowed',
      'available_themes',
      'routes',
      'availability',
      'privacy_policy_url',
    };
    if (data.length != keys.length ||
        data.keys.toSet().difference(keys).isNotEmpty) {
      throw const FormatException('Unexpected bootstrap fields');
    }

    final locale = arabic ? 'ar' : 'en';
    final expectedName = arabic ? 'رفيق وليد التقني' : 'Waleed Tech Companion';
    final defaultLocale = data['default_locale'];
    final supported = data['supported_locales'];
    final themes = data['available_themes'];
    final routes = data['routes'];
    final availability = data['availability'];
    final privacy = data['privacy_policy_url'];

    const expectedRoutes = {
      'home': '/api/v1/mobile/home',
      'articles': '/api/v1/mobile/articles',
      'runbooks': '/api/v1/mobile/runbooks',
      'learning_paths': '/api/v1/mobile/learn',
      'saved_resources': '/api/v1/mobile/saved',
      'ai_diagnosis': '/api/v1/mobile/ai/diagnose',
    };
    const availabilityKeys = {
      'guest_reading',
      'guest_runbooks',
      'guest_learning_paths',
      'authenticated_ai_diagnosis',
      'server_saved_resources',
      'saved_resources_requires_auth',
    };

    if (data['app_name'] != expectedName ||
        data['locale'] != locale ||
        (defaultLocale != 'en' && defaultLocale != 'ar') ||
        supported is! List ||
        supported.length != 2 ||
        supported[0] != 'en' ||
        supported[1] != 'ar' ||
        data['guest_entry_allowed'] != true ||
        themes is! List ||
        themes.length != 1 ||
        themes.single != 'light' ||
        routes is! Map<String, dynamic> ||
        routes.length != expectedRoutes.length ||
        routes.keys.toSet().difference(expectedRoutes.keys.toSet()).isNotEmpty ||
        availability is! Map<String, dynamic> ||
        availability.length != availabilityKeys.length ||
        availability.keys.toSet().difference(availabilityKeys).isNotEmpty ||
        availability['guest_reading'] != true ||
        availability['guest_runbooks'] != true ||
        availability['guest_learning_paths'] != true ||
        availability['authenticated_ai_diagnosis'] is! bool ||
        availability['server_saved_resources'] != true ||
        availability['saved_resources_requires_auth'] != true ||
        (privacy != null && privacy is! String)) {
      throw const FormatException('Unsupported bootstrap capabilities');
    }

    for (final entry in expectedRoutes.entries) {
      if (routes[entry.key] != entry.value) {
        throw const FormatException('Bootstrap route mismatch');
      }
    }

    Uri? privacyUri;
    if (privacy is String) {
      final relative = Uri.tryParse(privacy);
      if (relative == null ||
          relative.hasScheme ||
          relative.hasAuthority ||
          relative.userInfo.isNotEmpty ||
          relative.hasFragment ||
          relative.path != '/privacy' ||
          relative.queryParameters.length != 1 ||
          relative.queryParameters['lang'] != locale) {
        throw const FormatException('Unsafe privacy route');
      }
      privacyUri = origin.resolveUri(relative);
      if (privacyUri.scheme != origin.scheme ||
          privacyUri.host != origin.host ||
          privacyUri.port != origin.port) {
        throw const FormatException('Cross-origin privacy route');
      }
    }

    return MobileBootstrapCapabilities(
      appName: expectedName,
      locale: locale,
      defaultLocale: defaultLocale as String,
      aiDiagnosisAvailable:
          availability['authenticated_ai_diagnosis'] as bool,
      privacyUri: privacyUri,
    );
  }
}

class MobileBootstrapReader {
  const MobileBootstrapReader();

  static const _limit = 128 * 1024;

  static Uri? configuredOrigin() => VerifiedArticleReader.configuredOrigin();

  Future<MobileBootstrapCapabilities> load(bool arabic) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Bootstrap API not configured');
    }
    final url = origin.replace(
      path: '/api/v1/public/app-bootstrap',
      queryParameters: {'lang': arabic ? 'ar' : 'en'},
    );
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.getUrl(url).timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw const HttpException('Bootstrap unavailable');
      }
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > _limit) {
          throw const FormatException('Bootstrap response too large');
        }
        bytes.add(chunk);
      }
      return MobileBootstrapCapabilities.parse(
        utf8.decode(bytes.takeBytes(), allowMalformed: false),
        arabic,
        origin,
      );
    } finally {
      client.close(force: true);
    }
  }
}

typedef MobileBootstrapFetch =
    Future<MobileBootstrapCapabilities> Function(bool arabic);
typedef PrivacyOpen = Future<bool> Function(Uri uri);

Future<bool> _openPrivacy(Uri uri) =>
    launchUrl(uri, mode: LaunchMode.externalApplication);

class MobileBootstrapPanel extends StatefulWidget {
  const MobileBootstrapPanel({
    required this.arabic,
    this.fetch,
    this.openPrivacy,
    super.key,
  });

  final bool arabic;
  final MobileBootstrapFetch? fetch;
  final PrivacyOpen? openPrivacy;

  @override
  State<MobileBootstrapPanel> createState() => _MobileBootstrapPanelState();
}

class _MobileBootstrapPanelState extends State<MobileBootstrapPanel> {
  Future<MobileBootstrapCapabilities>? _capabilities;

  bool get _configured =>
      widget.fetch != null || MobileBootstrapReader.configuredOrigin() != null;

  String label(String en, String ar) => widget.arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant MobileBootstrapPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.arabic != widget.arabic || oldWidget.fetch != widget.fetch) {
      _load();
    }
  }

  void _load() {
    if (!_configured) {
      _capabilities = null;
      return;
    }
    _capabilities =
        (widget.fetch ?? const MobileBootstrapReader().load)(widget.arabic);
  }

  Future<void> _privacy(Uri privacyUri) async {
    try {
      final opened = await (widget.openPrivacy ?? _openPrivacy)(privacyUri);
      if (!opened && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(label(
            'Could not open the privacy disclosure.',
            'تعذر فتح إشعار الخصوصية.',
          ))),
        );
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(label(
          'Could not open the privacy disclosure.',
          'تعذر فتح إشعار الخصوصية.',
        ))),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_configured) {
      return Card(
        key: const Key('welcome-bootstrap-unconfigured'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Text(label(
            'Live capability check is not configured. Offline guest entry remains available.',
            'التحقق المباشر من الإمكانات غير مهيأ. يظل الدخول كضيف دون إنترنت متاحًا.',
          )),
        ),
      );
    }

    return FutureBuilder<MobileBootstrapCapabilities>(
      future: _capabilities,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Card(
            key: Key('welcome-bootstrap-loading'),
            child: Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            ),
          );
        }
        if (snapshot.hasError || !snapshot.hasData) {
          return Card(
            key: const Key('welcome-bootstrap-error'),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label(
                    'Could not verify live capabilities. You can still use offline guest entry.',
                    'تعذر التحقق من الإمكانات المباشرة. يمكنك الاستمرار كضيف دون إنترنت.',
                  )),
                  const SizedBox(height: 8),
                  TextButton(
                    key: const Key('welcome-bootstrap-retry'),
                    onPressed: () => setState(_load),
                    child: Text(label('Retry', 'إعادة المحاولة')),
                  ),
                ],
              ),
            ),
          );
        }

        final data = snapshot.data!;
        return Card(
          key: const Key('welcome-bootstrap-ready'),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label(
                    'Guest reading and reviewed runbooks are available.',
                    'القراءة كضيف وأدلة التشغيل المراجعة متاحة.',
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  data.aiDiagnosisAvailable
                      ? label(
                          'AI diagnosis is available after sign-in.',
                          'تشخيص الذكاء الاصطناعي متاح بعد تسجيل الدخول.',
                        )
                      : label(
                          'AI diagnosis is currently unavailable.',
                          'تشخيص الذكاء الاصطناعي غير متاح حاليًا.',
                        ),
                ),
                const SizedBox(height: 8),
                if (data.privacyUri != null)
                  TextButton.icon(
                    key: const Key('welcome-privacy'),
                    onPressed: () => _privacy(data.privacyUri!),
                    icon: const Icon(Icons.privacy_tip_outlined),
                    label: Text(label(
                      'Privacy & data disclosure',
                      'الخصوصية والبيانات',
                    )),
                  )
                else
                  Text(
                    label(
                      'A public privacy disclosure has not been published yet.',
                      'لم يتم نشر إشعار خصوصية عام حتى الآن.',
                    ),
                    key: const Key('welcome-privacy-unavailable'),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

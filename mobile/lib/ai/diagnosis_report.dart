import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';

/// Explicit user-authored report only. No diagnostic prompt, code, bearer,
/// result URL, provider details or job identifier is copied into this payload.
class DiagnosisReportInput {
  DiagnosisReportInput({
    required String name,
    required String email,
    required String message,
    required bool consent,
  })  : name = name.trim(),
        email = email.trim(),
        message = message.trim() {
    if (!consent ||
        this.name.length < 2 || this.name.length > 100 ||
        !RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(this.email) ||
        this.email.length > 254 ||
        this.message.length < 10 || this.message.length > 3000 ||
        RegExp(r'[\x00-\x1F\x7F]').hasMatch(this.name) ||
        RegExp(r'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]')
            .hasMatch(this.message)) {
      throw const FormatException('Invalid contact or consent fields');
    }
  }

  final String name;
  final String email;
  final String message;

  Map<String, Object> toJson() => {
        'name': name,
        'email': email,
        'message': message,
        'privacy_consent': true,
      };
}

class DiagnosisReportHttpException implements Exception {
  const DiagnosisReportHttpException(this.statusCode);
  final int statusCode;
}

class DiagnosisReportReceipt {
  const DiagnosisReportReceipt._();

  static DiagnosisReportReceipt parse(String raw) {
    if (raw.length > 4096) {
      throw const FormatException('Oversized contact receipt');
    }
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic> ||
        decoded['ok'] != true ||
        decoded['data'] is! Map<String, dynamic> ||
        (decoded['data'] as Map<String, dynamic>)['accepted_by_transport'] != true) {
      throw const FormatException('Contact transport did not accept the report');
    }
    // A transport acceptance is never proof of email inbox delivery.
    return const DiagnosisReportReceipt._();
  }
}

typedef DiagnosisReportAction = Future<void> Function(
    DiagnosisReportInput report);

/// Reuses the existing WEB-14 consent-gated contact transport. No bearer is
/// attached and a non-HTTPS origin or redirect is never accepted.
class DiagnosisReportClient {
  const DiagnosisReportClient();

  static const apiOrigin = String.fromEnvironment('WALIDSITE_API_ORIGIN');

  static Uri? configuredOrigin() =>
      VerifiedArticleReader.validatedOrigin(apiOrigin);

  Future<void> send(DiagnosisReportInput report) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('Unconfigured secure contact origin');
    }
    final url = origin.replace(path: '/api/v1/public/contact');
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.postUrl(url)
          .timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
      request.add(utf8.encode(jsonEncode(report.toJson())));
      final response = await request.close()
          .timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw DiagnosisReportHttpException(response.statusCode);
      }
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > 4096) {
          throw const FormatException('Oversized contact receipt');
        }
        bytes.add(chunk);
      }
      DiagnosisReportReceipt.parse(
          utf8.decode(bytes.takeBytes(), allowMalformed: false));
    } finally {
      client.close(force: true);
    }
  }
}

/// MOBILE-03: an independent, explicit report form for a diagnosis problem.
/// The API does not accept secret, job-id or code fields, so none are sent.
class MobileDiagnosisReportScreen extends StatefulWidget {
  const MobileDiagnosisReportScreen({
    required this.arabic,
    this.initialEmail,
    this.submitAction,
    super.key,
  });

  final bool arabic;
  final String? initialEmail;
  final DiagnosisReportAction? submitAction;

  @override
  State<MobileDiagnosisReportScreen> createState() =>
      _MobileDiagnosisReportScreenState();
}

class _MobileDiagnosisReportScreenState
    extends State<MobileDiagnosisReportScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _description = TextEditingController();
  bool _consent = false;
  bool _busy = false;
  bool _sent = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    // Prefill a visible, editable address; never silently submit it.
    final candidate = widget.initialEmail;
    if (candidate != null && candidate.length <= 254 &&
        RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(candidate)) {
      _email.text = candidate;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _description.dispose();
    super.dispose();
  }

  bool get _configured => widget.submitAction != null ||
      DiagnosisReportClient.configuredOrigin() != null;

  String _text(String english, String arabic) =>
      widget.arabic ? arabic : english;

  Future<void> _send() async {
    if (_busy || _sent || !_configured) return;
    DiagnosisReportInput report;
    try {
      report = DiagnosisReportInput(
        name: _name.text,
        email: _email.text,
        message: _description.text,
        consent: _consent,
      );
    } on FormatException {
      setState(() => _message = _text(
          'Enter a valid name, email, 10–3000 character description, and consent.',
          'أدخل اسمًا وبريدًا صحيحين ووصفًا من 10 إلى 3000 حرف ووافق على الإرسال.'));
      return;
    }
    setState(() { _busy = true; _message = null; });
    try {
      await (widget.submitAction ?? const DiagnosisReportClient().send)(report);
      if (!mounted) return;
      setState(() {
        _sent = true;
        _consent = false;
        _description.clear();
        _message = _text(
            'Report accepted by the configured mail transport. Inbox delivery is not confirmed.',
            'قبل نظام إرسال البريد البلاغ. لم يتم التأكد من وصوله إلى صندوق البريد.');
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _message = switch (error) {
          DiagnosisReportHttpException(statusCode: 422) => _text(
              'Please review the report fields.',
              'راجع حقول البلاغ ثم أعد المحاولة.'),
          DiagnosisReportHttpException(statusCode: 429) => _text(
              'Report rate limit reached. Try later.',
              'تم بلوغ حد إرسال البلاغات. حاول لاحقًا.'),
          DiagnosisReportHttpException(statusCode: 503) => _text(
              'Report delivery is not configured or is unavailable.',
              'إرسال البلاغات غير مهيأ أو غير متاح حاليًا.'),
          _ => _text(
              'Could not send the report securely. Check the connection.',
              'تعذر إرسال البلاغ بأمان. تحقق من الاتصال.'),
        };
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
      child: Scaffold(
        appBar: AppBar(title: Text(_text('Report diagnosis issue', 'الإبلاغ عن مشكلة التشخيص'))),
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(_text(
                  'Describe the issue yourself. Your AI question, code, job ID and account token are never attached automatically. Do not enter secrets.',
                  'اكتب وصف المشكلة بنفسك. لا يُرفق السؤال أو الكود أو رقم المهمة أو رمز حسابك تلقائيًا. لا تكتب أسرارًا.')),
              const SizedBox(height: 16),
              if (!_configured)
                Text(
                  _text('Secure report delivery is not configured for this build.',
                      'إرسال البلاغات الآمن غير مهيأ في هذا الإصدار.'),
                  key: const Key('report-unconfigured'),
                )
              else ...[
                TextField(
                  key: const Key('report-name'),
                  controller: _name,
                  enabled: !_busy && !_sent,
                  maxLength: 100,
                  textCapitalization: TextCapitalization.words,
                  decoration: InputDecoration(
                    border: const OutlineInputBorder(),
                    labelText: _text('Your name', 'الاسم'),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  key: const Key('report-email'),
                  controller: _email,
                  enabled: !_busy && !_sent,
                  maxLength: 254,
                  keyboardType: TextInputType.emailAddress,
                  autofillHints: const [AutofillHints.email],
                  decoration: InputDecoration(
                    border: const OutlineInputBorder(),
                    labelText: _text('Reply email', 'البريد الإلكتروني للرد'),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  key: const Key('report-description'),
                  controller: _description,
                  enabled: !_busy && !_sent,
                  maxLength: 3000,
                  minLines: 4,
                  maxLines: 8,
                  decoration: InputDecoration(
                    border: const OutlineInputBorder(),
                    labelText: _text('What went wrong?', 'ما المشكلة التي حدثت؟'),
                  ),
                ),
                CheckboxListTile(
                  key: const Key('report-consent'),
                  value: _consent,
                  onChanged: _busy || _sent ? null :
                      (checked) => setState(() => _consent = checked == true),
                  controlAffinity: ListTileControlAffinity.leading,
                  title: Text(_text(
                      'I consent to sending these contact details and my description by email.',
                      'أوافق على إرسال بيانات التواصل والوصف المكتوب بالبريد الإلكتروني.')),
                ),
                const SizedBox(height: 10),
                FilledButton.icon(
                  key: const Key('report-send'),
                  onPressed: _busy || _sent ? null : _send,
                  icon: const Icon(Icons.send_outlined),
                  label: Text(_busy
                      ? _text('Sending...', 'جارٍ الإرسال...')
                      : _text('Send report', 'إرسال البلاغ')),
                ),
              ],
              if (_message != null) ...[
                const SizedBox(height: 12),
                Semantics(
                  liveRegion: true,
                  child: Text(_message!, key: const Key('report-message')),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

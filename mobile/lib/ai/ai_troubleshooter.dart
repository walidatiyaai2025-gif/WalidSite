import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';
import '../runbooks/verified_online_runbooks.dart';
import '../theme/waleed_theme.dart';

class MobileAiHttpException implements Exception {
  const MobileAiHttpException(this.statusCode);
  final int statusCode;
}

class MobileAiSession {
  const MobileAiSession({required this.token, required this.expiresAt});
  final String token;
  final DateTime? expiresAt;
}

class MobileAiJob {
  const MobileAiJob({
    required this.id,
    required this.status,
    required this.progressPercent,
    required this.resultUrl,
    required this.retryAfterSeconds,
  });

  final String id;
  final String status;
  final int progressPercent;
  final String? resultUrl;
  final int? retryAfterSeconds;

  static final RegExp _uuid = RegExp(
    r'^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$',
  );

  static MobileAiJob parse(String raw) {
    if (raw.length > 128 * 1024) {
      throw const FormatException('Oversized diagnosis response');
    }
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid diagnosis response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    const allowed = {
      'id',
      'status',
      'progress_percent',
      'result_url',
      'retry_after_seconds',
    };
    if (data.length != allowed.length ||
        data.keys.toSet().difference(allowed).isNotEmpty) {
      throw const FormatException('Unexpected diagnosis fields');
    }

    final id = data['id'];
    final status = data['status'];
    final progress = data['progress_percent'];
    final resultUrl = data['result_url'];
    final retry = data['retry_after_seconds'];
    const statuses = {
      'queued',
      'running',
      'completed',
      'failed',
      'blocked',
      'cancelled',
    };
    if (id is! String ||
        !_uuid.hasMatch(id) ||
        status is! String ||
        !statuses.contains(status) ||
        progress is! int ||
        progress < 0 ||
        progress > 100 ||
        (resultUrl != null && resultUrl is! String) ||
        (retry != null && (retry is! int || retry < 0 || retry > 86400))) {
      throw const FormatException('Invalid diagnosis job');
    }
    if (status == 'completed' && progress != 100) {
      throw const FormatException('Incomplete completed job');
    }

    return MobileAiJob(
      id: id,
      status: status,
      progressPercent: progress,
      resultUrl: resultUrl as String?,
      retryAfterSeconds: retry as int?,
    );
  }

  String? reviewedRunbookId() {
    // A server-provided URL is never evidence that an unfinished or failed
    // diagnosis produced a reviewed, published solution.
    if (status != 'completed' || progressPercent != 100) return null;
    final raw = resultUrl;
    if (raw == null || raw.length > 120) return null;
    final match = RegExp(
      r'^/api/v1/mobile/solutions/([a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})$',
    ).firstMatch(raw);
    return match?.group(1);
  }
}

class MobileAiClient {
  const MobileAiClient();

  static const String apiOrigin =
      String.fromEnvironment('WALIDSITE_API_ORIGIN');
  static const int _maxResponseBytes = 1024 * 1024;

  static Uri? configuredOrigin() =>
      VerifiedArticleReader.validatedOrigin(apiOrigin);

  Future<MapEntry<int, String>> _jsonRequest(
    String method,
    String path, {
    Map<String, Object?>? body,
    String? bearer,
  }) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('API origin is not configured');
    }
    final url = origin.replace(path: path);
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.openUrl(method, url)
          .timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
      if (bearer != null) {
        request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $bearer');
      }
      if (body != null) {
        request.add(utf8.encode(jsonEncode(body)));
      }
      final response =
          await request.close().timeout(const Duration(seconds: 8));
      final bytes = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + chunk.length > _maxResponseBytes) {
          throw const FormatException('API response too large');
        }
        bytes.add(chunk);
      }
      final raw = utf8.decode(bytes.takeBytes(), allowMalformed: false);
      return MapEntry(response.statusCode, raw);
    } finally {
      client.close(force: true);
    }
  }

  Future<MobileAiSession> login(String email, String password) async {
    final safeEmail = email.trim();
    if (safeEmail.isEmpty ||
        safeEmail.length > 254 ||
        password.isEmpty ||
        password.length > 4096) {
      throw const FormatException('Invalid login input');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/auth/login',
      body: {
        'email': safeEmail,
        'password': password,
        'device_name': 'waleed-tech-companion',
      },
    );
    if (response.key != HttpStatus.ok) {
      throw MobileAiHttpException(response.key);
    }

    final decoded = jsonDecode(response.value);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid login response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    if (data.keys.toSet().difference({
          'token',
          'token_type',
          'expires_at',
        }).isNotEmpty ||
        data['token'] is! String ||
        data['token_type'] != 'Bearer' ||
        (data['token'] as String).isEmpty ||
        (data['token'] as String).length > 8192) {
      throw const FormatException('Invalid mobile token response');
    }
    final expiresRaw = data['expires_at'];
    final expires = expiresRaw == null
        ? null
        : expiresRaw is String
            ? DateTime.tryParse(expiresRaw)
            : null;
    if (expiresRaw != null && expires == null) {
      throw const FormatException('Invalid token expiry');
    }
    return MobileAiSession(
      token: data['token'] as String,
      expiresAt: expires?.toUtc(),
    );
  }

  Future<MobileAiJob> diagnose(
    String token, {
    required String question,
    required String technology,
    required String codeSnippet,
  }) async {
    if (token.isEmpty || token.length > 8192) {
      throw const FormatException('Invalid bearer token');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/mobile/ai/diagnose',
      bearer: token,
      body: {
        'question': question.trim(),
        'technology': technology.trim(),
        'code_snippet': codeSnippet,
        'consent_to_process': true,
      },
    );
    if (response.key != HttpStatus.ok) {
      throw MobileAiHttpException(response.key);
    }
    return MobileAiJob.parse(response.value);
  }

  /// Read-only status lookup. Never resubmits the prompt or reserves AI budget.
  Future<MobileAiJob> readJob(String token, String jobId) async {
    if (token.isEmpty ||
        token.length > 8192 ||
        !MobileAiJob._uuid.hasMatch(jobId)) {
      throw const FormatException('Invalid diagnosis job identity');
    }
    final response = await _jsonRequest(
      'GET',
      '/api/v1/mobile/ai/jobs/$jobId',
      bearer: token,
    );
    if (response.key != HttpStatus.ok) {
      throw MobileAiHttpException(response.key);
    }
    final job = MobileAiJob.parse(response.value);
    if (job.id.toLowerCase() != jobId.toLowerCase()) {
      throw const FormatException('Mismatched diagnosis job identity');
    }
    return job;
  }

  Future<void> saveRunbook(String token, String runbookId) async {
    if (token.isEmpty ||
        token.length > 8192 ||
        !MobileAiJob._uuid.hasMatch(runbookId)) {
      throw const FormatException('Invalid saved resource');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/mobile/saved',
      bearer: token,
      body: {
        'resource_type': 'runbook',
        'resource_id': runbookId,
      },
    );
    if (response.key != HttpStatus.ok) {
      throw MobileAiHttpException(response.key);
    }
    final decoded = jsonDecode(response.value);
    if (decoded is! Map<String, dynamic> ||
        decoded['data'] is! Map<String, dynamic> ||
        (decoded['data'] as Map<String, dynamic>)['saved'] != true ||
        (decoded['data'] as Map<String, dynamic>)['resource_id'] != runbookId) {
      throw const FormatException('Invalid save response');
    }
  }
}

typedef MobileLoginAction = Future<MobileAiSession> Function(
  String email,
  String password,
);
typedef MobileDiagnoseAction = Future<MobileAiJob> Function(
  String token,
  String question,
  String technology,
  String codeSnippet,
);
typedef MobileSaveAction = Future<void> Function(String token, String runbookId);
typedef MobileJobStatusAction = Future<MobileAiJob> Function(
  String token,
  String jobId,
);

class AiTroubleshooterScreen extends StatefulWidget {
  const AiTroubleshooterScreen({
    required this.arabic,
    this.loginAction,
    this.diagnoseAction,
    this.jobStatusAction,
    this.saveAction,
    this.detailLoader,
    this.nowUtc,
    super.key,
  });

  final bool arabic;
  final MobileLoginAction? loginAction;
  final MobileDiagnoseAction? diagnoseAction;
  final MobileJobStatusAction? jobStatusAction;
  final MobileSaveAction? saveAction;
  final Future<ReviewedRunbook> Function(String id)? detailLoader;
  /// Injectable clock for deterministic session-expiry regression tests.
  final DateTime Function()? nowUtc;

  @override
  State<AiTroubleshooterScreen> createState() =>
      _AiTroubleshooterScreenState();
}

class _AiTroubleshooterScreenState extends State<AiTroubleshooterScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _technology = TextEditingController();
  final _question = TextEditingController();
  final _code = TextEditingController();

  String? _token;
  DateTime? _expiresAt;
  bool _consent = false;
  bool _busyLogin = false;
  bool _busyDiagnosis = false;
  bool _busyPoll = false;
  bool _pollReady = true;
  Timer? _pollCooldown;
  bool _busySave = false;
  String? _message;
  MobileAiJob? _job;
  bool _saved = false;

  @override
  void dispose() {
    _pollCooldown?.cancel();
    _email.dispose();
    _password.dispose();
    _technology.dispose();
    _question.dispose();
    _code.dispose();
    super.dispose();
  }

  DateTime get _nowUtc => (widget.nowUtc?.call() ?? DateTime.now()).toUtc();

  String get _expiredSessionMessage => widget.arabic
      ? 'انتهت صلاحية الجلسة. سجّل الدخول مرة أخرى.'
      : 'Session expired. Sign in again.';

  bool _requireActiveSession() {
    if (_token == null) return false;
    if (_expiresAt == null || _expiresAt!.isAfter(_nowUtc)) return true;
    setState(() {
      _token = null;
      _expiresAt = null;
      _job = null;
      _resetPollCooldown();
      _saved = false;
      _consent = false;
      _message = _expiredSessionMessage;
    });
    return false;
  }

  bool get _isPending =>
      _job?.status == 'queued' || _job?.status == 'running';

  void _resetPollCooldown() {
    _pollCooldown?.cancel();
    _pollCooldown = null;
    _pollReady = true;
  }

  // Timer only unlocks the manual button. It NEVER sends a background request.
  void _respectPollDelay(MobileAiJob job) {
    _resetPollCooldown();
    if (job.status != 'queued' && job.status != 'running') return;
    final suggested = job.retryAfterSeconds ?? 30;
    final seconds = suggested < 1 ? 1 : (suggested > 3600 ? 3600 : suggested);
    _pollReady = false;
    _pollCooldown = Timer(Duration(seconds: seconds), () {
      if (mounted) setState(() => _pollReady = true);
    });
  }

  // The API status is deliberately machine-readable, but the progress card
  // must never expose untranslated state tokens to Arabic readers.
  String _statusLabel(String status) => switch (status) {
        'queued' => widget.arabic ? 'في الانتظار' : 'Queued',
        'running' => widget.arabic ? 'قيد التنفيذ' : 'Running',
        'completed' => widget.arabic ? 'مكتمل' : 'Completed',
        'failed' => widget.arabic ? 'فشل' : 'Failed',
        'blocked' => widget.arabic ? 'محظور' : 'Blocked',
        'cancelled' => widget.arabic ? 'ملغى' : 'Cancelled',
        _ => widget.arabic ? 'حالة غير معروفة' : 'Unknown status',
      };

  String _jobMessage(MobileAiJob job) => switch (job.status) {
        'completed' when job.reviewedRunbookId() != null => widget.arabic
            ? 'تم العثور على حل منشور تمت مراجعته.'
            : 'A reviewed published solution is ready.',
        'completed' => widget.arabic
            ? 'اكتمل التشخيص ولم يتم العثور على دليل مناسب.'
            : 'Diagnosis completed with no suitable reviewed guide.',
        'queued' || 'running' => widget.arabic
            ? 'الطلب قيد المعالجة. حدّث الحالة بعد المدة المقترحة دون إرسال تشخيص جديد.'
            : 'Diagnosis in progress. Refresh status after the advised delay without submitting again.',
        _ => widget.arabic
            ? 'تعذر إكمال التشخيص بأمان.'
            : 'The diagnosis could not complete safely.',
      };

  bool get _apiConfigured =>
      widget.loginAction != null ||
      widget.diagnoseAction != null ||
      MobileAiClient.configuredOrigin() != null;

  MobileLoginAction get _login => widget.loginAction ??
      (String email, String password) =>
          const MobileAiClient().login(email, password);

  MobileDiagnoseAction get _diagnose => widget.diagnoseAction ??
      (String token, String question, String technology, String codeSnippet) =>
          const MobileAiClient().diagnose(
            token,
            question: question,
            technology: technology,
            codeSnippet: codeSnippet,
          );

  MobileJobStatusAction get _readStatus => widget.jobStatusAction ??
      (String token, String jobId) =>
          const MobileAiClient().readJob(token, jobId);

  MobileSaveAction get _save => widget.saveAction ??
      (String token, String runbookId) =>
          const MobileAiClient().saveRunbook(token, runbookId);

  String _httpMessage(int status) {
    final ar = widget.arabic;
    return switch (status) {
      401 || 403 => ar
          ? 'انتهت الجلسة أو لا تملك صلاحية استخدام الأداة.'
          : 'Your session expired or is not authorized for this tool.',
      422 => ar
          ? 'تحقق من البيانات المدخلة ثم حاول مرة أخرى.'
          : 'Check the submitted fields and try again.',
      429 => ar
          ? 'تم بلوغ حد الاستخدام. حاول لاحقًا.'
          : 'Usage limit reached. Try again later.',
      503 => ar
          ? 'خدمة التشخيص غير متاحة حاليًا.'
          : 'AI diagnosis is currently unavailable.',
      _ => ar
          ? 'تعذر إكمال الطلب. تحقق من الاتصال وحاول مرة أخرى.'
          : 'Could not complete the request. Check your connection and try again.',
    };
  }

  String _genericError(Object error) {
    if (error is MobileAiHttpException) {
      return _httpMessage(error.statusCode);
    }
    return widget.arabic
        ? 'تعذر الاتصال بالخدمة بأمان. تحقق من الإعداد والاتصال.'
        : 'Could not reach the service safely. Check configuration and connectivity.';
  }

  Future<void> _signIn() async {
    if (_busyLogin) return;
    final email = _email.text.trim();
    final password = _password.text;
    if (email.isEmpty || password.isEmpty) {
      setState(() => _message = widget.arabic
          ? 'أدخل البريد الإلكتروني وكلمة المرور.'
          : 'Enter email and password.');
      return;
    }

    setState(() {
      _busyLogin = true;
      _message = null;
    });
    try {
      final session = await _login(email, password);
      if (!mounted) return;
      if (session.expiresAt != null && !session.expiresAt!.isAfter(_nowUtc)) {
        setState(() {
          _password.clear();
          _message = _expiredSessionMessage;
        });
        return;
      }
      setState(() {
        _token = session.token;
        _expiresAt = session.expiresAt;
        _password.clear();
        _message = widget.arabic
            ? 'تم تسجيل الدخول لهذه الجلسة فقط.'
            : 'Signed in for this session only.';
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _password.clear();
        _message = _genericError(error);
      });
    } finally {
      if (mounted) setState(() => _busyLogin = false);
    }
  }

  Future<void> _submit() async {
    // An in-flight job must be checked with GET, never resubmitted with POST.
    if (_busyDiagnosis || _busyPoll || _isPending || !_requireActiveSession()) {
      return;
    }
    final question = _question.text.trim();
    final technology = _technology.text.trim();
    if (!_consent) {
      setState(() => _message = widget.arabic
          ? 'يلزم الموافقة قبل إرسال البيانات للمعالجة.'
          : 'Consent is required before sending data for processing.');
      return;
    }
    if (question.length < 4 || technology.isEmpty) {
      setState(() => _message = widget.arabic
          ? 'أدخل التقنية وسؤالًا تقنيًا واضحًا.'
          : 'Enter a technology and a clear technical question.');
      return;
    }

    setState(() {
      _busyDiagnosis = true;
      _message = null;
      _job = null;
      _resetPollCooldown();
      _saved = false;
    });
    try {
      final job =
          await _diagnose(_token!, question, technology, _code.text);
      if (!mounted) return;
      setState(() {
        _job = job;
        _respectPollDelay(job);
        _message = _jobMessage(job);
      });
    } catch (error) {
      if (!mounted) return;
      if (error is MobileAiHttpException &&
          (error.statusCode == 401 || error.statusCode == 403)) {
        _token = null;
        _expiresAt = null;
        _job = null;
        _resetPollCooldown();
      }
      setState(() => _message = _genericError(error));
    } finally {
      if (mounted) setState(() => _busyDiagnosis = false);
    }
  }

  Future<void> _refreshStatus() async {
    if (_busyPoll || !_isPending || !_pollReady || !_requireActiveSession()) {
      return;
    }
    final oldJob = _job!;
    final token = _token!;
    setState(() {
      _busyPoll = true;
      _pollReady = false;
      _message = null;
    });
    try {
      final updated = await _readStatus(token, oldJob.id);
      if (!mounted) return;
      if (!_requireActiveSession()) return;
      // Never render a different user's or a different request's solution.
      if (updated.id.toLowerCase() != oldJob.id.toLowerCase()) {
        throw const FormatException('Mismatched diagnosis job identity');
      }
      setState(() {
        _job = updated;
        _respectPollDelay(updated);
        _message = _jobMessage(updated);
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        if (error is MobileAiHttpException &&
            (error.statusCode == 401 || error.statusCode == 403)) {
          _token = null;
          _expiresAt = null;
          _job = null;
          _consent = false;
          _resetPollCooldown();
        } else if (error is MobileAiHttpException &&
            error.statusCode == 404) {
          _job = null;
          _resetPollCooldown();
        } else {
          // Retain the pending job but throttle subsequent manual reads.
          _respectPollDelay(oldJob);
        }
        _message = error is MobileAiHttpException && error.statusCode == 404
            ? (widget.arabic
                ? 'لم تعد مهمة التشخيص متاحة. يمكنك بدء طلب جديد.'
                : 'This diagnosis job is no longer available. You may start again.')
            : _genericError(error);
      });
    } finally {
      if (mounted) setState(() => _busyPoll = false);
    }
  }

  Future<void> _saveResult() async {
    final id = _job?.reviewedRunbookId();
    if (_busySave || id == null || !_requireActiveSession()) return;
    setState(() {
      _busySave = true;
      _message = null;
    });
    try {
      await _save(_token!, id);
      if (!mounted) return;
      setState(() {
        _saved = true;
        _message = widget.arabic
            ? 'تم حفظ الدليل في حسابك.'
            : 'The reviewed guide was saved to your account.';
      });
    } catch (error) {
      if (!mounted) return;
      if (error is MobileAiHttpException &&
          (error.statusCode == 401 || error.statusCode == 403)) {
        _token = null;
        _expiresAt = null;
        _job = null;
      }
      setState(() => _message = _genericError(error));
    } finally {
      if (mounted) setState(() => _busySave = false);
    }
  }

  void _openSolution() {
    final id = _job?.reviewedRunbookId();
    if (id == null || !_requireActiveSession()) return;
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => ReviewedRunbookDetail(
        id: id,
        arabic: widget.arabic,
        loader: widget.detailLoader,
        initialBearer: _token,
        saveAction: (token, runbookId) => _save(token, runbookId),
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final ar = widget.arabic;
    final resultId = _job?.reviewedRunbookId();
    return Directionality(
      textDirection: ar ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        appBar: AppBar(
          title: Text(ar ? 'مساعد تشخيص الأعطال' : 'AI Troubleshooter'),
        ),
        body: SafeArea(
        child: !_apiConfigured
            ? Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    ar
                        ? 'خادم التشخيص الآمن غير مهيأ في هذا الإصدار.'
                        : 'The secure diagnosis server is not configured for this build.',
                    key: const Key('ai-unconfigured'),
                    textAlign: TextAlign.center,
                  ),
                ),
              )
            : ListView(
                padding: const EdgeInsets.all(20),
                children: [
                  Container(
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: WaleedPalette.border),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(
                          Icons.auto_awesome_outlined,
                          color: WaleedPalette.accent,
                          size: 34,
                        ),
                        const SizedBox(height: 12),
                        Text(
                          ar
                              ? 'تشخيص تقني يوجّهك فقط إلى أدلة منشورة ومراجعة.'
                              : 'Technical diagnosis that can only point to reviewed published guides.',
                          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: WaleedPalette.ink,
                              ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          ar
                              ? 'قد تُرسل بيانات السؤال والكود إلى مزود AI المهيأ من الإدارة. لا تُرسل أسرارًا أو بيانات شخصية.'
                              : 'Your question and code may be sent to the admin-configured AI provider. Do not submit secrets or personal data.',
                          style: const TextStyle(color: WaleedPalette.body),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (_token == null) ...[
                    Text(
                      ar ? 'تسجيل الدخول مطلوب' : 'Sign in required',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      key: const Key('ai-login-email'),
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
                      key: const Key('ai-login-password'),
                      controller: _password,
                      obscureText: true,
                      enableSuggestions: false,
                      autocorrect: false,
                      decoration: InputDecoration(
                        labelText: ar ? 'كلمة المرور' : 'Password',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 12),
                    FilledButton(
                      key: const Key('ai-login-submit'),
                      onPressed: _busyLogin ? null : _signIn,
                      child: Text(_busyLogin
                          ? (ar ? 'جارٍ تسجيل الدخول...' : 'Signing in...')
                          : (ar ? 'تسجيل الدخول' : 'Sign in')),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      ar
                          ? 'رمز الجلسة يبقى في الذاكرة فقط ولا يتم حفظ كلمة المرور.'
                          : 'The session token stays in memory only; the password is never saved.',
                      key: const Key('ai-session-privacy'),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ] else ...[
                    TextField(
                      key: const Key('ai-technology'),
                      controller: _technology,
                      maxLength: 120,
                      decoration: InputDecoration(
                        labelText: ar ? 'التقنية أو المنصة' : 'Technology or stack',
                        hintText: ar ? 'مثال: SQL Server' : 'Example: SQL Server',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 8),
                    TextField(
                      key: const Key('ai-question'),
                      controller: _question,
                      maxLength: 2000,
                      minLines: 3,
                      maxLines: 7,
                      decoration: InputDecoration(
                        labelText: ar ? 'صف المشكلة' : 'Describe the issue',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 8),
                    TextField(
                      key: const Key('ai-code'),
                      controller: _code,
                      maxLength: 8000,
                      minLines: 4,
                      maxLines: 10,
                      autocorrect: false,
                      enableSuggestions: false,
                      decoration: InputDecoration(
                        labelText: ar
                            ? 'مقتطف الكود أو الخطأ (اختياري)'
                            : 'Code or error snippet (optional)',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    CheckboxListTile(
                      key: const Key('ai-consent'),
                      value: _consent,
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      onChanged: _busyDiagnosis
                          ? null
                          : (value) =>
                              setState(() => _consent = value == true),
                      title: Text(ar
                          ? 'أوافق على معالجة هذه البيانات للتشخيص.'
                          : 'I consent to processing this data for diagnosis.'),
                      subtitle: Text(ar
                          ? 'لا ترسل كلمات مرور أو مفاتيح API أو معلومات شخصية.'
                          : 'Do not submit passwords, API keys, or personal information.'),
                    ),
                    const SizedBox(height: 8),
                    FilledButton.icon(
                      key: const Key('ai-diagnose-submit'),
                      onPressed: _busyDiagnosis || _busyPoll || _isPending
                          ? null : _submit,
                      icon: const Icon(Icons.auto_awesome),
                      label: Text(_busyDiagnosis
                          ? (ar ? 'جارٍ التشخيص...' : 'Diagnosing...')
                          : (ar ? 'ابدأ التشخيص' : 'Diagnose')),
                    ),
                  ],
                  if (_message != null) ...[
                    const SizedBox(height: 16),
                    Semantics(
                      liveRegion: true,
                      child: Text(
                        _message!,
                        key: const Key('ai-message'),
                      ),
                    ),
                  ],
                  if (_job != null) ...[
                    const SizedBox(height: 16),
                    Card(
                      key: const Key('ai-job-card'),
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              ar ? 'حالة التشخيص' : 'Diagnosis status',
                              style: const TextStyle(fontWeight: FontWeight.w700),
                            ),
                            const SizedBox(height: 8),
                            LinearProgressIndicator(
                              value: _job!.progressPercent / 100,
                              minHeight: 6,
                            ),
                            const SizedBox(height: 8),
                            Text(
                              '${_statusLabel(_job!.status)} · ${_job!.progressPercent}%',
                              key: const Key('ai-job-status'),
                            ),
                            if (_job!.retryAfterSeconds != null)
                              Text(ar
                                  ? 'إعادة المحاولة بعد نحو ${_job!.retryAfterSeconds} ثانية.'
                                  : 'Retry after about ${_job!.retryAfterSeconds} seconds.'),
                            if (_isPending) ...[
                              const SizedBox(height: 12),
                              Text(ar
                                  ? 'التحقق من الحالة فقط لا يعيد إرسال السؤال أو يستهلك طلب AI جديدًا.'
                                  : 'Checking status does not resend your question or start another AI request.'),
                              const SizedBox(height: 8),
                              OutlinedButton.icon(
                                key: const Key('ai-refresh-status'),
                                onPressed: _busyPoll || !_pollReady
                                    ? null
                                    : _refreshStatus,
                                icon: const Icon(Icons.refresh),
                                label: Text(_busyPoll
                                    ? (ar ? 'جارٍ التحديث...' : 'Checking status...')
                                    : (ar ? 'تحديث حالة التشخيص' : 'Refresh diagnosis status')),
                              ),
                              if (!_pollReady && !_busyPoll)
                                Text(
                                  ar
                                      ? 'يمكن التحديث بعد فترة الانتظار المحددة؛ لا يُرسل طلب تلقائي.'
                                      : 'Wait for the advised interval before refreshing. No automatic request is sent.',
                                  key: const Key('ai-poll-wait'),
                                ),
                            ],
                            if (resultId != null) ...[
                              const SizedBox(height: 12),
                              FilledButton.icon(
                                key: const Key('ai-open-solution'),
                                onPressed: _openSolution,
                                icon: const Icon(Icons.fact_check_outlined),
                                label: Text(ar
                                    ? 'افتح الحل المراجع'
                                    : 'Open reviewed solution'),
                              ),
                              const SizedBox(height: 8),
                              OutlinedButton.icon(
                                key: const Key('ai-save-answer'),
                                onPressed:
                                    _busySave || _saved ? null : _saveResult,
                                icon: Icon(_saved
                                    ? Icons.bookmark_added_outlined
                                    : Icons.bookmark_add_outlined),
                                label: Text(_saved
                                    ? (ar ? 'تم الحفظ' : 'Saved')
                                    : _busySave
                                        ? (ar ? 'جارٍ الحفظ...' : 'Saving...')
                                        : (ar
                                            ? 'حفظ الحل'
                                            : 'Save answer')),
                              ),
                            ],
                            const SizedBox(height: 8),
                            Tooltip(
                              message: ar
                                  ? 'لا توجد واجهة إبلاغ بالخادم حتى الآن.'
                                  : 'No server-side reporting endpoint is implemented yet.',
                              child: OutlinedButton.icon(
                                key: const Key('ai-report-error-disabled'),
                                onPressed: null,
                                icon: const Icon(Icons.report_gmailerrorred_outlined),
                                label: Text(ar
                                    ? 'الإبلاغ غير متاح حاليًا'
                                    : 'Report error unavailable'),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ],
              ),
        ),
      ),
    );
  }
}

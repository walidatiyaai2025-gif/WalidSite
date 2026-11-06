import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';
import 'diagnosis_report.dart';
import 'mobile_ai_paid_intent.dart';
import '../runbooks/verified_online_runbooks.dart';
import '../runbooks/offline_runbooks.dart';
import '../solutions/offline_solution_finder.dart';
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

  /// Validate a user-held job reference before any authenticated status GET.
  static bool isValidId(String value) => _uuid.hasMatch(value);

  static MobileAiJob parse(String raw) {
    // A decoded Dart string can represent far more UTF-8 bytes than its
    // length implies. Enforce the authenticated status payload budget before
    // jsonDecode, including direct callers outside the bounded HTTP reader.
    if (raw.length > 128 * 1024 ||
        utf8.encode(raw).length > 128 * 1024) {
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

  /// One overall deadline for the entire authenticated HTTP exchange.
  /// Individual read timeouts reset after each small chunk and permit
  /// indefinitely slow streams; this deadline does not.
  @visibleForTesting
  static Future<T> withinDeadline<T>(
    Future<T> Function() operation, {
    Duration timeout = const Duration(seconds: 12),
  }) => Future<T>.sync(operation).timeout(timeout);

  static Uri? configuredOrigin() =>
      VerifiedArticleReader.validatedOrigin(apiOrigin);

  Future<MapEntry<int, String>> _jsonRequest(
    String method,
    String path, {
    Map<String, Object?>? body,
    String? bearer,
    String? idempotencyKey,
  }) async {
    final origin = configuredOrigin();
    if (origin == null) {
      throw const FormatException('API origin is not configured');
    }
    // This path always uses the build-time, HTTPS-validated origin. Neither
    // user text nor a server response may redirect an authenticated request.
    return boundedExchange(
      method,
      origin.replace(path: path),
      body: body,
      bearer: bearer,
      idempotencyKey: idempotencyKey,
    );
  }

  /// The actual production HTTP transport, exposed for loopback fixture
  /// verification of streaming and hanging-header deadlines. Only _jsonRequest
  /// selects the production URL, after the canonical HTTPS origin validator.
  /// No runtime UI or saved preference can change [url] or [totalTimeout].
  @visibleForTesting
  static Future<MapEntry<int, String>> boundedExchange(
    String method,
    Uri url, {
    Map<String, Object?>? body,
    String? bearer,
    String? idempotencyKey,
    Duration totalTimeout = const Duration(seconds: 12),
  }) async {
    if (idempotencyKey != null && !MobileAiIntentKey.isValid(idempotencyKey)) {
      throw const FormatException('Invalid paid diagnosis intent key');
    }
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      return await withinDeadline(() async {
        final request = await client.openUrl(method, url);
        request.followRedirects = false;
        request.headers.set(HttpHeaders.acceptHeader, 'application/json');
        request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
        if (bearer != null) {
          request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $bearer');
        }
        if (idempotencyKey != null) {
          request.headers.set('Idempotency-Key', idempotencyKey);
        }
        if (body != null) {
          request.add(utf8.encode(jsonEncode(body)));
        }
        final response = await request.close();
        final bytes = BytesBuilder(copy: false);
        await for (final chunk in response) {
          if (bytes.length + chunk.length > _maxResponseBytes) {
            throw const FormatException('API response too large');
          }
          bytes.add(chunk);
        }
        final raw = utf8.decode(bytes.takeBytes(), allowMalformed: false);
        return MapEntry(response.statusCode, raw);
      }, timeout: totalTimeout);
    } finally {
      // Also abort a slow-drip HTTP stream when the total deadline expires.
      // Never allow an expired private request to keep its socket alive.
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

    return parseLoginResponse(response.value);
  }

  /// Enforce the exact bearer-login identity before any private AI or Save
  /// action receives a session. This method is also used by real transport.
  @visibleForTesting
  static MobileAiSession parseLoginResponse(String raw) {
    if (raw.length > 16384 || utf8.encode(raw).length > 16384) {
      throw const FormatException('Invalid login response size');
    }
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid login response');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    final bearer = data['token'];
    if (data.length != 3 ||
        data.keys.toSet().difference({'token', 'token_type', 'expires_at'}).isNotEmpty ||
        bearer is! String || bearer.isEmpty || bearer.length > 8192 ||
        // Sanctum tokens are printable ASCII, not newline/header directives.
        !RegExp(r'^[!-~]+$').hasMatch(bearer) ||
        data['token_type'] != 'Bearer') {
      throw const FormatException('Invalid mobile token response');
    }
    final expiresRaw = data['expires_at'];
    DateTime? expires;
    if (expiresRaw != null) {
      if (expiresRaw is! String || expiresRaw.length > 50) {
        throw const FormatException('Invalid token expiry');
      }
      // DateTime.parse normalizes invalid calendar values (e.g. Feb 30).
      // Require complete UTC-aware ISO-8601, then validate the calendar.
      final stamp = RegExp(
        r'^([0-9]{4})-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])'
        r'T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]'
        r'(?:[.][0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$',
      ).firstMatch(expiresRaw);
      if (stamp == null) {
        throw const FormatException('Invalid token expiry');
      }
      final year = int.parse(stamp.group(1)!);
      final month = int.parse(stamp.group(2)!);
      final day = int.parse(stamp.group(3)!);
      final calendar = DateTime.utc(year, month, day);
      if (calendar.year != year || calendar.month != month ||
          calendar.day != day) {
        throw const FormatException('Invalid token expiry');
      }
      expires = DateTime.tryParse(expiresRaw);
      if (expires == null || !expires.isUtc ||
          !expires.isAfter(DateTime.now().toUtc())) {
        throw const FormatException('Invalid token expiry');
      }
    }
    return MobileAiSession(token: bearer, expiresAt: expires);
  }

  Future<MobileAiJob> diagnose(
    String token, {
    required String question,
    required String technology,
    required String codeSnippet,
    required bool consentToProcess,
    String? idempotencyKey,
  }) async {
    // Never assert consent in the HTTP adapter on a caller's behalf.
    // A direct caller must supply affirmative per-request consent.
    if (!consentToProcess) {
      throw const FormatException('Explicit diagnosis consent required');
    }
    if (token.isEmpty || token.length > 8192) {
      throw const FormatException('Invalid bearer token');
    }
    // The production client never transmits an unkeyed potentially paid POST.
    if (idempotencyKey == null || !MobileAiIntentKey.isValid(idempotencyKey)) {
      throw const FormatException('A stable paid diagnosis key is required');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/mobile/ai/diagnose',
      bearer: token,
      idempotencyKey: idempotencyKey,
      body: {
        'question': question.trim(),
        'technology': technology.trim(),
        'code_snippet': codeSnippet,
        'consent_to_process': consentToProcess,
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
        !MobileAiJob.isValidId(jobId)) {
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
    verifyRunbookSavedResponse(response.value, runbookId);
  }

  /// A 200 response proves no Save until the server acknowledges the exact
  /// typed published runbook identity with no unexpected response fields.
  @visibleForTesting
  static void verifyRunbookSavedResponse(String raw, String runbookId) {
    if (!MobileAiJob.isValidId(runbookId) ||
        raw.length > 16384 || utf8.encode(raw).length > 16384) {
      throw const FormatException('Invalid runbook save receipt');
    }
    final decoded = jsonDecode(raw);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic>) {
      throw const FormatException('Invalid runbook save receipt');
    }
    final data = decoded['data'] as Map<String, dynamic>;
    if (data.length != 3 ||
        data.keys.toSet().difference({'resource_type', 'resource_id', 'saved'}).isNotEmpty ||
        data['resource_type'] != 'runbook' ||
        data['resource_id'] != runbookId ||
        data['saved'] != true) {
      throw const FormatException('Invalid runbook save receipt');
    }
  }

  /// Revoke this exact bearer. Network failure must not be reported as
  /// successful server-side revocation.
  Future<void> logout(String token) async {
    if (token.isEmpty || token.length > 8192) {
      throw const FormatException('Invalid bearer token');
    }
    final response = await _jsonRequest(
      'POST',
      '/api/v1/auth/logout',
      bearer: token,
    );
    if (response.key != HttpStatus.ok) {
      throw MobileAiHttpException(response.key);
    }
    final decoded = jsonDecode(response.value);
    if (decoded is! Map<String, dynamic> ||
        decoded.length != 1 ||
        decoded['data'] is! Map<String, dynamic> ||
        (decoded['data'] as Map<String, dynamic>).length != 1 ||
        (decoded['data'] as Map<String, dynamic>)['revoked'] != true) {
      throw const FormatException('Unverified logout response');
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
typedef MobileKeyedDiagnoseAction = Future<MobileAiJob> Function(
  String token,
  String question,
  String technology,
  String codeSnippet,
  String idempotencyKey,
);
typedef MobileSaveAction = Future<void> Function(String token, String runbookId);
typedef MobileLogoutAction = Future<void> Function(String token);
typedef MobileJobStatusAction = Future<MobileAiJob> Function(
  String token,
  String jobId,
);

class AiTroubleshooterScreen extends StatefulWidget {
  const AiTroubleshooterScreen({
    required this.arabic,
    this.loginAction,
    this.logoutAction,
    this.diagnoseAction,
    this.keyedDiagnoseAction,
    this.intentStore,
    this.jobStatusAction,
    this.saveAction,
    this.reportAction,
    this.detailLoader,
    this.offlinePack,
    this.nowUtc,
    super.key,
  });

  final bool arabic;
  final MobileLoginAction? loginAction;
  final MobileLogoutAction? logoutAction;
  final MobileDiagnoseAction? diagnoseAction;
  /// Real keyed-POST fixture; the original four-argument callback stays for
  /// backwards-compatible widget fixtures without a live paid API.
  final MobileKeyedDiagnoseAction? keyedDiagnoseAction;
  final MobileAiIntentStore? intentStore;
  final MobileJobStatusAction? jobStatusAction;
  final MobileSaveAction? saveAction;
  final DiagnosisReportAction? reportAction;
  final Future<ReviewedRunbook> Function(String id)? detailLoader;
  /// The production route loads verified signed snapshots (or warns about
  /// bundled educational content). Tests may supply a deterministic pack.
  final Future<OfflineRunbookPack>? offlinePack;
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
  final _resumeId = TextEditingController();

  String? _token;
  DateTime? _expiresAt;
  bool _consent = false;
  bool _busyLogin = false;
  bool _busyLogout = false;
  bool _busyDiagnosis = false;
  bool _busyPoll = false;
  bool _pollReady = true;
  Timer? _pollCooldown;
  bool _busySave = false;
  String? _message;
  MobileAiJob? _job;
  bool _saved = false;
  String? _accountEmail;
  String? _attemptKey;
  MobileAiPaidAttempt? _attemptSnapshot;
  Future<void>? _pendingClear;
  final MobileAiIntentStore _fixtureIntentStore = MemoryMobileAiIntentStore();

  MobileAiIntentStore get _intentStore => widget.intentStore ??
      (widget.loginAction != null ||
              widget.diagnoseAction != null ||
              widget.keyedDiagnoseAction != null
          ? _fixtureIntentStore
          : const FileMobileAiIntentStore());

  @override
  void dispose() {
    _pollCooldown?.cancel();
    _email.dispose();
    _password.dispose();
    _technology.dispose();
    _question.dispose();
    _code.dispose();
    _resumeId.dispose();
    super.dispose();
  }

  DateTime get _nowUtc => (widget.nowUtc?.call() ?? DateTime.now()).toUtc();

  String get _expiredSessionMessage => widget.arabic
      ? 'انتهت صلاحية الجلسة. سجّل الدخول مرة أخرى.'
      : 'Session expired. Sign in again.';

  /// Clear every piece of account-bound diagnosis state on ANY session loss,
  /// not only on explicit logout. Controllers survive the unauthenticated
  /// form and would otherwise reveal a previous user's code after re-login.
  /// Caller owns setState so this also works inside an async error handler.
  void _scrubPrivateSession() {
    final email = _accountEmail;
    if (email != null) {
      // No raw account, prompt, bearer or question survives session loss.
      _pendingClear = _intentStore.clearKey(email).catchError((Object _) {});
    }
    _accountEmail = null;
    _attemptKey = null;
    _attemptSnapshot = null;
    _token = null;
    _expiresAt = null;
    _job = null;
    _saved = false;
    _consent = false;
    _resetPollCooldown();
    _email.clear();
    _password.clear();
    _technology.clear();
    _question.clear();
    _code.clear();
    _resumeId.clear();
  }

  bool _requireActiveSession() {
    if (_token == null) return false;
    if (_expiresAt == null || _expiresAt!.isAfter(_nowUtc)) return true;
    setState(() {
      _scrubPrivateSession();
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

  MobileLogoutAction get _logout => widget.logoutAction ??
      (String token) => const MobileAiClient().logout(token);

  MobileLoginAction get _login => widget.loginAction ??
      (String email, String password) =>
          const MobileAiClient().login(email, password);

  MobileKeyedDiagnoseAction get _keyedDiagnose =>
      widget.keyedDiagnoseAction ??
      (String token, String question, String technology,
          String codeSnippet, String key) {
        final fixture = widget.diagnoseAction;
        if (fixture != null) {
          // The legacy test injection never creates a real paid HTTP request.
          return fixture(token, question, technology, codeSnippet);
        }
        return const MobileAiClient().diagnose(
          token,
          question: question,
          technology: technology,
          codeSnippet: codeSnippet,
          consentToProcess: _consent,
          idempotencyKey: key,
        );
      };

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
    if (error is TimeoutException) {
      // A timed-out manual GET or login is not a configuration failure.
      // Never retry here: paid POST uncertainty has separate key-based
      // recovery, and job status remains a user-initiated read-only GET.
      return widget.arabic
          ? 'انتهت مهلة الاتصال. تحقق من الشبكة ثم أعد المحاولة يدويًا؛ لم تُرسل محاولة تلقائية.'
          : 'The request timed out. Check your connection and retry manually; no automatic retry was sent.';
    }
    return widget.arabic
        ? 'تعذر الاتصال بالخدمة بأمان. تحقق من الإعداد والاتصال.'
        : 'Could not reach the service safely. Check configuration and connectivity.';
  }

  Future<void> _signIn() async {
    if (_busyLogin || _busyLogout) return;
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
      // Read only an opaque key from app-private storage. Never restore
      // prompt/code or a previous login bearer after app restart.
      if (_pendingClear != null) await _pendingClear;
      final retainedKey = await _intentStore.readKey(email);
      if (!mounted) return;
      setState(() {
        _token = session.token;
        _expiresAt = session.expiresAt;
        _accountEmail = email.toLowerCase();
        _attemptKey = retainedKey;
        _attemptSnapshot = null;
        _password.clear();
        _message = retainedKey == null
            ? (widget.arabic
                ? 'تم تسجيل الدخول لهذه الجلسة فقط.'
                : 'Signed in for this session only.')
            : (widget.arabic
                ? 'قد يكون طلب تشخيص سابق قد بدأ. أدخل نفس السؤال والبيانات ثم أعد المحاولة بنفس المفتاح؛ لن ننشئ طلبًا مدفوعًا جديدًا تلقائيًا.'
                : 'An earlier diagnosis may have been accepted. Re-enter exactly the same request to recover it with the same key; no new paid request will be started automatically.');
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

  /// Private inputs leave local UI immediately, even on a network failure.
  /// In-flight status responses cannot resurrect a discarded bearer session.
  Future<void> _signOut() async {
    if (_busyLogout || _token == null) return;
    final token = _token!;
    setState(() {
      _busyLogout = true;
      _scrubPrivateSession();
      _message = widget.arabic
          ? 'جارٍ إلغاء الجلسة على الخادم...'
          : 'Revoking the session on the server...';
    });
    try {
      await _logout(token);
      if (!mounted) return;
      setState(() => _message = widget.arabic
          ? 'تم تسجيل الخروج وإلغاء صلاحية الجلسة.'
          : 'Signed out and the session was revoked.');
    } catch (_) {
      if (!mounted) return;
      setState(() => _message = widget.arabic
          ? 'تم مسح الجلسة من الجهاز، لكن لم يمكن تأكيد إلغائها على الخادم.'
          : 'Signed out on this device; server revocation could not be confirmed.');
    } finally {
      if (mounted) setState(() => _busyLogout = false);
    }
  }

  Future<void> _submit() async {
    // A pending job uses GET only. A lost POST acknowledgement is different:
    // the only permitted manual POST retry reuses its original opaque key.
    if (_busyDiagnosis || _busyPoll || _busySave || _isPending ||
        !_requireActiveSession()) return;
    if (!_consent) {
      setState(() => _message = widget.arabic
          ? 'يلزم الموافقة قبل إرسال البيانات للمعالجة.'
          : 'Consent is required before sending data for processing.');
      return;
    }
    final question = _question.text.trim();
    final technology = _technology.text.trim();
    if (_attemptSnapshot == null &&
        (question.length < 4 || technology.isEmpty)) {
      setState(() => _message = widget.arabic
          ? 'أدخل التقنية وسؤالًا تقنيًا واضحًا.'
          : 'Enter a technology and a clear technical question.');
      return;
    }

    final requestToken = _token!;
    final accountEmail = _accountEmail;
    if (accountEmail == null) return;
    var sent = false;
    setState(() {
      _busyDiagnosis = true;
      _message = null;
      _job = null;
      _resetPollCooldown();
      _saved = false;
    });

    try {
      final attempt = _attemptSnapshot ?? MobileAiPaidAttempt(
        key: _attemptKey ?? MobileAiIntentKey.generate(),
        question: question,
        technology: technology,
        codeSnippet: _code.text,
      );
      // Fail closed on storage failure: never send a potentially charged POST
      // without retaining the same key for crash/lost-ACK recovery.
      if (_attemptKey == null) {
        await _intentStore.saveKey(accountEmail, attempt.key);
      }
      if (!mounted || _token != requestToken) return;
      _attemptKey = attempt.key;
      _attemptSnapshot = attempt;
      sent = true;
      final job = await _keyedDiagnose(
        requestToken,
        attempt.question,
        attempt.technology,
        attempt.codeSnippet,
        attempt.key,
      );
      if (!mounted || !_requireActiveSession() ||
          _token != requestToken) return;
      try {
        await _intentStore.clearKey(accountEmail);
      } catch (_) {
        // A stale recovery key is safer than losing a paid admission.
        // The job has an authenticated server ID and can be read using GET.
      }
      if (!mounted || _token != requestToken) return;
      setState(() {
        _attemptKey = null;
        _attemptSnapshot = null;
        _job = job;
        _respectPollDelay(job);
        _message = _jobMessage(job);
      });
    } catch (error) {
      if (!mounted || !_requireActiveSession() ||
          _token != requestToken) return;
      if (error is MobileAiHttpException &&
          (error.statusCode == 401 || error.statusCode == 403)) {
        setState(() {
          _scrubPrivateSession();
          _message = _httpMessage(error.statusCode);
        });
        return;
      }
      setState(() {
        if (!sent) {
          _message = widget.arabic
              ? 'تعذر حفظ مفتاح الاسترداد بأمان، ولم يتم إرسال طلب التشخيص.'
              : 'Could not securely save the recovery key. No diagnosis was sent.';
        } else if (error is MobileAiHttpException &&
            error.statusCode == 409) {
          _message = widget.arabic
              ? 'لا تطابق بيانات الطلب المحاولة السابقة. أعد إدخال نفس البيانات؛ لن يُنشأ تشخيص مدفوع آخر.'
              : 'This request differs from the previous attempt. Re-enter the original input; no additional paid diagnosis was created.';
        } else if (error is MobileAiHttpException &&
            (error.statusCode == 429 ||
             error.statusCode == 422 ||
             error.statusCode == 503)) {
          // Preserve actionable quota/provider/validation status messages,
          // while the pending key still prevents a fresh paid admission.
          _message = _httpMessage(error.statusCode);
        } else {
          _message = widget.arabic
              ? 'حالة الطلب غير مؤكدة. قد يكون الخادم قد قبله. أعد المحاولة بنفس المفتاح فقط؛ لا يتم إرسال تشخيص جديد تلقائيًا.'
              : 'Diagnosis outcome is unknown; the server may have accepted it. Only a manual retry with the SAME key is allowed. No new paid request is sent automatically.';
        }
      });
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
      if (!mounted || _token != token || _job?.id != oldJob.id) return;
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
      if (!mounted || _token != token || _job?.id != oldJob.id) return;
      setState(() {
        if (error is MobileAiHttpException &&
            (error.statusCode == 401 || error.statusCode == 403)) {
          _scrubPrivateSession();
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

  /// Recover a previously issued job after navigation/network loss. The ID
  /// alone conveys no access: the backend still checks the current bearer.
  /// No prompt is replayed and no AI budget is reserved by this GET.
  Future<void> _resumeExistingJob() async {
    if (_busyDiagnosis || _busyPoll || _busySave || _job != null ||
        !_requireActiveSession()) return;
    final id = _resumeId.text.trim();
    if (!MobileAiJob.isValidId(id)) {
      setState(() => _message = widget.arabic
          ? 'أدخل معرّف مهمة صالحًا من 36 حرفًا.'
          : 'Enter a valid 36-character diagnosis job ID.');
      return;
    }
    final token = _token!;
    setState(() {
      _busyPoll = true;
      _message = null;
    });
    try {
      final job = await _readStatus(token, id);
      if (!mounted || _token != token || !_requireActiveSession()) return;
      if (job.id.toLowerCase() != id.toLowerCase()) {
        throw const FormatException('Mismatched diagnosis job identity');
      }
      setState(() {
        _job = job;
        _saved = false;
        _resumeId.clear();
        _respectPollDelay(job);
        _message = _jobMessage(job);
      });
    } catch (error) {
      if (!mounted || _token != token) return;
      setState(() {
        if (error is MobileAiHttpException &&
            (error.statusCode == 401 || error.statusCode == 403)) {
          _scrubPrivateSession();
        }
        _message = error is MobileAiHttpException && error.statusCode == 404
            ? (widget.arabic
                ? 'المهمة غير متاحة أو انتهت صلاحيتها. ابدأ تشخيصًا جديدًا.'
                : 'Job not available or expired. Start a new diagnosis.')
            : _genericError(error);
      });
    } finally {
      if (mounted) setState(() => _busyPoll = false);
    }
  }

  Future<void> _saveResult() async {
    final id = _job?.reviewedRunbookId();
    if (_busySave || id == null || !_requireActiveSession()) return;
    final requestToken = _token!;
    setState(() {
      _busySave = true;
      _message = null;
    });
    try {
      await _save(requestToken, id);
      if (!mounted || !_requireActiveSession() || _token != requestToken ||
          _job?.reviewedRunbookId() != id) {
        return;
      }
      setState(() {
        _saved = true;
        _message = widget.arabic
            ? 'تم حفظ الدليل في حسابك.'
            : 'The reviewed guide was saved to your account.';
      });
    } catch (error) {
      if (!mounted || !_requireActiveSession() || _token != requestToken ||
          _job?.reviewedRunbookId() != id) {
        return;
      }
      if (error is MobileAiHttpException &&
          (error.statusCode == 401 || error.statusCode == 403)) {
        _scrubPrivateSession();
      }
      setState(() => _message = _genericError(error));
    } finally {
      if (mounted) setState(() => _busySave = false);
    }
  }

  void _openReport() {
    if (_job == null) return;
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => MobileDiagnosisReportScreen(
        arabic: widget.arabic,
        initialEmail: _email.text.trim(),
        submitAction: widget.reportAction,
      ),
    ));
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

  // Never pass question, code, bearer or AI result into the local finder.
  // The user must initiate their own offline keyword search.
  void _openOfflineRecovery() {
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => Directionality(
        textDirection: widget.arabic ? TextDirection.rtl : TextDirection.ltr,
        child: OfflineSolutionFinder(
          arabic: widget.arabic,
          bundledPack: widget.offlinePack,
        ),
      ),
    ));
  }

  void _openReviewedCatalog() {
    if (ReviewedRunbookReader.configuredOrigin() == null) return;
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => Directionality(
        textDirection: widget.arabic ? TextDirection.rtl : TextDirection.ltr,
        child: ReviewedRunbookLibrary(arabic: widget.arabic),
      ),
    ));
  }

  Widget _recoveryActions() {
    final ar = widget.arabic;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          ar
              ? 'يمكنك البحث يدويًا في الأدلة المحلية دون إرسال السؤال أو الكود إلى أي خادم. النتائج النصية ليست تشخيصًا بالذكاء الاصطناعي.'
              : 'Search offline guides manually without sending your question or code to a server. Keyword matches are not AI diagnoses.',
          key: const Key('ai-recovery-disclaimer'),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          key: const Key('ai-offline-recovery'),
          onPressed: _openOfflineRecovery,
          icon: const Icon(Icons.offline_bolt_outlined),
          label: Text(ar ? 'ابحث في الأدلة المحلية' : 'Search offline guides'),
        ),
        if (ReviewedRunbookReader.configuredOrigin() != null) ...[
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const Key('ai-reviewed-catalog'),
            onPressed: _openReviewedCatalog,
            icon: const Icon(Icons.fact_check_outlined),
            label: Text(ar ? 'تصفح الأدلة المنشورة المراجعة' : 'Browse reviewed online guides'),
          ),
        ],
      ],
    );
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
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        ar
                            ? 'خادم التشخيص الآمن غير مهيأ في هذا الإصدار.'
                            : 'The secure diagnosis server is not configured for this build.',
                        key: const Key('ai-unconfigured'),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 20),
                      _recoveryActions(),
                    ],
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
                      onPressed: _busyLogin || _busyLogout ? null : _signIn,
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
                    Align(
                      alignment: AlignmentDirectional.centerEnd,
                      child: OutlinedButton.icon(
                        key: const Key('ai-sign-out'),
                        onPressed: _busyLogout ? null : _signOut,
                        icon: const Icon(Icons.logout),
                        label: Text(ar ? 'تسجيل الخروج' : 'Sign out'),
                      ),
                    ),
                    const SizedBox(height: 8),
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
                      onPressed: _busyDiagnosis || _busyPoll || _busySave || _isPending
                          ? null : _submit,
                      icon: const Icon(Icons.auto_awesome),
                      label: Text(_busyDiagnosis
                          ? (ar ? 'جارٍ التشخيص...' : 'Diagnosing...')
                          : _attemptKey != null
                              ? (ar ? 'استرداد التشخيص السابق' : 'Recover previous diagnosis')
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
                  if (_message != null || (_job != null && !_isPending && resultId == null)) ...[
                    const SizedBox(height: 16),
                    _recoveryActions(),
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
                            OutlinedButton.icon(
                              key: const Key('ai-report-error'),
                              onPressed: _openReport,
                              icon: const Icon(Icons.report_gmailerrorred_outlined),
                              label: Text(ar
                                  ? 'الإبلاغ عن مشكلة التشخيص'
                                  : 'Report a diagnosis issue'),
                            ),
                            Text(ar
                                ? 'احتفظ بمعرّف المهمة لاسترجاعها بعد تسجيل الدخول مرة أخرى:'
                                : 'Keep this ID to resume after signing in again:'),
                            SelectableText(
                              _job!.id,
                              key: const Key('ai-job-reference'),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                  if (_token != null && _job == null) ...[
                    const SizedBox(height: 12),
                    Text(ar
                        ? 'استرجاع تشخيص سابق (يتطلب صلاحية حسابك)'
                        : 'Resume an earlier diagnosis (account access required)'),
                    const SizedBox(height: 8),
                    TextField(
                      key: const Key('ai-resume-id'),
                      controller: _resumeId,
                      maxLength: 36,
                      autocorrect: false,
                      enableSuggestions: false,
                      decoration: InputDecoration(
                        labelText: ar ? 'معرّف مهمة التشخيص' : 'Diagnosis job ID',
                        border: const OutlineInputBorder(),
                      ),
                    ),
                    OutlinedButton.icon(
                      key: const Key('ai-resume-submit'),
                      onPressed: _busyDiagnosis || _busyPoll || _busySave ||
                              _job != null ? null : _resumeExistingJob,
                      icon: const Icon(Icons.history),
                      label: Text(_busyPoll
                          ? (ar ? 'جارٍ الاسترجاع...' : 'Restoring...')
                          : (ar ? 'استرجاع الحالة' : 'Restore status')),
                    ),
                  ],
                ],
              ),
        ),
      ),
    );
  }
}

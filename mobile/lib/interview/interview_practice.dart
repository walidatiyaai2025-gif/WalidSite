import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:flutter/material.dart';

import '../articles/verified_articles.dart';
import '../learning/verified_learning_paths.dart';
import '../runbooks/reviewed_runbook_actions.dart';

final _uuid = RegExp(
    r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$');

class InterviewStarted {
  const InterviewStarted(this.id, this.pathId, this.question, this.context);
  final String id;
  final String pathId;
  final String question;
  final String context;

  factory InterviewStarted.parse(String raw, String expectedPath) {
    final data = _responseData(raw);
    final progress = data['progress'];
    if (data['session_id'] is! String ||
        !_uuid.hasMatch(data['session_id'] as String) ||
        data['path_id'] != expectedPath ||
        data['status'] != 'awaiting_answer' ||
        progress is! Map<String, dynamic> ||
        progress['answered'] != 0 || progress['total'] != 1 ||
        data['feedback'] != null ||
        data['question'] is! String ||
        data['context'] is! String ||
        (data['question'] as String).trim().isEmpty ||
        (data['question'] as String).length > 2000 ||
        (data['context'] as String).length > 6000) {
      throw const FormatException('Invalid interview session');
    }
    return InterviewStarted(data['session_id'] as String, expectedPath,
        data['question'] as String, data['context'] as String);
  }
}

class InterviewFeedback {
  const InterviewFeedback(this.sessionId, this.message, this.reviewSource);
  final String sessionId;
  final String message;
  final String reviewSource;

  factory InterviewFeedback.parse(String raw, String expectedSession) {
    final data = _responseData(raw);
    final progress = data['progress'];
    final feedback = data['feedback'];
    if (data['session_id'] != expectedSession ||
        data['status'] != 'answered' ||
        progress is! Map<String, dynamic> ||
        progress['answered'] != 1 || progress['total'] != 1 ||
        feedback is! Map<String, dynamic> ||
        feedback['type'] != 'guided_self_review' ||
        feedback['message'] is! String ||
        feedback['review_source'] is! String ||
        (feedback['message'] as String).length > 1200 ||
        (feedback['review_source'] as String).length > 6000 ||
        (feedback['review_source'] as String).trim().isEmpty ||
        data.containsKey('score') || data.containsKey('ai_result')) {
      throw const FormatException('Untrusted interview feedback');
    }
    return InterviewFeedback(expectedSession, feedback['message'] as String,
        feedback['review_source'] as String);
  }
}

Map<String, dynamic> _responseData(String raw) {
  if (raw.length > 262144) throw const FormatException('Response too large');
  final parsed = jsonDecode(raw);
  if (parsed is! Map<String, dynamic> ||
      parsed.length != 1 || parsed['data'] is! Map<String, dynamic>) {
    throw const FormatException('Invalid interview response');
  }
  return parsed['data'] as Map<String, dynamic>;
}

typedef InterviewLogin = Future<RunbookSession> Function(String, String);
typedef InterviewTracks = Future<VerifiedLearningPage> Function(bool, int);
typedef InterviewStart = Future<InterviewStarted> Function(String, bool, String, int);
typedef InterviewAnswer = Future<InterviewFeedback> Function(String, String, String);
typedef InterviewLogout = Future<void> Function(String);

/// The canonical server stores an answer privately. No AI is used for grading.
class MobileInterviewClient {
  const MobileInterviewClient();

  static Uri? configuredOrigin() => VerifiedArticleReader.validatedOrigin(
      const String.fromEnvironment('WALIDSITE_API_ORIGIN'));

  Future<String> _post(String token, String path,
      Map<String, Object?>? payload) async {
    final origin = configuredOrigin();
    if (origin == null || token.trim().isEmpty || token.length > 8192) {
      throw const FormatException('Interview server unavailable');
    }
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 8);
    try {
      final request = await client.postUrl(origin.replace(path: path))
          .timeout(const Duration(seconds: 8));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $token');
      if (payload != null) {
        request.headers.set(HttpHeaders.contentTypeHeader, 'application/json');
        request.add(utf8.encode(jsonEncode(payload)));
      }
      final response = await request.close().timeout(const Duration(seconds: 8));
      if (response.statusCode != HttpStatus.ok) {
        throw RunbookActionHttpException(response.statusCode);
      }
      final bytes = BytesBuilder(copy: false);
      await for (final part in response.timeout(const Duration(seconds: 8))) {
        if (bytes.length + part.length > 262144) {
          throw const FormatException('Interview response too large');
        }
        bytes.add(part);
      }
      return utf8.decode(bytes.takeBytes(), allowMalformed: false);
    } finally {
      client.close(force: true);
    }
  }

  Future<InterviewStarted> start(String token, bool arabic,
      String pathId, int lessonIndex) async {
    if (!_uuid.hasMatch(pathId) || lessonIndex < 0 || lessonIndex > 39) {
      throw const FormatException('Invalid learning lesson');
    }
    return InterviewStarted.parse(await _post(token, '/api/v1/mobile/interview', {
      'action': 'start', 'path_id': pathId,
      'lesson_index': lessonIndex, 'lang': arabic ? 'ar' : 'en',
    }), pathId);
  }

  Future<InterviewFeedback> answer(String token, String id, String answer) async {
    if (!_uuid.hasMatch(id) || answer.trim().length < 3 || answer.length > 4000) {
      throw const FormatException('Invalid practice answer');
    }
    return InterviewFeedback.parse(await _post(token, '/api/v1/mobile/interview', {
      'action': 'answer', 'session_id': id, 'answer': answer,
    }), id);
  }

  Future<void> logout(String token) async {
    final result = _responseData(await _post(token, '/api/v1/auth/logout', null));
    if (result['revoked'] != true) throw const FormatException('Unconfirmed logout');
  }
}

/// MOBILE-09: the review text comes from a currently published learning lesson.
/// Answer storage is explicitly disclosed and requires consent before POST.
class InterviewPracticeScreen extends StatefulWidget {
  const InterviewPracticeScreen({
    required this.arabic, this.loginAction, this.tracksAction,
    this.startAction, this.answerAction, this.logoutAction, super.key,
  });
  final bool arabic;
  final InterviewLogin? loginAction;
  final InterviewTracks? tracksAction;
  final InterviewStart? startAction;
  final InterviewAnswer? answerAction;
  final InterviewLogout? logoutAction;

  @override
  State<InterviewPracticeScreen> createState() => _InterviewPracticeState();
}

class _InterviewPracticeState extends State<InterviewPracticeScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _draft = TextEditingController();
  RunbookSession? _session;
  final List<VerifiedLearningTrack> _tracks = [];
  InterviewStarted? _active;
  InterviewFeedback? _feedback;
  String? _error;
  bool _busy = false;
  bool _consent = false;
  bool _hasMore = false;
  int _page = 1;
  int _epoch = 0;

  String t(String en, String arabic) => widget.arabic ? arabic : en;
  bool get configured => widget.startAction != null ||
      MobileInterviewClient.configuredOrigin() != null;
  InterviewLogin get _login =>
      widget.loginAction ?? const ReviewedRunbookActionsClient().login;
  InterviewTracks get _readTracks => widget.tracksAction ??
      const VerifiedLearningReader().loadPage;
  InterviewStart get _start =>
      widget.startAction ?? const MobileInterviewClient().start;
  InterviewAnswer get _answer =>
      widget.answerAction ?? const MobileInterviewClient().answer;
  InterviewLogout get _logout =>
      widget.logoutAction ?? const MobileInterviewClient().logout;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _draft.dispose();
    super.dispose();
  }

  bool _tokenValid() {
    final s = _session;
    if (s != null && s.token.trim().isNotEmpty && s.token.length <= 8192 &&
        (s.expiresAt == null ||
            s.expiresAt!.isAfter(DateTime.now().toUtc()))) {
      return true;
    }
    _draft.clear();
    _password.clear();
    setState(() {
      _session = null;
      _tracks.clear();
      _active = null;
      _feedback = null;
      _consent = false;
      _error = t('Session expired. Please sign in again.',
          'انتهت صلاحية الجلسة. سجل الدخول مرة أخرى.');
    });
    return false;
  }

  Future<void> _signIn() async {
    if (_busy) return;
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _error = t('Enter email and password.',
          'أدخل البريد الإلكتروني وكلمة المرور.'));
      return;
    }
    final epoch = ++_epoch;
    setState(() { _busy = true; _error = null; });
    try {
      final signed = await _login(_email.text.trim(), _password.text);
      _password.clear();
      if (!mounted || epoch != _epoch) return;
      if (signed.token.trim().isEmpty || signed.token.length > 8192 ||
          (signed.expiresAt != null &&
              !signed.expiresAt!.isAfter(DateTime.now().toUtc()))) {
        throw const FormatException('Expired bearer');
      }
      final catalog = await _readTracks(widget.arabic, 1);
      if (!mounted || epoch != _epoch) return;
      setState(() {
        _session = signed;
        _tracks
          ..clear()
          ..addAll(catalog.tracks);
        _page = 1;
        _hasMore = catalog.hasMorePages;
      });
    } catch (_) {
      if (mounted && epoch == _epoch) {
        setState(() => _error = t(
            'Could not sign in or load verified lessons. Try again.',
            'تعذر الدخول أو تحميل الدروس المراجعة. أعد المحاولة.'));
      }
    } finally {
      _password.clear();
      if (mounted && epoch == _epoch) setState(() => _busy = false);
    }
  }

  Future<void> _loadMore() async {
    if (_busy || !_tokenValid() || !_hasMore) return;
    final epoch = ++_epoch;
    setState(() { _busy = true; _error = null; });
    try {
      final next = await _readTracks(widget.arabic, _page + 1);
      if (next.currentPage != _page + 1 ||
          next.tracks.any((x) => _tracks.any((y) => y.id == x.id))) {
        throw const FormatException('Repeated lesson page');
      }
      if (!mounted || epoch != _epoch) return;
      setState(() {
        _tracks.addAll(next.tracks);
        _page++;
        _hasMore = next.hasMorePages;
      });
    } catch (_) {
      if (mounted && epoch == _epoch) {
        setState(() => _error = t('Cannot load more lessons. Retry.',
            'تعذر تحميل المزيد من الدروس. أعد المحاولة.'));
      }
    } finally {
      if (mounted && epoch == _epoch) setState(() => _busy = false);
    }
  }

  Future<void> _begin(VerifiedLearningTrack track, int index) async {
    if (_busy || !_tokenValid()) return;
    final epoch = ++_epoch;
    setState(() { _busy = true; _error = null; });
    try {
      final started = await _start(_session!.token, widget.arabic,
          track.id, index);
      if (!mounted || epoch != _epoch) return;
      if (started.pathId != track.id) throw const FormatException('Wrong track');
      _draft.clear();
      setState(() {
        _active = started;
        _feedback = null;
        _consent = false;
      });
    } catch (_) {
      if (mounted && epoch == _epoch) {
        setState(() => _error = t(
            'This reviewed lesson cannot start practice right now.',
            'لا يمكن بدء التدريب لهذا الدرس المراجع حاليًا.'));
      }
    } finally {
      if (mounted && epoch == _epoch) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    if (_busy || !_tokenValid() || _active == null) return;
    if (!_consent || _draft.text.trim().length < 3) {
      setState(() => _error = t(
          'Write an answer and agree to private server storage.',
          'اكتب إجابة ووافق على حفظها الخاص بالخادم.'));
      return;
    }
    final id = _active!.id;
    final text = _draft.text;
    final epoch = ++_epoch;
    setState(() { _busy = true; _error = null; });
    try {
      final result = await _answer(_session!.token, id, text);
      if (!mounted || epoch != _epoch) return;
      if (result.sessionId != id) throw const FormatException('Wrong session');
      _draft.clear();
      setState(() => _feedback = result);
    } catch (_) {
      if (mounted && epoch == _epoch) {
        setState(() => _error = t(
            'Could not store the answer or load feedback. Retry once; a completed session cannot be resubmitted.',
            'تعذر حفظ الإجابة أو تحميل المراجعة. أعد المحاولة مرة واحدة؛ لا يمكن إعادة إرسال جلسة مكتملة.'));
      }
    } finally {
      if (mounted && epoch == _epoch) setState(() => _busy = false);
    }
  }

  Future<void> _signOut() async {
    if (_busy || !_tokenValid()) return;
    final epoch = ++_epoch;
    setState(() { _busy = true; _error = null; });
    try {
      await _logout(_session!.token);
      if (!mounted || epoch != _epoch) return;
      _draft.clear();
      _password.clear();
      setState(() {
        _session = null;
        _tracks.clear();
        _active = null;
        _feedback = null;
        _consent = false;
      });
    } catch (_) {
      if (mounted && epoch == _epoch) {
        setState(() => _error = t(
            'Session could not be revoked. Retry sign-out.',
            'تعذر إلغاء الجلسة. أعد محاولة الخروج.'));
      }
    } finally {
      if (mounted && epoch == _epoch) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: widget.arabic ? TextDirection.rtl : TextDirection.ltr,
      child: Scaffold(
        appBar: AppBar(title: Text(t('Interview trainer', 'تدريب المقابلات'))),
        body: ListView(padding: const EdgeInsets.all(20), children: [
          Text(t('Evidence-based interview practice',
              'تدريب مقابلات مستند إلى دروس منشورة'),
              style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 12),
          Text(t(
              'Practice uses currently published learning lessons. Your answer '
              'is sent to the server and saved privately to your account for this '
              'session. Feedback is guided self-review, not AI grading. '
              'Sign-out revokes the token but does not erase stored answers.',
              'يعتمد التدريب على دروس منشورة. تُرسل إجابتك إلى الخادم '
              'وتُحفظ بشكل خاص في حسابك للجلسة. المراجعة ذاتية دون تقييم '
              'بالذكاء الاصطناعي. الخروج يلغي الرمز ولا يحذف الإجابات المحفوظة.')),
          const SizedBox(height: 18),
          if (!configured)
            Card(key: const Key('interview-unconfigured'),
              child: Padding(padding: const EdgeInsets.all(18),
                child: Text(t('Practice server is not configured.',
                    'خادم التدريب غير مهيأ.'))))
          else if (_session == null) ...[
            TextField(key: const Key('interview-email'), controller: _email,
              keyboardType: TextInputType.emailAddress,
              decoration: InputDecoration(labelText: t('Email', 'البريد الإلكتروني'))),
            TextField(key: const Key('interview-password'), controller: _password,
              obscureText: true, enableSuggestions: false, autocorrect: false,
              decoration: InputDecoration(labelText: t('Password', 'كلمة المرور'))),
            const SizedBox(height: 12),
            FilledButton(key: const Key('interview-sign-in'),
              onPressed: _busy ? null : _signIn,
              child: Text(_busy ? t('Loading...', 'جارٍ التحميل...')
                  : t('Sign in to practice', 'الدخول إلى التدريب'))),
          ] else if (_active == null) ...[
            Text(t('Choose a verified lesson', 'اختر درسًا تمت مراجعته'),
                style: Theme.of(context).textTheme.titleMedium),
            if (_tracks.isEmpty)
              Text(t('No published lessons available for practice.',
                  'لا توجد دروس منشورة متاحة للتدريب.'),
                  key: const Key('interview-empty')),
            ..._tracks.expand((track) => [
              Text(track.title, style: Theme.of(context).textTheme.titleMedium),
              ...track.lessons.asMap().entries.map((entry) =>
                Padding(padding: const EdgeInsets.symmetric(vertical: 5),
                  child: OutlinedButton(
                    key: Key('interview-start-' + track.slug + '-' +
                        entry.key.toString()),
                    onPressed: _busy ? null : () => _begin(track, entry.key),
                    child: Text(entry.value.title),
                  ))),
            ]),
            if (_hasMore)
              TextButton(key: const Key('interview-more'),
                onPressed: _busy ? null : _loadMore,
                child: Text(t('More verified lessons', 'المزيد من الدروس المراجعة'))),
          ] else ...[
            Text(_active!.question, key: const Key('interview-question'),
                style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
            Text(_active!.context, key: const Key('interview-context')),
            const SizedBox(height: 12),
            if (_feedback == null) ...[
              TextField(key: const Key('interview-answer'), controller: _draft,
                minLines: 3, maxLines: 7, maxLength: 2000,
                decoration: InputDecoration(
                  labelText: t('Your practice answer', 'إجابتك التدريبية'),
                  border: const OutlineInputBorder())),
              CheckboxListTile(
                key: const Key('interview-consent'),
                value: _consent, onChanged: _busy ? null :
                    (value) => setState(() => _consent = value ?? false),
                title: Text(t(
                  'I agree to send and privately store this answer on the server.',
                  'أوافق على إرسال هذه الإجابة وحفظها بشكل خاص على الخادم.')),
                controlAffinity: ListTileControlAffinity.leading,
              ),
              FilledButton(key: const Key('interview-submit'),
                onPressed: _busy ? null : _submit,
                child: Text(t('Submit for guided self-review',
                    'إرسال للمراجعة الذاتية'))),
            ] else
              Card(key: const Key('interview-feedback'),
                child: Padding(padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(t('Published lesson review source',
                          'مصدر المراجعة: الدرس المنشور'),
                          style: Theme.of(context).textTheme.titleMedium),
                      Text(_feedback!.reviewSource),
                      const SizedBox(height: 10),
                      Text(_feedback!.message),
                    ]))),
            const SizedBox(height: 12),
            if (_feedback != null)
              FilledButton(key: const Key('interview-next'),
                onPressed: () {
                  ++_epoch;
                  _draft.clear();
                  setState(() {
                    _active = null; _feedback = null;
                    _consent = false; _error = null;
                  });
                },
                child: Text(t('Choose another lesson', 'اختر درسًا آخر'))),
          ],
          if (_session != null) ...[
            const SizedBox(height: 10),
            OutlinedButton(key: const Key('interview-sign-out'),
              onPressed: _busy ? null : _signOut,
              child: Text(t('Sign out and revoke token',
                  'خروج وإلغاء رمز الجلسة'))),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Semantics(liveRegion: true,
              child: Text(_error!, key: const Key('interview-error'))),
          ],
        ]),
      ),
    );
  }
}

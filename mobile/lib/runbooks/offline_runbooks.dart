import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'signed_runbook_sync.dart';
import 'offline_guide_text_search.dart';

/// The bundled pack is an offline-first *safe fallback*, not a successful sync.
/// T013 does not assert that the live runbook API or signature checks exist.
class OfflineRunbookPack {
  const OfflineRunbookPack({
    required this.version,
    required this.source,
    required this.lastSynced,
    required this.verifiedAt,
    required this.items,
    this.signedVerified = false,
  });

  final String version;
  final String source;
  final DateTime? lastSynced;
  final DateTime? verifiedAt;
  final List<OfflineRunbook> items;

  // True only after pinned-key signature verification by SignedRunbookSource.
  // Not parsed from downloaded JSON or inferred from publisher timestamps.
  final bool signedVerified;

  bool get isStale {
    final verified = verifiedAt;
    final now = DateTime.now().toUtc();
    return verified == null ||
        verified.isAfter(now) ||
        now.difference(verified).inDays >= 30;
  }

  // rootBundle already caches small asset strings. Never hold a static Future
  // across navigator trees or widget-test FakeAsync zones: its async
  // completion can strand a later FutureBuilder on a permanent spinner.
  static Future<OfflineRunbookPack> loadBundled() => _readBundledAsset();

  static Future<OfflineRunbookPack> _readBundledAsset() async {
    // rootBundle never calls the network; available in airplane mode and after restart.
    final text = await rootBundle.loadString('assets/runbooks/offline-pack.json');
    return parse(text);
  }

  static OfflineRunbookPack parse(String jsonText) {
    // The offline fallback is bundled with the app, not a streaming trust
    // boundary. Bound input before JSON decoding so a corrupt pack cannot
    // exhaust memory or lock the reader on a low-end device.
    if (jsonText.length > 512 * 1024) {
      throw const FormatException('Offline runbook package is too large');
    }
    final decoded = jsonDecode(jsonText);
    if (decoded is! Map<String, dynamic> ||
        decoded['schema'] != 'walidsite.offline-runbook-pack.v1' ||
        decoded['version'] is! String ||
        decoded['items'] is! List) {
      throw const FormatException('Unsupported offline runbook package');
    }

    final version = decoded['version'] as String;
    final rawItems = decoded['items'] as List<dynamic>;
    if (version.trim().isEmpty || version.length > 64 ||
        rawItems.length > 200) {
      throw const FormatException('Invalid runbook package limits');
    }

    final source = decoded['source'];
    if (source is! String || source.trim().isEmpty || source.length > 256) {
      throw const FormatException('Runbook source is missing');
    }

    DateTime? dateOrNull(Object? raw) {
      if (raw == null) return null;
      if (raw is! String) throw const FormatException('Invalid timestamp');
      final parsed = DateTime.tryParse(raw);
      if (parsed == null || !parsed.isUtc) {
        throw const FormatException('Timestamp must include a UTC offset');
      }
      return parsed.toUtc();
    }

    final items = <OfflineRunbook>[];
    final seen = <String>{};
    for (final entry in rawItems) {
      if (entry is! Map<String, dynamic>) {
        throw const FormatException('Invalid runbook entry');
      }
      final id = entry['id'];
      final titleEn = entry['title_en'];
      final titleAr = entry['title_ar'];
      final stepsEn = entry['steps_en'];
      final stepsAr = entry['steps_ar'];
      if (id is! String || !RegExp(r'^[a-z0-9-]{1,60}$').hasMatch(id) ||
          !seen.add(id) || titleEn is! String || titleAr is! String ||
          titleEn.trim().isEmpty || titleAr.trim().isEmpty ||
          titleEn.length > 250 || titleAr.length > 250 ||
          stepsEn is! List || stepsAr is! List ||
          stepsEn.isEmpty || stepsEn.length > 50 ||
          stepsEn.length != stepsAr.length ||
          !stepsEn.every((value) =>
              value is String && value.trim().isNotEmpty && value.length <= 4000) ||
          !stepsAr.every((value) =>
              value is String && value.trim().isNotEmpty && value.length <= 4000)) {
        throw const FormatException('Runbook entry failed validation');
      }
      items.add(OfflineRunbook(
        id: id,
        titleEn: titleEn,
        titleAr: titleAr,
        stepsEn: List<String>.unmodifiable(stepsEn.cast<String>()),
        stepsAr: List<String>.unmodifiable(stepsAr.cast<String>()),
      ));
    }
    return OfflineRunbookPack(
      version: decoded['version'] as String,
      source: source,
      lastSynced: dateOrNull(decoded['last_synced_at']),
      verifiedAt: dateOrNull(decoded['verified_at']),
      items: List.unmodifiable(items),
    );
  }
}

class OfflineRunbook {
  const OfflineRunbook({
    required this.id,
    required this.titleEn,
    required this.titleAr,
    required this.stepsEn,
    required this.stepsAr,
  });

  final String id;
  final String titleEn;
  final String titleAr;
  final List<String> stepsEn;
  final List<String> stepsAr;

  String title(bool arabic) => arabic ? titleAr : titleEn;
  List<String> steps(bool arabic) => arabic ? stepsAr : stepsEn;
}

class OfflineRunbookLibrary extends StatefulWidget {
  const OfflineRunbookLibrary({required this.arabic, this.bundledPack, this.repository, super.key});
  final bool arabic;

  // Tests can supply deterministic offline content without depending on an
  // AssetBundle read during a Flutter FakeAsync widget frame.
  final Future<OfflineRunbookPack>? bundledPack;
  final OfflineRunbookRepository? repository;

  @override
  State<OfflineRunbookLibrary> createState() => _OfflineRunbookLibraryState();
}

class _OfflineRunbookLibraryState extends State<OfflineRunbookLibrary> {
  late final OfflineRunbookRepository _repository;
  late Future<OfflineRunbookPack> _pack;
  String _query = '';
  String? _syncError;
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    _repository = widget.repository ?? OfflineRunbookRepository();
    // The app always opens offline: first a reverified signed local
    // snapshot if configured, then the safe bundled educational pack.
    _pack = widget.bundledPack ?? _repository.load();
  }

  Future<void> _sync() async {
    if (_syncing || !_repository.source.configured) return;
    setState(() {
      _syncing = true;
      _syncError = null;
    });
    try {
      final next = await _repository.sync();
      if (!mounted) return;
      setState(() => _pack = Future.value(next));
    } catch (_) {
      // Never surface raw provider responses, URLs, tokens or HTTP details.
      if (!mounted) return;
      setState(() => _syncError = widget.arabic
          ? 'تعذّر التحقق من التحديث. المحتوى المحلي السابق ما زال متاحًا.'
          : 'Update could not be verified. Previous offline content remains available.');
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final arabic = widget.arabic;
    return Scaffold(
      appBar: AppBar(title: Text(arabic ? 'مكتبة الأدلة المحلية' : 'Offline runbook library')),
      body: FutureBuilder<OfflineRunbookPack>(
        future: _pack,
        builder: (context, snapshot) {
          if (snapshot.hasError) {
            return Center(child: Text(arabic
                ? 'تعذر قراءة الحزمة المحلية، يرجى إعادة تثبيت التطبيق.'
                : 'Unable to read the bundled runbooks. Please reinstall the app.'));
          }
          if (!snapshot.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          final pack = snapshot.data!;
          // Match the locally bundled guidance, not just its heading.
          // Search only the currently selected language so hidden alternate
          // translations do not appear as misleading matches.
          final results = pack.items.where((item) =>
              OfflineGuideTextSearch.matches(
                _query, [item.title(arabic), ...item.steps(arabic)],
              )).toList();
          return ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _PackStatus(pack: pack, arabic: arabic),
              const SizedBox(height: 12),
              if (_repository.source.configured)
                FilledButton.icon(
                  key: const Key('runbook-sync'),
                  onPressed: _syncing ? null : _sync,
                  icon: const Icon(Icons.sync_outlined),
                  label: Text(arabic ? 'التحقق من تحديث الأدلة' : 'Verify runbook update'),
                )
              else
                Text(
                  arabic ? 'التحديثات الموقعة غير مهيأة؛ يمكنك القراءة بلا اتصال.'
                      : 'Signed updates not configured. Offline reading is available.',
                  key: const Key('runbook-sync-unconfigured'),
                ),
              if (_syncing) const LinearProgressIndicator(key: Key('runbook-sync-progress')),
              if (_syncError != null)
                Text(_syncError!, key: const Key('runbook-sync-error'),
                    style: const TextStyle(color: Colors.red)),
              const SizedBox(height: 16),
              TextField(
                key: const Key('runbook-search'),
                decoration: InputDecoration(
                  border: const OutlineInputBorder(),
                  labelText: arabic ? 'بحث داخل الأدلة' : 'Search runbooks',
                  prefixIcon: const Icon(Icons.search),
                ),
                onChanged: (value) => setState(() => _query = value.trim()),
              ),
              const SizedBox(height: 16),
              if (results.isEmpty)
                Text(arabic ? 'لا توجد أدلة مطابقة.' : 'No matching runbooks.'),
              ...results.map((item) => Card(
                child: ListTile(
                  key: Key('runbook-${item.id}'),
                  leading: const Icon(Icons.menu_book_outlined),
                  title: Text(item.title(arabic)),
                  subtitle: Text(pack.signedVerified
                      ? (arabic ? 'نسخة موقّعة تم التحقق منها — متاحة دون إنترنت'
                          : 'Verified signed snapshot — saved offline')
                      : (arabic ? 'حزمة تعليمية مرفقة — متاحة دون إنترنت'
                          : 'Bundled educational copy — available offline')),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => OfflineRunbookDetail(arabic: arabic, item: item, pack: pack),
                  )),
                ),
              )),
            ],
          );
        },
      ),
    );
  }
}

class _PackStatus extends StatelessWidget {
  const _PackStatus({required this.pack, required this.arabic});
  final OfflineRunbookPack pack;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
    final sync = pack.lastSynced?.toIso8601String() ??
        (arabic ? 'لم تحدث مزامنة' : 'Never synced');
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(arabic ? 'الإصدار المحلي: ${pack.version}' : 'Offline pack version: ${pack.version}',
                style: const TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            Text(arabic ? 'المصدر: ${pack.source}' : 'Source: ${pack.source}'),
            Text(arabic ? 'آخر مزامنة: $sync' : 'Last sync: $sync'),
            if (pack.isStale)
              Text(
                arabic ? 'تنبيه: المحتوى غير موثق حديثًا، لا تستخدمه كإجراء تشغيلي معتمد.'
                    : 'Stale / unverified: not approved for production operations.',
                key: const Key('runbook-stale-warning'),
                style: const TextStyle(color: Color(0xFF93600B), fontWeight: FontWeight.bold),
              ),
          ],
        ),
      ),
    );
  }
}

class OfflineRunbookDetail extends StatefulWidget {
  const OfflineRunbookDetail({required this.item, required this.pack, required this.arabic, super.key});
  final OfflineRunbook item;
  final OfflineRunbookPack pack;
  final bool arabic;

  @override
  State<OfflineRunbookDetail> createState() => _OfflineRunbookDetailState();
}

class _OfflineRunbookDetailState extends State<OfflineRunbookDetail> {
  final Set<int> _completed = <int>{};

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    final pack = widget.pack;
    final arabic = widget.arabic;
    final steps = item.steps(arabic);
    return Scaffold(
      appBar: AppBar(title: Text(item.title(arabic))),
      body: ListView(
        key: const Key('offline-runbook-detail'),
        padding: const EdgeInsets.all(20),
        children: [
          _PackStatus(pack: pack, arabic: arabic),
          const SizedBox(height: 20),
          Text(arabic ? 'خطوات توضيحية عامة' : 'General guidance steps',
              style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 12),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: FilledButton.icon(
              key: const Key('runbook-copy-steps'),
              icon: const Icon(Icons.copy_outlined),
              label: Text(arabic ? 'نسخ الخطوات مع المصدر' : 'Copy steps with source'),
              onPressed: () async {
                // A copy must never omit evidence status or switch language.
                final copyText = [
                  item.title(arabic),
                  arabic ? 'إصدار الحزمة: ${pack.version}' : 'Pack version: ${pack.version}',
                  arabic ? 'المصدر: ${pack.source}' : 'Source: ${pack.source}',
                  if (pack.isStale)
                    arabic
                        ? 'غير موثق حديثًا — لا تستخدمه كإجراء تشغيلي معتمد.'
                        : 'Stale / unverified: not approved for production operations.',
                  for (var i = 0; i < steps.length; i++) '${i + 1}. ${steps[i]}',
                ].join('\n');
                await Clipboard.setData(ClipboardData(text: copyText));
                if (!context.mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(arabic ? 'تم نسخ الخطوات مع المصدر والتحذير.' : 'Steps, source and warning copied.'),
                ));
              },
            ),
          ),
          const SizedBox(height: 12),
          for (var i = 0; i < steps.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: CheckboxListTile(
                key: Key('runbook-step-${i + 1}-complete'),
                value: _completed.contains(i),
                controlAffinity: ListTileControlAffinity.leading,
                title: SelectableText(steps[i]),
                subtitle: Text(arabic ? 'خطوة ${i + 1}' : 'Step ${i + 1}'),
                onChanged: (value) => setState(() {
                  if (value == true) {
                    _completed.add(i);
                  } else {
                    _completed.remove(i);
                  }
                }),
              ),
            ),
        ],
      ),
    );
  }
}

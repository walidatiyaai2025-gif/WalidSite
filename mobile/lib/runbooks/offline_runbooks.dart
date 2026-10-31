import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// The bundled pack is an offline-first *safe fallback*, not a successful sync.
/// T013 does not assert that the live runbook API or signature checks exist.
class OfflineRunbookPack {
  const OfflineRunbookPack({
    required this.version,
    required this.source,
    required this.lastSynced,
    required this.verifiedAt,
    required this.items,
  });

  final String version;
  final String source;
  final DateTime? lastSynced;
  final DateTime? verifiedAt;
  final List<OfflineRunbook> items;

  bool get isStale => verifiedAt == null ||
      DateTime.now().difference(verifiedAt!).inDays >= 30;

  // One immutable bundled read per app process, preventing repeated asset I/O
  // and avoiding build-frame races during rapid locale or route transitions.
  static Future<OfflineRunbookPack>? _bundledCache;

  static Future<OfflineRunbookPack> loadBundled() =>
      _bundledCache ??= _readBundledAsset();

  static Future<OfflineRunbookPack> _readBundledAsset() async {
    // rootBundle never calls the network; available in airplane mode and after restart.
    final text = await rootBundle.loadString('assets/runbooks/offline-pack.json');
    return parse(text);
  }

  static OfflineRunbookPack parse(String jsonText) {
    final decoded = jsonDecode(jsonText);
    if (decoded is! Map<String, dynamic> ||
        decoded['schema'] != 'walidsite.offline-runbook-pack.v1' ||
        decoded['version'] is! String ||
        decoded['items'] is! List) {
      throw const FormatException('Unsupported offline runbook package');
    }

    final source = decoded['source'];
    if (source is! String || source.trim().isEmpty) {
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
    for (final entry in decoded['items'] as List<dynamic>) {
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
          stepsEn is! List || stepsAr is! List ||
          stepsEn.isEmpty || stepsEn.length != stepsAr.length ||
          !stepsEn.every((value) => value is String && value.trim().isNotEmpty) ||
          !stepsAr.every((value) => value is String && value.trim().isNotEmpty)) {
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
  const OfflineRunbookLibrary({required this.arabic, super.key});
  final bool arabic;

  @override
  State<OfflineRunbookLibrary> createState() => _OfflineRunbookLibraryState();
}

class _OfflineRunbookLibraryState extends State<OfflineRunbookLibrary> {
  late final Future<OfflineRunbookPack> _pack = OfflineRunbookPack.loadBundled();
  String _query = '';

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
          final results = pack.items.where((item) =>
              item.title(arabic).toLowerCase().contains(_query.toLowerCase())).toList();
          return ListView(
            padding: const EdgeInsets.all(20),
            children: [
              _PackStatus(pack: pack, arabic: arabic),
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
                  subtitle: Text(arabic ? 'حزمة محلية — لا تحتاج إنترنت' : 'Bundled — available without internet'),
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

class OfflineRunbookDetail extends StatelessWidget {
  const OfflineRunbookDetail({required this.item, required this.pack, required this.arabic, super.key});
  final OfflineRunbook item;
  final OfflineRunbookPack pack;
  final bool arabic;

  @override
  Widget build(BuildContext context) {
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
          for (var i = 0; i < steps.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Semantics(
                label: arabic ? 'خطوة ${i + 1}' : 'Step ${i + 1}',
                child: ListTile(
                  leading: CircleAvatar(child: Text('${i + 1}')),
                  title: SelectableText(steps[i]),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

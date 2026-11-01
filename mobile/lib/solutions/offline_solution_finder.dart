import 'package:flutter/material.dart';
import '../runbooks/offline_runbooks.dart';
import '../runbooks/signed_runbook_sync.dart';
import '../runbooks/offline_guide_text_search.dart';

/// Local, deterministic runbook discovery. It does not call an AI service,
/// upload source code, infer a diagnosis, or claim the draft pack is current.
class OfflineSolutionSearch {
  const OfflineSolutionSearch._();

  static List<OfflineRunbook> find(
    OfflineRunbookPack pack,
    String query, {
    required bool arabic,
  }) {
    // Unlike the library's browse-all state, a finder with no keywords
    // must not suggest that a diagnosis has already been produced.
    if (OfflineGuideTextSearch.normalize(query).isEmpty) {
      return const <OfflineRunbook>[];
    }

    return pack.items.where((item) {
      // Never search hidden translations; only the selected locale's fields.
      return OfflineGuideTextSearch.matches(
        query, [item.title(arabic), ...item.steps(arabic)],
      );
    }).take(20).toList(growable: false);
  }
}

class OfflineSolutionFinder extends StatefulWidget {
  const OfflineSolutionFinder({
    required this.arabic,
    this.bundledPack,
    this.repository,
    super.key,
  });

  final bool arabic;
  // Deterministic test fixture; normal operation uses the same verified
  // offline snapshot selection as the runbook library, including at restart.
  final Future<OfflineRunbookPack>? bundledPack;
  final OfflineRunbookRepository? repository;

  @override
  State<OfflineSolutionFinder> createState() => _OfflineSolutionFinderState();
}

class _OfflineSolutionFinderState extends State<OfflineSolutionFinder> {
  late final Future<OfflineRunbookPack> _pack;
  String _query = '';

  @override
  void initState() {
    super.initState();
    // This read never initiates a network call. The repository verifies the
    // persisted Ed25519 signature with the pinned publisher key on EVERY load
    // and safely falls back to the bundled educational pack on failure.
    _pack = widget.bundledPack ??
        (widget.repository ?? OfflineRunbookRepository()).load();
  }

  @override
  Widget build(BuildContext context) {
    final arabic = widget.arabic;
    return Scaffold(
      appBar: AppBar(
        title: Text(arabic ? 'البحث عن حلول محلية' : 'Offline solution finder'),
      ),
      body: SafeArea(
        child: FutureBuilder<OfflineRunbookPack>(
          future: _pack,
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    arabic
                        ? 'تعذر قراءة الأدلة المحلية. لا توجد نتيجة تشخيص. أعد تثبيت التطبيق.'
                        : 'Could not load offline guidance. No diagnosis was performed. Reinstall the app.',
                    key: const Key('solution-pack-error'),
                    textAlign: TextAlign.center,
                  ),
                ),
              );
            }
            if (!snapshot.hasData) {
              return const Center(child: CircularProgressIndicator());
            }

            final pack = snapshot.data!;
            final results = OfflineSolutionSearch.find(pack, _query, arabic: arabic);
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Text(
                  arabic
                      ? 'ابحث داخل عناوين وخطوات الأدلة المحلية المتاحة؛ هذه مطابقة نصية وليست تشخيصًا بالذكاء الاصطناعي.'
                      : 'Search titles and steps in offline guides. Matches are not AI diagnoses.',
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const SizedBox(height: 12),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(arabic
                            ? 'إصدار الحزمة: ${pack.version}'
                            : 'Pack version: ${pack.version}'),
                        Text(arabic
                            ? 'المصدر: ${pack.source}'
                            : 'Source: ${pack.source}'),
                        Text(
                          pack.signedVerified
                              ? (arabic
                                  ? 'نسخة محلية موقّعة تم التحقق منها'
                                  : 'Verified signed offline snapshot')
                              : (arabic
                                  ? 'حزمة تعليمية مرفقة غير معتمدة'
                                  : 'Bundled educational copy — unverified'),
                          key: const Key('solution-pack-provenance'),
                        ),
                        if (!pack.signedVerified || pack.isStale)
                          Text(
                            arabic
                                ? 'تنبيه: الأدلة غير موثقة حديثًا؛ لا تعتمد عليها كتعليمات تشغيل نهائية.'
                                : 'Stale/unverified guidance: not approved for production operations.',
                            key: const Key('solution-stale-warning'),
                            style: const TextStyle(
                              color: Color(0xFF93600B),
                              fontWeight: FontWeight.bold,
                            ),
                          )
                        else
                          Text(arabic
                              ? 'تاريخ التحقق: ${pack.verifiedAt!.toIso8601String()}'
                              : 'Verified at: ${pack.verifiedAt!.toIso8601String()}'),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const Key('solution-search-input'),
                  maxLength: 160,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    border: const OutlineInputBorder(),
                    labelText: arabic ? 'كلمات من المشكلة التقنية' : 'Technical issue keywords',
                    prefixIcon: const Icon(Icons.search),
                  ),
                  onChanged: (value) => setState(() => _query = value),
                ),
                if (_query.trim().isEmpty)
                  Text(
                    arabic
                        ? 'أدخل كلمة للعثور على أدلة محلية، ولا تُرسل أي بيانات إلى الخادم.'
                        : 'Enter keywords to find local guides. Nothing is sent to a server.',
                    key: const Key('solution-search-hint'),
                  )
                else if (results.isEmpty)
                  Text(
                    arabic
                        ? 'لا توجد خطوات محلية مطابقة. لم يتم إنشاء أي حل تلقائي.'
                        : 'No local guidance matched. No answer was generated.',
                    key: const Key('solution-search-empty'),
                  )
                else ...[
                  Text(arabic
                      ? 'أدلة محلية مطابقة (حتى ٢٠)'
                      : 'Matching local guides (up to 20)'),
                  ...results.map((item) => Card(
                        child: ListTile(
                          key: Key('solution-${item.id}'),
                          leading: const Icon(Icons.menu_book_outlined),
                          title: Text(item.title(arabic)),
                          subtitle: Text(arabic
                              ? 'افتح خطوات الدليل ومعلومات المصدر'
                              : 'Open the guide steps and source status'),
                          trailing: Icon(arabic
                              ? Icons.chevron_left
                              : Icons.chevron_right),
                          onTap: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => OfflineRunbookDetail(
                                item: item,
                                pack: pack,
                                arabic: arabic,
                              ),
                            ),
                          ),
                        ),
                      )),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}

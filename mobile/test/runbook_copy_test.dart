import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/runbooks/offline_runbooks.dart';

void main() {
  testWidgets('copy preserves offline source, safety warning and selected language',
      (tester) async {
    const item = OfflineRunbook(
      id: 'checklist',
      titleEn: 'Safety checklist',
      titleAr: 'قائمة الأمان',
      stepsEn: ['Record the current state', 'Test rollback'],
      stepsAr: ['سجّل الحالة الحالية', 'اختبر التراجع'],
    );
    const pack = OfflineRunbookPack(
      version: '0.1.0',
      source: 'Bundled educational draft',
      lastSynced: null,
      verifiedAt: null,
      items: [item],
    );
    final copied = <String>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied.add((call.arguments as Map<dynamic, dynamic>)['text'] as String);
        }
        return null;
      },
    );
    addTearDown(() {
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      );
    });

    await tester.pumpWidget(const MaterialApp(
      home: OfflineRunbookDetail(item: item, pack: pack, arabic: false),
    ));
    await tester.tap(find.byKey(const Key('runbook-copy-steps')));
    await tester.pump();

    expect(copied, hasLength(1));
    expect(copied.first, contains('Pack version: 0.1.0'));
    expect(copied.first, contains('Source: Bundled educational draft'));
    expect(copied.first, contains('Stale / unverified'));
    expect(copied.first, contains('1. Record the current state'));
    expect(copied.first, contains('\n2. Test rollback'));
    expect(copied.first, isNot(contains('سجّل الحالة الحالية')));
    expect(find.text('Steps, source and warning copied.'), findsOneWidget);

    // Unmount the English scaffold before testing the Arabic messenger.
    // Reusing the same messenger leaves its earlier SnackBar in the queue.
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    await tester.pumpWidget(const MaterialApp(
      locale: Locale('ar'),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: OfflineRunbookDetail(item: item, pack: pack, arabic: true),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('runbook-copy-steps')));
    await tester.pump();

    expect(copied, hasLength(2));
    expect(copied.last, contains('إصدار الحزمة: 0.1.0'));
    expect(copied.last, contains('المصدر: Bundled educational draft'));
    expect(copied.last, contains('غير موثق حديثًا'));
    expect(copied.last, contains('1. سجّل الحالة الحالية'));
    expect(copied.last, isNot(contains('Record the current state')));
    expect(find.text('تم نسخ الخطوات مع المصدر والتحذير.'), findsOneWidget);
  });
}

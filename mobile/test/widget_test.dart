import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';

void main() {
  testWidgets('home renders engineering topics without invented metrics', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const WaleedTechApp());

    expect(find.text('Waleed Tech Companion'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Explore engineering topics'), 140);
    expect(find.text('Explore engineering topics'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Database Engineering'), 140);
    expect(find.text('Database Engineering'), findsOneWidget);
    // The offline CTA increases the ListView height; scroll before asserting.
    await tester.scrollUntilVisible(find.text('Software Engineering'), 140);
    expect(find.text('Software Engineering'), findsOneWidget);
    // ListView builds items lazily: scroll to the last topic before asserting.
    await tester.scrollUntilVisible(find.text('AI Engineering'), 150);
    expect(find.text('AI Engineering'), findsOneWidget);
  });

  testWidgets('topic card navigates to a real details screen', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.scrollUntilVisible(find.text('Database Engineering'), 140);
    await tester.tap(find.text('Database Engineering'));
    await tester.pumpAndSettle();

    expect(find.text('SQL Server, reliability and performance'), findsOneWidget);
    expect(find.textContaining('Verified guides'), findsOneWidget);

    await tester.pageBack();
    await tester.pumpAndSettle();

    expect(find.text('Explore engineering topics'), findsOneWidget);
  });

  testWidgets('language switch changes layout to Arabic RTL and back', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const WaleedTechApp());

    expect(find.byTooltip('Switch to Arabic'), findsOneWidget);
    await tester.tap(find.byTooltip('Switch to Arabic'));
    await tester.pumpAndSettle();

    expect(find.text('رفيق وليد التقني'), findsOneWidget);
    expect(find.text('استكشف المجالات التقنية'), findsOneWidget);
    expect(find.text('هندسة قواعد البيانات'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('رفيق وليد التقني'))),
      TextDirection.rtl,
    );

    await tester.tap(find.byTooltip('Switch to English'));
    await tester.pumpAndSettle();
    expect(find.text('Waleed Tech Companion'), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.text('Waleed Tech Companion'))),
      TextDirection.ltr,
    );
  });

  testWidgets('Arabic topic opens a real localized details route', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.tap(find.byTooltip('Switch to Arabic'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('هندسة قواعد البيانات'), 140);
    // A lazily built element may still be partially below the test viewport.
    await tester.ensureVisible(find.text('هندسة قواعد البيانات'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('هندسة قواعد البيانات'));
    await tester.pumpAndSettle();

    expect(find.text('SQL Server والاعتمادية والأداء'), findsOneWidget);
    expect(find.textContaining('ستُنشر الأدلة الموثّقة'), findsOneWidget);
  });
}


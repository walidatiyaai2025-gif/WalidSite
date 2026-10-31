import 'package:flutter_test/flutter_test.dart';
import 'package:waleed_tech_companion/main.dart';

void main() {
  testWidgets('home renders engineering topics without invented metrics', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const WaleedTechApp());

    expect(find.text('Waleed Tech Companion'), findsOneWidget);
    expect(find.text('Explore engineering topics'), findsOneWidget);
    expect(find.text('Database Engineering'), findsOneWidget);
    expect(find.text('Software Engineering'), findsOneWidget);
    // ListView builds items lazily: scroll to the last topic before asserting.
    await tester.scrollUntilVisible(find.text('AI Engineering'), 150);
    expect(find.text('AI Engineering'), findsOneWidget);
  });

  testWidgets('topic card navigates to a real details screen', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(const WaleedTechApp());
    await tester.tap(find.text('Database Engineering'));
    await tester.pumpAndSettle();

    expect(find.text('SQL Server, reliability and performance'), findsOneWidget);
    expect(find.textContaining('Verified guides'), findsOneWidget);

    await tester.pageBack();
    await tester.pumpAndSettle();

    expect(find.text('Explore engineering topics'), findsOneWidget);
  });
}

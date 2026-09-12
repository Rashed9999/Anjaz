import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/access/domain/repositories/access_repo.dart';
import 'package:amial_pay/features/merchant/screens/merchant_adaptive_shell.dart';

/// مستودعُ وصولٍ مزيَّف — الحارسُ يقيس تخطيطاً لا بيانات.
/// (نفسُ نمط `screens_widget_test.dart` القائم.)
class _MockAccessRepo extends Mock implements AccessRepo {}

/// AMIAL-SHELL-OVERLAP-001 — **زرّان في موضعٍ واحد.**
///
/// ══════════════════════════════════════════════════════════════════════
/// غلافُ التاجر يطفو بزرّ القائمة فوق شاشة القطاع. وشاشاتُ القطاعات لها
/// `AppBar` وأزرارُها في `actions` — **وفي العربيّة تُرسَم `actions`
/// يساراً**. وكان الزرُّ `Alignment.topLeft` فوقعا في موضعٍ واحد:
/// دائرةٌ كبيرةٌ تعلو أيقونةَ الشاشة. (رآه صاحبُ المشروع في لقطة.)
///
/// **ولا يمسكه تحليلٌ ولا مُصرِّف**: التخطيطُ صحيحٌ نحويّاً، والشاشتان
/// تُبنَيان بلا شكوى. لا يُرى إلّا بقياس **الإحداثيّات** — أو بعينِ إنسان.
///
/// فالحارسُ يبني الغلافَ حول شاشةٍ لها `AppBar` بأزرار، ويقيس أنّ
/// مستطيلَ زرِّ الغلاف **لا يتقاطع** مع مستطيل زرّ الشاشة.
void main() {
  setUp(() {
    Get.reset();
    Get.put<AccessController>(AccessController(repo: _MockAccessRepo()));
  });

  tearDown(Get.reset);

  /// شاشةُ قطاعٍ نموذجيّة: `AppBar` بعنوانٍ وزرٍّ في `actions` — كما في
  /// `MerchantRetailHomeScreen` و`MerchantQuickSaleHomeScreen`.
  Widget sectorHome() => Scaffold(
        appBar: AppBar(
          title: const Text('بقالة النور'),
          actions: [
            IconButton(
              key: const Key('sector-action'),
              icon: const Icon(Icons.apps_rounded),
              onPressed: () {},
            ),
          ],
        ),
        body: const SizedBox.expand(),
      );

  testWidgets('زرُّ الغلاف لا يتقاطع مع زرّ شاشة القطاع', (tester) async {
    await tester.pumpWidget(
      GetMaterialApp(home: MerchantAdaptiveShell(child: sectorHome())),
    );
    await tester.pumpAndSettle();

    final shellBtn = find.widgetWithIcon(IconButton, Icons.menu_rounded);
    final sectorBtn = find.byKey(const Key('sector-action'));

    expect(shellBtn, findsOneWidget, reason: 'زرُّ قائمة الغلاف غائب');
    expect(sectorBtn, findsOneWidget, reason: 'زرُّ الشاشة غائب');

    final a = tester.getRect(shellBtn);
    final b = tester.getRect(sectorBtn);

    expect(a.overlaps(b), isFalse,
        reason: '**زرُّ الغلاف يعلو زرَّ الشاشة** — '
            'الغلاف $a · الشاشة $b. وهو «التداخل في القائمة في الأعلى».');
  });

  testWidgets('وزرُّ الغلاف يبقى مضغوطاً ويفتح الدرج', (tester) async {
    // **والثمنُ يُقاس أيضاً**: نقلُ الزرّ لا يجوز أن يُفقده وظيفتَه.
    // (القاعدة التاسعة: زرٌّ لم يُضغط ليس مبنيّاً.)
    await tester.pumpWidget(
      GetMaterialApp(home: MerchantAdaptiveShell(child: sectorHome())),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithIcon(IconButton, Icons.menu_rounded));
    await tester.pumpAndSettle();

    expect(find.byType(MerchantAdaptiveDrawer), findsOneWidget,
        reason: 'الزرُّ نُقل ولم يعد يفتح الدرج — استُبدل تداخلٌ بزرٍّ ميّت');
  });
}

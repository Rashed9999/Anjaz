// AMIAL-DAILY-MOVEMENT-001 — **مصفوفةُ الحركة تُرسَم، والغائبُ يُقال.**
//
// ══════════════════════════════════════════════════════════════════════
// حارسُ الخادم (`DailyMovementMatrixGuardTest`) يثبت أنّ الأرقام صحيحةٌ
// ومن مصادرها. **وهذا يثبت أنّها تصل العين** — وهما شيئان مختلفان:
// حقلٌ صحيحٌ في JSON لا يُرسَم إن أخطأ القارئُ اسمَه، ولا خطأ في أيّ
// سجلّ. (وهو نمطُ «مبنيٌّ ولا يُوصَل إليه» الأكثرُ تكراراً في المشروع.)
//
// **والحالةُ الحرجةُ هي الصفُّ الغائب**: الخادمُ يرسل `available:false`
// ومعه سببُه، **والشاشةُ يجب أن تكتب السببَ لا صفراً**. صفرٌ هنا يُقرأ
// «لم يقع شيءٌ اليوم» وهو كذب. (القاعدة السابعة.)

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amial_pay/features/merchant/domain/repositories/merchant_repo.dart';
import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';

class _MockMerchantRepo extends Mock implements MerchantRepo {}

Map<String, dynamic> _row(String code, String label, String direction,
        {String cash = '0.0000',
        String amialPay = '0.0000',
        String credit = '0.0000',
        String total = '0.0000'}) =>
    {
      'code': code, 'label_ar': label, 'direction': direction,
      'available': true, 'cash': cash, 'amial_pay': amialPay,
      'credit': credit, 'total': total, 'count': 1, 'source': 'قياس',
    };

Map<String, dynamic> _report({required List<Map<String, dynamic>> rows}) => {
      'contract_version': 'merchant-financial-truth/v1',
      'period': {'from': '2026-09-05', 'to': '2026-09-05'},
      'vertical': 'retail',
      'currency': 'YER',
      'sales': {'gross': '0', 'count': 0, 'by_payment_method': {}, 'source': 'merchant_sales'},
      'collections': {'count': 0},
      'wallet': {'balance': '0'},
      'receivables': {'known': true, 'amount': '0', 'source': 'قياس'},
      'movement': {
        'contract': 'daily-movement/v1',
        'columns': ['cash', 'amial_pay', 'credit'],
        'column_labels_ar': {
          'cash': 'نقدي', 'amial_pay': 'أميال باي', 'credit': 'آجل',
        },
        'rows': rows,
        'net_cash': {
          'label_ar': 'صافي النقد من حركة اليوم',
          'amount': '300.0000',
          'note_ar': 'نقدُ الدرج وحدَه.',
        },
      },
    };

Future<void> _pump(WidgetTester t, Map<String, dynamic> report) async {
  final c = MerchantController(repo: _MockMerchantRepo());
  Get.put<MerchantController>(c, permanent: true);
  c.financialReport.value = report;

  await t.pumpWidget(GetMaterialApp(
    home: Directionality(
      textDirection: TextDirection.rtl,
      child: const FinancialTruthReportScreen(dailyOnly: true),
    ),
  ));
  await t.pump();
}

void main() {
  tearDown(Get.reset);

  testWidgets('المصفوفةُ تُرسَم بصفوفها الأربعة وصافيها', (t) async {
    await _pump(t, _report(rows: [
      _row('sale', 'المبيع', 'in', cash: '500.0000', credit: '900.0000', total: '1400.0000'),
      _row('sale_return', 'مرتجع المبيع', 'out'),
      _row('purchase', 'الشراء', 'out', cash: '200.0000', total: '200.0000'),
      _row('purchase_return', 'مرتجع الشراء', 'in'),
    ]));

    expect(find.text('الحركة اليومية الكاملة'), findsOneWidget);

    for (final label in ['المبيع', 'مرتجع المبيع', 'الشراء', 'مرتجع الشراء']) {
      expect(find.text(label), findsWidgets, reason: 'صفُّ «$label» لا يُرسَم');
    }

    expect(find.text('صافي النقد من حركة اليوم'), findsOneWidget,
        reason: 'الصافي النقديُّ لا يصل العين');
  });

  testWidgets('والصفُّ الغائبُ يكتب سببَه ولا يُرسَم صفراً', (t) async {
    const why = 'لا مورّدين في قطاعك — فلا بضاعةَ تُردّ إليهم';

    await _pump(t, _report(rows: [
      _row('sale', 'المبيع', 'in', cash: '500.0000', total: '500.0000'),
      _row('sale_return', 'مرتجع المبيع', 'out'),
      {
        'code': 'purchase', 'label_ar': 'الشراء', 'direction': 'out',
        'available': false, 'unavailable_reason_ar': why,
        'cash': null, 'amial_pay': null, 'credit': null,
        'total': null, 'count': null, 'source': null,
      },
      {
        'code': 'purchase_return', 'label_ar': 'مرتجع الشراء', 'direction': 'in',
        'available': false, 'unavailable_reason_ar': why,
        'cash': null, 'amial_pay': null, 'credit': null,
        'total': null, 'count': null, 'source': null,
      },
    ]));

    expect(find.text(why), findsNWidgets(2),
        reason: 'الغيابُ لم يُكتب — والقارئُ لا يعرف أهو عطلٌ أم قطاع');
  });

  testWidgets('ولا تنهار الشاشةُ إن غابت المصفوفةُ كلُّها', (t) async {
    // **خادمٌ أقدمُ من التطبيق** — نشرةٌ لم تصل بعد. والشاشةُ تبقى تعمل
    // بما تعرفه، ولا تسقط على مفتاحٍ ناقص.
    final old = _report(rows: const [])..remove('movement');

    await _pump(t, old);

    expect(find.text('المبيعات التشغيلية'), findsOneWidget);
    expect(find.text('الحركة اليومية الكاملة'), findsNothing);
  });
}

// AMIAL-SALES-BREAKDOWN-001 — **التقريرُ يصل العين، والرفضُ يصلها بنصّه.**
//
// ══════════════════════════════════════════════════════════════════════
// حارسُ الخادم (`SalesBreakdownGuardTest`) يثبت أنّ الأرقام تُحسب صحيحةً:
// المرتجعُ مطروح، والتكلفةُ المجهولةُ معزولة. **وهذا يثبت ما يقع في يد
// التاجر** — وثلاثةٌ منه لا يراها الخادمُ إطلاقاً:
//
//   ① **الأصنافُ والتصنيفاتُ تُقرأ من الردّ.** حقلٌ صحيحٌ في JSON لا
//      يُرسَم إن أخطأ القارئُ اسمَه، ولا خطأ في أيّ سجلّ.
//   ② **والرفضُ يصل بنصّه.** «تعذّر» وحدَها تُرسل التاجرَ إلى الدعم على
//      قفلِ باقةٍ يفتحه هو بضغطةٍ لو قُرئ.
//   ③ **والتقريرُ القديمُ لا يبقى معروضاً بعد رفض.** رقمُ الأمسِ فوق
//      شاشةِ اليومِ يُقرأ رقمَ اليوم.

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/domain/repositories/cashier_repo.dart';

class _MockRepo extends Mock implements CashierRepo {
  @override
  InternalFinalCallback<void> get onStart =>
      InternalFinalCallback<void>(callback: () {});

  @override
  InternalFinalCallback<void> get onDelete =>
      InternalFinalCallback<void>(callback: () {});
}

Response _ok(Map<String, dynamic> meta) => Response(
      statusCode: 200,
      body: {'success': true, 'code': 'OK', 'message': '', 'errors': {}, 'meta': meta},
    );

const _report = {
  'range': {'from': '2026-08-07', 'to': '2026-09-05', 'days': 30},
  'totals': {
    'revenue': '5000.0000', 'cost': '3000.0000', 'profit': '2000.0000',
    'margin_percent': '40.00', 'qty': '25', 'items_count': 2, 'categories_count': 2,
  },
  'items': [
    {
      'name': 'حليب', 'category': 'ألبان', 'qty': '20', 'returned_qty': '0',
      'revenue': '4000.0000', 'cost': '2400.0000', 'profit': '1600.0000',
      'margin_percent': '40.00',
    },
    {
      'name': 'لبن', 'category': 'ألبان', 'qty': '5', 'returned_qty': '18',
      'revenue': '1000.0000', 'cost': '600.0000', 'profit': '400.0000',
      'margin_percent': '40.00',
    },
  ],
  'categories': [
    {
      'category': 'ألبان', 'items': 2, 'qty': '25', 'revenue': '5000.0000',
      'cost': '3000.0000', 'profit': '2000.0000', 'margin_percent': '40.00',
    },
  ],
  'cost_coverage': {'unknown_cost_lines': 0, 'unknown_cost_revenue': '0.0000', 'note': null},
};

void main() {
  late _MockRepo repo;
  late CashierController c;

  setUp(() {
    repo = _MockRepo();
    when(() => repo.heldTickets())
        .thenAnswer((_) async => _ok({'tickets': [], 'count': 0, 'max_open': 20}));
    c = CashierController(repo: repo);
    Get.put<CashierController>(c);
  });

  tearDown(Get.reset);

  test('① الأصناف والتصنيفات تُقرأ من الردّ', () async {
    when(() => repo.salesBreakdown(from: any(named: 'from'), to: any(named: 'to')))
        .thenAnswer((_) async => _ok(Map<String, dynamic>.from(_report)));

    await c.loadSalesBreakdown(from: '2026-08-07');

    expect(c.breakdownItems.length, 2,
        reason: 'الخادم يُرسل الأصناف والتطبيق لا يلتقطها — '
            'فالتقريرُ ينتهي عند JSON');
    expect(c.breakdownItems.first['name'], 'حليب');
    expect(c.breakdownCategories.length, 1);
    expect(c.breakdownCategories.first['category'], 'ألبان');

    // المرتجعُ يصل الشاشةَ أيضاً — وإلّا قُرئ الرقمُ الصغيرُ ضعفاً لا ردّاً.
    expect(c.breakdownItems[1]['returned_qty'], '18');
    expect(c.salesBreakdown.value?['range']['days'], 30,
        reason: 'المدى لا يصل — و«٥٠٠٠ ريالاً» بلا مدىً ليست رقماً');
  });

  test('② والرفضُ يصل بنصّه ③ ولا يبقى تقريرُ الأمس معروضاً', () async {
    when(() => repo.salesBreakdown(from: any(named: 'from'), to: any(named: 'to')))
        .thenAnswer((_) async => _ok(Map<String, dynamic>.from(_report)));
    await c.loadSalesBreakdown();
    expect(c.breakdownItems, isNotEmpty);

    when(() => repo.salesBreakdown(from: any(named: 'from'), to: any(named: 'to')))
        .thenAnswer((_) async => const Response(
            statusCode: 402,
            body: {'success': false, 'message': 'تقاريرُ الربحيّة في باقة الأعمال'}));

    await c.loadSalesBreakdown();

    expect(c.lastError.value, contains('باقة'),
        reason: 'الرفضُ لا يصل بنصّه — فيُرسَل التاجرُ إلى الدعم على قفلٍ '
            'يفتحه هو بضغطة');
    expect(c.breakdownItems, isEmpty,
        reason: 'تقريرُ الأمسِ بقي فوق شاشةِ اليوم — فيُقرأ رقمَ اليوم');
  });
}

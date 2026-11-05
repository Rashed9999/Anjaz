// AMIAL-NEGATIVE-STOCK-001 — **التحذيرُ يصل الكاشير، ولا يصله بلا سبب.**
//
// ══════════════════════════════════════════════════════════════════════
// حارسُ الخادم (`NegativeStockReachesTheMerchantGuardTest`) يثبت أنّ البيع
// **لا يُقفَل** حين ينقص المخزون، وأنّ الردَّ يحمل ما نزل تحت الصفر.
// **وهذا يثبت أنّ ما حُمل يصل العين** — وهما شيئان مختلفان: حقلٌ صحيحٌ في
// JSON لا يُرسَم إن أخطأ القارئُ اسمَه، ولا خطأ في أيّ سجلّ.
//
// **وحالتان لا واحدة، والثانيةُ هي التي تُفقِد التحذيرَ معناه:**
//
//   ① بيعةٌ نزلت بالمخزون تحت الصفر ⇒ يُلتقَط ما نزل ويُقرأ.
//   ② **وبيعةٌ سليمةٌ لا تحمل تحذيراً** — تحذيرٌ يظهر بلا سببٍ يُعوّد
//      القارئَ على تجاهله يومَ يصدق. (وهو درسُ لافتة «كُسرت السلسلة».)

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

Response _sale(Map<String, dynamic> extra) => Response(
      statusCode: 200,
      body: {
        'success': true,
        'code': 'SALE_RECORDED',
        'message': 'تم تسجيل البيع',
        'errors': {},
        'meta': {
          'sale': {'sale_ulid': '01SALE0000000000000000000', 'total_amount': '1500'},
          ...extra,
        },
      },
    );

void main() {
  late _MockRepo repo;
  late CashierController c;

  setUp(() {
    repo = _MockRepo();
    when(() => repo.heldTickets())
        .thenAnswer((_) async => const Response(statusCode: 200, body: {'meta': {'tickets': []}}));
    c = CashierController(repo: repo);
    Get.put<CashierController>(c);
  });

  tearDown(Get.reset);

  Future<void> sell() async {
    c.cart.add(CartLine(name: 'حليب', price: 300, qty: 5, productId: 7));
    await c.recordSale(total: 1500, method: 'cash');
  }

  test('① ما نزل تحت الصفر يُلتقَط ويُقرأ', () async {
    when(() => repo.recordSale(any())).thenAnswer((_) async => _sale({
          'negative_stock': [
            {'product': 'حليب', 'on_hand': '-3.000', 'shortfall': '3.000'},
          ],
        }));

    await sell();

    expect(c.lastNegativeStock.length, 1,
        reason: 'الخادم قال إنّ المخزون نزل تحت الصفر والتطبيق لم يلتقطه — '
            'فالإشارة تنتظر مالكاً يفتح شاشة بعد يومين');
    expect(c.lastNegativeStock[0]['product'], 'حليب');
    expect(c.lastNegativeStock[0]['shortfall'], '3.000');
  });

  test('② وبيعة سليمة لا تحمل تحذيراً', () async {
    when(() => repo.recordSale(any())).thenAnswer((_) async => _sale({}));

    await sell();

    expect(c.lastNegativeStock, isEmpty,
        reason: 'تحذير على بيعة سليمة — فيُعتاد تجاهله يوم يصدق');
  });

  test('③ والتحذير لا يبقى معلّقاً على البيعة التالية', () async {
    when(() => repo.recordSale(any())).thenAnswer((_) async => _sale({
          'negative_stock': [
            {'product': 'حليب', 'on_hand': '-3.000', 'shortfall': '3.000'},
          ],
        }));
    await sell();
    expect(c.lastNegativeStock, isNotEmpty);

    // بيعةٌ ثانيةٌ سليمة
    when(() => repo.recordSale(any())).thenAnswer((_) async => _sale({}));
    await sell();

    expect(c.lastNegativeStock, isEmpty,
        reason: 'تحذير الأمس بقي على بيعة اليوم — فيُقرأ نقصٌ لا وجود له');
  });
}

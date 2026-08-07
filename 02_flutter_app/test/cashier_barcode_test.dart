// AMIAL-CASHIER-BARCODE-001 — اختبار منطق مسح باركود الكاشير (مستودع مزيّف).
//
// يتحقّق من: المسح يضيف المنتج للسلّة بـ product_id (ليُخصم المخزون)، الباركود
// المجهول يعيد not_found (ليعرض التطبيق الإنشاء)، ودمج الكميات لنفس المنتج.

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/domain/repositories/cashier_repo.dart';

class _MockCashierRepo extends Mock implements CashierRepo {}

void main() {
  late _MockCashierRepo repo;
  late CashierController c;

  setUp(() {
    repo = _MockCashierRepo();
    c = CashierController(repo: repo);
  });

  test('المسح الناجح يضيف المنتج للسلّة بـ product_id', () async {
    when(() => repo.lookupBarcode('6291000001234')).thenAnswer((_) async => Response(
          statusCode: 200,
          body: {'success': true, 'meta': {'product': {'id': 42, 'name': 'أرز', 'price': '750'}}},
        ));

    final result = await c.lookupAndAddByBarcode('6291000001234');

    expect(result, 'added');
    expect(c.cart.length, 1);
    expect(c.cart.first.productId, 42);
    expect(c.cart.first.name, 'أرز');
    expect(c.cart.first.price, 750);
    // toJson يحمل product_id (ليخصم المخزون في الباكند)
    expect(c.cart.first.toJson()['product_id'], 42);
  });

  test('الباركود المجهول يعيد not_found بلا إضافة', () async {
    when(() => repo.lookupBarcode(any())).thenAnswer((_) async => Response(
          statusCode: 404, body: {'success': false, 'code': 'NOT_FOUND'}));

    final result = await c.lookupAndAddByBarcode('0000');

    expect(result, 'not_found');
    expect(c.cart, isEmpty);
  });

  test('مسح نفس المنتج مرتين يدمج الكمية', () async {
    when(() => repo.lookupBarcode('6291000001234')).thenAnswer((_) async => Response(
          statusCode: 200,
          body: {'success': true, 'meta': {'product': {'id': 42, 'name': 'أرز', 'price': '750'}}},
        ));

    await c.lookupAndAddByBarcode('6291000001234');
    await c.lookupAndAddByBarcode('6291000001234');

    expect(c.cart.length, 1);
    expect(c.cart.first.qty, 2);
  });

  test('عرض السعر يُحترم (offer_price) عند الإضافة', () {
    c.addProductToCart({'id': 7, 'name': 'سكر', 'price': '500', 'offer_price': '450'});
    expect(c.cart.first.price, 450);
    expect(c.cart.first.productId, 7);
  });
}

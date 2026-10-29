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

  test('مزامنة قائمة أميال التاجر تُبقي UUID ولا تخلطه مع معرفات أميال باي', () async {
    const uuid = '10000000-0000-4000-8000-000000000011';
    when(() => repo.products(search: null, includeVariantParents: false))
        .thenAnswer((_) async => Response(statusCode: 200, body: {
          'success': true,
          'meta': {
            'catalog_source': 'amial-merchant',
            'products': [{'id': uuid, 'merchant_product_id': uuid, 'name': 'مياه', 'price': '30.0000', 'source': 'amial-merchant'}],
          },
        }));
    await c.loadProducts();
    expect(c.usingIndependentCatalog.value, true);
    expect(c.products.single['id'], uuid);
    c.addProductToCart(c.products.single);
    expect(c.cart.single.productId, isNull);
    expect(c.cart.single.merchantProductId, uuid);
    expect(c.cart.single.toJson()['merchant_product_id'], uuid);
    expect(c.cart.single.toJson().containsKey('product_id'), false);
    expect(c.containsIndependentProducts, true);
  });

  test('الكاميرا تمسح باركود أميال التاجر وتضيف كامل العبوة للسلة', () async {
    const uuid = '10000000-0000-4000-8000-000000000011';
    when(() => repo.lookupBarcode('6291999000111')).thenAnswer((_) async =>
        Response(statusCode: 200, body: {
          'success': true,
          'meta': {
            'product': {
              'id': uuid, 'merchant_product_id': uuid,
              'name': 'علبة مياه', 'price': '15.0000',
              'source': 'amial-merchant',
            },
            'pack_size': '24',
          },
        }));
    expect(await c.lookupAndAddByBarcode('6291999000111'), 'added');
    expect(c.cart.single.qty, 24);
    expect(c.cart.single.productId, isNull);
    expect(c.cart.single.merchantProductId, uuid);
    expect(c.cartTotal, 360.0);
    expect(await c.lookupAndAddByBarcode('6291999000111'), 'added');
    expect(c.cart.single.qty, 48);
  });

  test('لا يخلط أصناف المستودع القديم والجديد في السلة', () {
    c.addProductToCart({'id':42, 'name':'أرز', 'price':'100'});
    c.addProductToCart({
      'id':'10000000-0000-4000-8000-000000000011',
      'name':'مياه', 'price':'10.0000', 'source':'amial-merchant',
    });
    expect(c.cart.length, 1);
    expect(c.lastError.value, contains('لا يمكن خلط'));
  });

  test('باركود غير موجود في الخدمة المستقلة لا يتحول إلى إنشاء قديم', () async {
    when(() => repo.lookupBarcode('6291999000888')).thenAnswer((_) async =>
        Response(statusCode: 422, body: {
          'success': false,
          'code': 'MERCHANT_BARCODE_NOT_FOUND',
          'message': 'أضف الباركود من لوحة أميال التاجر',
        }));
    expect(await c.lookupAndAddByBarcode('6291999000888'), 'error');
    expect(c.cart, isEmpty);
  });

  test('سلة مستقلة تحجز أصناف المحفظة قبل عرض QR بمبلغ الخادم', () async {
    const id = '10000000-0000-4000-8000-000000000031';
    c.addProductToCart({
      'id': id, 'name': 'صنف', 'price': '100.0000',
      'source': 'amial-merchant',
    }, quantity: 2);
    when(() => repo.prepareWalletOrder(any())).thenAnswer((invocation) async {
      final sent = invocation.positionalArguments.single as Map;
      expect(sent['expected_total'], '200.0000');
      expect(sent['items'], [
        {'merchant_product_id': id, 'qty': 2},
      ]);
      return Response(statusCode: 200, body: {
        'success': true,
        'meta': {
          'order': {'id': '20000000-0000-4000-8000-000000000031', 'total': '200.0000'},
          'request': {'id': 777, 'short_code': 'QR-123456'},
          'short_code': 'QR-123456',
        },
      });
    });
    final prepared = await c.prepareWalletOrder(
        requestKey: 'aaaa0000-0000-4000-8000-000000000031',
        expectedTotal: 200);
    expect(prepared?['short_code'], 'QR-123456');
    expect(c.cart.first.merchantProductId, id);
    expect(c.cartTotal, 200);
  });

  test('تأكيد الدفع يقرأ إيصال الخادم ولا يمسح السلة إذا فشل', () async {
    const id = '10000000-0000-4000-8000-000000000032';
    c.addProductToCart({
      'id': id, 'name': 'صنف', 'price': '125.0000',
      'source': 'amial-merchant',
    });
    when(() => repo.completeWalletOrder(any(), any())).thenAnswer((_) async =>
        Response(statusCode: 503, body: {
          'success': false,
          'message': 'تم تحصيل المحفظة، يلزم مطابقة إيصال التاجر',
        }));
    final bad = await c.completeWalletOrder(
        '20000000-0000-4000-8000-000000000032', 'AMIALTXN123456789012');
    expect(bad, isNull);
    expect(c.cart.length, 1);
    expect(c.lastError.value, contains('تم تحصيل'));

    when(() => repo.completeWalletOrder(any(), any())).thenAnswer((_) async =>
        Response(statusCode: 200, body: {
          'success': true,
          'meta': {
            'sale': {
              'order_id': '20000000-0000-4000-8000-000000000032',
              'payment_method': 'amial_pay',
              'items': [{'name':'صنف','qty':1,'price':'125.0000'}],
            },
          },
        }));
    final receipt = await c.completeWalletOrder(
        '20000000-0000-4000-8000-000000000032', 'AMIALTXN123456789012');
    expect(receipt?['payment_method'], 'amial_pay');
    expect(c.cart.length, 1); // UI clears only after confirmed receipt
  });

  test('عرض السعر يُحترم (offer_price) عند الإضافة', () {
    c.addProductToCart({'id': 7, 'name': 'سكر', 'price': '500', 'offer_price': '450'});
    expect(c.cart.first.price, 450);
    expect(c.cart.first.productId, 7);
  });
}

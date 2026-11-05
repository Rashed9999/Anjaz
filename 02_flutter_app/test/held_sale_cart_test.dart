// AMIAL-HELD-SALE-001 — **السلّةُ تُعلَّق وتعود، ولا تضيع في الطريق.**
//
// ══════════════════════════════════════════════════════════════════════
// حارسُ الخادم (`HeldSaleOpenTicketsGuardTest`) يثبت أنّ التذكرة تُحفَظ
// ولا تُستأنَف مرّتين. **وهذا يثبت ما يقع في يد الكاشير**، وثلاثةٌ منه لا
// يراها الخادمُ إطلاقاً:
//
//   ① **السلّةُ لا تُفرَّغ قبل نجاح الحفظ.** تفريغُها متفائلاً يفقدها إن
//      سقط الطلب — وهو أسوأُ من ألّا تُعلَّق أصلاً.
//   ② **والتذكرةُ تُنسى مع السلّة.** بقاءُ `activeTicket` بعد تفريغٍ
//      يدويٍّ يجعل الفاتورةَ التاليةَ تُختَم بتذكرةٍ لا علاقةَ لها بها،
//      فيُقرأ مصيرُ التذكرة خطأً: «دُفعت» وهي لم تُدفَع.
//   ③ **والاستئنافُ يُعيد الأصنافَ بأسعارها وكمّيّاتها** — لا أسماءَها
//      وحدَها.

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

Response _ok(Map<String, dynamic> meta, {int code = 200}) => Response(
      statusCode: code,
      body: {'success': true, 'code': 'OK', 'message': '', 'errors': {}, 'meta': meta},
    );

const _ticket = {
  'ticket_ulid': '01HELDTICKETULID0000000000',
  'label': 'أبو محمد',
  'items': [
    {'name': 'حليب', 'qty': 2, 'price': '300.0000', 'product_id': 7},
    {'name': 'خبز', 'qty': 1, 'price': '100.0000', 'product_id': null},
  ],
  'items_count': 2,
  'total': '700.0000',
  'status': 'resumed',
  'opened_by_name': 'راشد المعربي',
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

  test('① السلة لا تُفرَّغ إن سقط حفظ التذكرة', () async {
    when(() => repo.holdCart(any())).thenAnswer((_) async => const Response(
        statusCode: 422,
        body: {'success': false, 'message': 'بلغتَ ٢٠ تذكرة مفتوحة'}));

    c.cart.add(CartLine(name: 'حليب', price: 300, qty: 2, productId: 7));

    final ok = await c.holdCart(label: 'أبو محمد');

    expect(ok, isFalse);
    expect(c.cart.length, 1,
        reason: 'فُرّغت السلة رغم فشل الحفظ — وضاعت أصنافٌ مُسحت بالباركود');
    expect(c.lastError.value, contains('تذكرة'),
        reason: 'الرفض لا يصل الكاشير بنصّه');
  });

  test('وتُفرَّغ عند النجاح', () async {
    when(() => repo.holdCart(any()))
        .thenAnswer((_) async => _ok({'ticket': _ticket}, code: 201));

    c.cart.add(CartLine(name: 'حليب', price: 300, qty: 2, productId: 7));

    expect(await c.holdCart(label: 'أبو محمد'), isTrue);
    expect(c.cart, isEmpty);
  });

  test('③ الاستئناف يعيد الأصناف بأسعارها وكمّياتها', () async {
    when(() => repo.resumeHeld(any()))
        .thenAnswer((_) async => _ok({'ticket': _ticket}));

    expect(await c.resumeHeld('01HELDTICKETULID0000000000'), isTrue);

    expect(c.cart.length, 2);
    expect(c.cart[0].name, 'حليب');
    expect(c.cart[0].qty, 2);
    expect(c.cart[0].price, 300);
    expect(c.cart[0].productId, 7,
        reason: 'معرّف المنتج ضاع — فلا يُخصم المخزون عند الدفع');
    expect(c.cartTotal, 700);

    expect(c.activeTicket.value, '01HELDTICKETULID0000000000',
        reason: 'التذكرة لا تُحفظ — فلا تُختَم ببيعتها ولا يُعرف مصيرها');
  });

  test('② والتذكرة تُنسى مع تفريغ السلة يدوياً', () async {
    when(() => repo.resumeHeld(any()))
        .thenAnswer((_) async => _ok({'ticket': _ticket}));

    await c.resumeHeld('01HELDTICKETULID0000000000');
    expect(c.activeTicket.value, isNotEmpty);

    c.clearCart();

    expect(c.activeTicket.value, isEmpty,
        reason: 'التذكرة بقيت معلّقة بالسلة الجديدة — فتُختَم فاتورةٌ '
            'لا علاقة لها بها، ويُقرأ مصير التذكرة خطأً');
  });
}

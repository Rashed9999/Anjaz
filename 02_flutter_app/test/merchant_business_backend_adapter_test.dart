// AMIAL-BUSINESS-UNIFY-U2 — اختبارات محوّل تطبيق Flutter إلى عقد أميال أعمال (/api/v1/mobile/*).
//
// تغطي:
// 1. الإيقاف الافتراضي (merchantBusinessBackendEnabled == false) وعدم تخزين أي رمز Bearer في SharedPreferences (B4).
// 2. بقاء التجار غير المرحّلين على مسارات أميال باي القديمة دون أي تغيير.
// 3. تشغيل المتحكمات الحقيقية (CashierController + CashierRefundController + CustomerCreditController)
//    عبر المحوّل مع توافق كامل للعقد (amial_pay -> wallet، amount_received -> tendered، ULID، غلاف success/meta) (B3).
// 4. طابور العمل دون اتصال (OfflineSaleQueue): نجاح + إعادة محاولة على 503 + فشل نهائي على 409 conflict + التراجع الآمن.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mocktail/mocktail.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_refund_controller.dart';
import 'package:amial_pay/features/merchant/controllers/customer_credit_controller.dart';
import 'package:amial_pay/features/merchant/domain/repositories/cashier_refund_repo.dart';
import 'package:amial_pay/features/merchant/domain/repositories/cashier_repo.dart';
import 'package:amial_pay/features/merchant/domain/repositories/customer_credit_repo.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_backend_config.dart';
import 'package:amial_pay/features/merchant/services/offline_sale_queue.dart';

class _MockApiClient extends Mock implements ApiClient {}

http.Response _jsonResponse(Map<String, dynamic> body, int statusCode) =>
    http.Response.bytes(
      utf8.encode(jsonEncode(body)),
      statusCode,
      headers: const {'content-type': 'application/json; charset=utf-8'},
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late InMemoryBusinessTokenStore secureStore;
  late MerchantBusinessBackendConfig config;
  late _MockApiClient legacyApi;

  setUp(() async {
    Get.reset();
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    secureStore = InMemoryBusinessTokenStore();
    config = MerchantBusinessBackendConfig(
      sharedPreferences: prefs,
      tokenStore: secureStore,
      allowInsecureHttpForTesting: true,
    );
    legacyApi = _MockApiClient();
  });

  tearDown(() {
    Get.reset();
  });

  test('الافتراض لجميع التجار هو false، والرمز يُحفظ في المخزن المشفّر فقط لا في SharedPreferences (B4)', () async {
    expect(config.merchantBusinessBackendEnabled('merchant-100'), isFalse);
    expect(config.isActiveMerchantEnabled, isFalse);
    expect(config.isReadyForActiveMerchant, isFalse);

    final calledBusinessPaths = <String>[];
    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        calledBusinessPaths.add(req.url.path);
        return _jsonResponse({}, 200);
      }),
    );

    when(() => legacyApi.getData('/api/v1/amial/merchant/cashier/products', query: any(named: 'query')))
        .thenAnswer((_) async => const Response(statusCode: 200, body: {'success': true, 'meta': {'products': []}}));

    final repo = CashierRepo(apiClient: legacyApi, businessAdapter: adapter);
    final res = await repo.products(search: 'حليب');

    expect(res.statusCode, 200);
    expect(calledBusinessPaths, isEmpty);
    verify(() => legacyApi.getData('/api/v1/amial/merchant/cashier/products', query: {'search': 'حليب'})).called(1);

    // تفعيل التاجر يجب أن يضع التوكن في المخزن المشفّر فقط وليس في SharedPreferences.
    await config.enableForMerchant(
      merchantId: 'merchant-secure-1',
      bearerToken: 'abm_secret_tok',
      baseUrl: 'https://merchant.example.test',
    );
    expect(prefs.getString('amial_merchant_biz_token_v1_merchant-secure-1'), isNull);
    expect(await secureStore.read('amial_merchant_biz_token_v1_merchant-secure-1'), 'abm_secret_tok');
    expect(config.businessTokenFor('merchant-secure-1'), 'abm_secret_tok');
  });

  test('المتحكمات الحقيقية (Cashier/Refund/Credit) تعمل عبر المحوّل بتوافق عقد كامل (B3)', () async {
    await config.enableForMerchant(
      merchantId: 'tenant-ulid-1',
      bearerToken: 'abm_test_token_123',
      baseUrl: 'https://merchant.example.test',
    );

    const invoiceUlid = '01JTESTULID000000000000077';
    final requests = <http.Request>[];
    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        requests.add(req);
        if (req.url.path == '/api/v1/mobile/pos/barcode') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'kind': 'scale',
              'item': {'id': 9, 'name': 'سمك ديرك', 'price': '3000.0000', 'scanned_quantity': '1.250'},
            },
          }, 200);
        }
        if (req.url.path == '/api/v1/mobile/pos/sales' && req.method == 'POST') {
          final sent = jsonDecode(req.body) as Map<String, dynamic>;
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'replayed': false,
            'data': {
              'currency': 'YER',
              'invoice': {
                'id': 77,
                'ulid': invoiceUlid,
                'number': 'INV-000077',
                'status': sent['method'] == 'wallet' ? 'awaiting_payment' : 'paid',
                'payment_method': sent['method'],
                'total': '6000.0000',
                'wallet_intent': sent['method'] == 'wallet'
                    ? {'intent_id': '01JINTENT88', 'short_code': 'PAY-88', 'qr_payload': 'amial://pay?intent=01JINTENT88'}
                    : null,
              },
            },
          }, 201);
        }
        if (req.url.path == '/api/v1/mobile/pos/sales/$invoiceUlid' && req.method == 'GET') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'invoice': {
                'id': 77,
                'ulid': invoiceUlid,
                'number': 'INV-000077',
                'total': '6000.0000',
                'returned_total': '0.0000',
                'lines': [
                  {'id': 501, 'product_id': 9, 'description': 'سمك ديرك', 'quantity': '2.0000', 'unit_price': '3000.0000'},
                ],
                'refundable': {
                  'refunded_so_far': '0.0000',
                  'remaining': '6000.0000',
                  'available_methods': ['cash'],
                },
              },
            },
          }, 200);
        }
        if (req.url.path == '/api/v1/mobile/pos/sales/$invoiceUlid/returns') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'replayed': false,
            'data': {
              'currency': 'YER',
              'return': {'id': 5, 'number': 'SR-000001', 'total': '3000.0000'},
            },
          }, 201);
        }
        if (req.url.path == '/api/v1/mobile/pos/held-carts' && req.method == 'GET') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'held_carts': [
                {
                  'id': 15,
                  'reference': 'HLD-000015',
                  'items': [{'product_id': 9, 'name': 'سمك ديرك', 'quantity': '1', 'unit_price': '3000'}],
                },
              ],
            },
          }, 200);
        }
        if (req.url.path == '/api/v1/mobile/pos/held-carts/15/resume') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'held_cart': {
                'id': 15,
                'reference': 'HLD-000015',
                'items': [{'product_id': 9, 'name': 'سمك ديرك', 'quantity': '2', 'unit_price': '3000'}],
              },
            },
          }, 200);
        }
        if (req.url.path == '/api/v1/mobile/contacts' && req.method == 'GET') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'contacts': [
                {'id': 12, 'name': 'بقالة الأمل', 'phone': '+96777000012', 'receivable': '1500.0000'},
              ],
            },
          }, 200);
        }
        if (req.url.path == '/api/v1/mobile/contacts/12/collect') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'replayed': false,
            'data': {
              'currency': 'YER',
              'payment': {'id': 99, 'number': 'CP-000099', 'amount': '1000.0000'},
              'remaining_receivable': '500.0000',
            },
          }, 201);
        }
        return _jsonResponse({'ok': true, 'data': {}}, 200);
      }),
    );

    final cashierRepo = CashierRepo(apiClient: legacyApi, businessAdapter: adapter);
    final refundRepo = CashierRefundRepo(apiClient: legacyApi, businessAdapter: adapter);
    final creditRepo = CustomerCreditRepo(apiClient: legacyApi, businessAdapter: adapter);

    final cashierCtrl = CashierController(repo: cashierRepo);
    final refundCtrl = CashierRefundController(repo: refundRepo);
    final creditCtrl = CustomerCreditController(repo: creditRepo);

    // 1) CashierController: مسح باركود ميزان + بيع نقدي عبر amount_received.
    expect(await cashierCtrl.lookupAndAddByBarcode('2100123012507'), 'added');
    final cashSale = await cashierCtrl.recordSale(total: 6000, method: 'cash', amountReceived: 6000);
    expect(cashSale, isNotNull);
    expect(cashSale!['ulid'], invoiceUlid);
    final cashReq = requests.firstWhere((r) => r.url.path == '/api/v1/mobile/pos/sales' && r.method == 'POST');
    final cashBody = jsonDecode(cashReq.body) as Map<String, dynamic>;
    expect(cashBody['method'], 'cash');
    expect(cashBody['tendered'], '6000.00');
    expect(cashReq.headers['Idempotency-Key'], isNotEmpty);

    // 2) CashierController: بيع محفظة (amial_pay -> wallet) يعيد wallet_intent QR!
    cashierCtrl.addToCart('سمك ديرك', 3000, productId: 9, quantity: 2);
    final walletSale = await cashierCtrl.recordSale(total: 6000, method: 'amial_pay');
    expect(walletSale, isNotNull);
    expect(walletSale!['payment_method'], 'wallet');
    expect((walletSale['wallet_intent'] as Map)['short_code'], 'PAY-88');

    // 3) CashierController: تفاصيل الفاتورة عبر ULID + استئناف تذكرة معلّقة.
    await cashierCtrl.loadSaleDetail(invoiceUlid);
    expect(cashierCtrl.saleDetail.value, isNotNull);
    expect((cashierCtrl.saleDetail.value!['sale'] as Map)['number'], 'INV-000077');

    await cashierCtrl.loadHeldTickets();
    expect(cashierCtrl.heldTickets.length, 1);
    expect(await cashierCtrl.resumeHeld('15'), isTrue);
    expect(cashierCtrl.cart.first.qty, 2);

    // 4) CashierRefundController: loadRefundable وcreate عبر ULID!
    await refundCtrl.loadRefundable(invoiceUlid);
    expect(refundCtrl.refundableInfo.value, isNotNull);
    expect(refundCtrl.refundableInfo.value!['remaining'], '6000.0000');

    final refundOk = await refundCtrl.create(
      saleUlid: invoiceUlid,
      amount: '3000.00',
      refundMethod: 'cash',
      items: [{'line_id': 501, 'qty': '1'}],
      reason: 'مرتجع اختباري',
    );
    expect(refundOk, isTrue);
    expect(refundCtrl.lastRefund.value!['number'], 'SR-000001');

    // 5) CustomerCreditController: loadCustomers وcollectCash عبر غلاف meta/success!
    await creditCtrl.loadCustomers();
    expect(creditCtrl.customers.length, 1);
    expect(creditCtrl.customers.first['name'], 'بقالة الأمل');

    final collected = await creditCtrl.collectCash(12, '1000.00', 'col-key-12');
    expect(collected, isNotNull);
    expect(collected!['remaining_receivable'], '500.0000');

    verifyZeroInteractions(legacyApi);
  });

  test('OfflineSaleQueue: إعادة المحاولة على 503، فشل نهائي على 409 conflict، والتراجع الآمن', () async {
    await config.enableForMerchant(
      merchantId: 'tenant-offline-1',
      bearerToken: 'abm_offline_tok',
      baseUrl: 'https://merchant.example.test',
    );

    int callCount = 0;
    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        callCount++;
        final body = jsonDecode(req.body) as Map<String, dynamic>;
        if (body['client_uuid'] == 'op-ok') {
          return _jsonResponse({'ok': true, 'replayed': true, 'data': {'invoice': {'id': 1}}}, 201);
        }
        if (body['client_uuid'] == 'op-503') {
          return _jsonResponse({'ok': false, 'message': 'unavailable'}, 503);
        }
        return _jsonResponse({'ok': false, 'code': 'IDEMPOTENCY_CONFLICT', 'message': 'مفتاح مكرر بحمولة مختلفة'}, 409);
      }),
    );

    final queue = OfflineSaleQueue(apiClient: legacyApi, businessAdapter: adapter);
    await queue.enqueue({'client_uuid': 'op-ok', 'method': 'cash', 'tendered': '500', 'items': [{'product_id': 1, 'quantity': '1'}]});
    await queue.enqueue({'client_uuid': 'op-503', 'method': 'cash', 'tendered': '500', 'items': [{'product_id': 1, 'quantity': '1'}]});
    await queue.enqueue({'client_uuid': 'op-409', 'method': 'cash', 'tendered': '500', 'items': [{'product_id': 1, 'quantity': '1'}]});

    final synced = await queue.sync();
    expect(synced, 1);
    expect(callCount, 3);

    final remaining = await queue.items();
    expect(remaining.length, 2);
    final retryItem = remaining.firstWhere((e) => e['client_uuid'] == 'op-503');
    final conflictItem = remaining.firstWhere((e) => e['client_uuid'] == 'op-409');
    expect(retryItem['_offline_state'], 'pending');
    expect(retryItem['_offline_backend'], 'business');
    expect(conflictItem['_offline_state'], 'failed');
    expect(conflictItem['_offline_failure_code'], 409);

    // التراجع عن التحويل (Rollback): الطلبات الجديدة تعود فوراً إلى المسار القديم، والعملية الموسومة بـ business لا تُرسل خطأً لـ legacy!
    await config.disableForMerchant('tenant-offline-1');
    expect(config.isReadyForActiveMerchant, isFalse);

    when(() => legacyApi.postData('/api/v1/amial/merchant/cashier/sales', any()))
        .thenAnswer((_) async => const Response(statusCode: 201, body: {'success': true}));

    final cashier = CashierRepo(apiClient: legacyApi, businessAdapter: adapter);
    final rollbackSale = await cashier.recordSale({'client_uuid': 'legacy-after-rollback', 'items': []});
    expect(rollbackSale.statusCode, 201);
    verify(() => legacyApi.postData('/api/v1/amial/merchant/cashier/sales', any())).called(1);

    final syncedAfterRollback = await queue.sync();
    expect(syncedAfterRollback, 0);
    verifyNoMoreInteractions(legacyApi);
  });
}

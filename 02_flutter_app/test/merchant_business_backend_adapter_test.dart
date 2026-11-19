// AMIAL-BUSINESS-UNIFY-U2 — اختبارات محوّل تطبيق Flutter إلى عقد أميال أعمال (/api/v1/mobile/*).
//
// تغطي:
// 1. الإيقاف الافتراضي (merchantBusinessBackendEnabled == false) لجميع التجار بلا تحويل تلقائي.
// 2. بقاء التجار غير المرحّلين على مسارات أميال باي القديمة دون أي تغيير.
// 3. توجيه التاجر المرحّل إلى /api/v1/mobile/* (الكاشير، باركود الميزان، المرتجع، التحصيل، والوقود) مع Bearer وIdempotency-Key.
// 4. طابور العمل دون اتصال (OfflineSaleQueue): نجاح + إعادة محاولة على 503 + تعليم failed على 409 IDEMPOTENCY_CONFLICT.
// 5. التراجع الفوري عن التحويل (disableForMerchant) وإعادة الطلبات للمسار القديم دون مساس بترويسات محفظة أميال باي.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:mocktail/mocktail.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/data/api/api_client.dart';
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
  late MerchantBusinessBackendConfig config;
  late _MockApiClient legacyApi;

  setUp(() async {
    Get.reset();
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    config = MerchantBusinessBackendConfig(
      sharedPreferences: prefs,
      allowInsecureHttpForTesting: true,
    );
    legacyApi = _MockApiClient();
  });

  tearDown(() {
    Get.reset();
  });

  test('الافتراض لجميع التجار هو false ولا يُحوَّل أي تاجر تلقائياً', () async {
    expect(config.merchantBusinessBackendEnabled('merchant-100'), isFalse);
    expect(config.isActiveMerchantEnabled, isFalse);
    expect(config.isReadyForActiveMerchant, isFalse);

    final calledBusinessPaths = <String>[];
    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        calledBusinessPaths.add(req.url.path);
        return http.Response('{}', 200);
      }),
    );

    when(() => legacyApi.getData('/api/v1/amial/merchant/cashier/products', query: any(named: 'query')))
        .thenAnswer((_) async => const Response(statusCode: 200, body: {'success': true, 'meta': {'products': []}}));

    final repo = CashierRepo(apiClient: legacyApi, businessAdapter: adapter);
    final res = await repo.products(search: 'حليب');

    expect(res.statusCode, 200);
    expect(calledBusinessPaths, isEmpty);
    verify(() => legacyApi.getData('/api/v1/amial/merchant/cashier/products', query: {'search': 'حليب'})).called(1);
  });

  test('التاجر المفعّل يوجّه الكاشير والمرتجع والتحصيل والوقود إلى /api/v1/mobile/* مع Idempotency-Key', () async {
    await config.enableForMerchant(
      merchantId: 'tenant-ulid-1',
      bearerToken: 'abm_test_token_123',
      baseUrl: 'https://merchant.example.test',
    );

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
        if (req.url.path == '/api/v1/mobile/pos/sales') {
          return _jsonResponse({
            'ok': true,
            'code': 'OK',
            'replayed': false,
            'data': {
              'currency': 'YER',
              'invoice': {'id': 77, 'number': 'INV-000001', 'total': '1500.0000'},
            },
          }, 201);
        }
        if (req.url.path == '/api/v1/mobile/pos/sales/77/returns') {
          return _jsonResponse({'ok': true, 'code': 'OK', 'replayed': false, 'data': {'return': {'id': 5}}}, 201);
        }
        if (req.url.path == '/api/v1/mobile/contacts/12/collect') {
          return _jsonResponse({'ok': true, 'code': 'OK', 'replayed': false, 'data': {'remaining_receivable': '500.0000'}}, 201);
        }
        if (req.url.path == '/api/v1/mobile/fuel/sales') {
          return _jsonResponse({'ok': true, 'code': 'OK', 'replayed': false, 'data': {'invoice': {'id': 88, 'total': '7000.0000'}}}, 201);
        }
        return _jsonResponse({'ok': true, 'data': {}}, 200);
      }),
    );

    final cashier = CashierRepo(apiClient: legacyApi, businessAdapter: adapter);
    final refund = CashierRefundRepo(apiClient: legacyApi, businessAdapter: adapter);
    final credit = CustomerCreditRepo(apiClient: legacyApi, businessAdapter: adapter);

    // 1) باركود الميزان
    final bcRes = await cashier.lookupBarcode('2100123012507');
    expect(bcRes.statusCode, 200);
    expect((bcRes.body as Map)['meta']['product']['scanned_quantity'], '1.250');

    // 2) بيع نقدي مع مفتاح client_uuid -> يرسل Idempotency-Key ويهيّئ الحمولة
    final saleRes = await cashier.recordSale({
      'payment_method': 'cash',
      'cash_tendered': '1500.00',
      'client_uuid': 'sale-uuid-77',
      'items': [
        {'product_id': 9, 'qty': 3},
      ],
    });
    expect(saleRes.statusCode, 201);
    final saleReq = requests.firstWhere((r) => r.url.path == '/api/v1/mobile/pos/sales');
    expect(saleReq.headers['Authorization'], 'Bearer abm_test_token_123');
    expect(saleReq.headers['Idempotency-Key'], 'sale-uuid-77');
    final sentBody = jsonDecode(saleReq.body) as Map<String, dynamic>;
    expect(sentBody['method'], 'cash');
    expect(sentBody['tendered'], '1500.00');
    expect((sentBody['items'] as List).first['quantity'], '3');

    // 3) مرتجع + تحصيل آجل + بيع وقود
    final retRes = await refund.create('77', {'quantities': {'1': '1'}, 'client_operation_id': 'ret-77'});
    expect(retRes.statusCode, 201);

    final colRes = await credit.collectCash(12, '1000.00', 'col-key-12');
    expect(colRes.statusCode, 201);
    final colReq = requests.firstWhere((r) => r.url.path == '/api/v1/mobile/contacts/12/collect');
    expect(colReq.headers['Idempotency-Key'], 'col-key-12');

    final fuelRes = await adapter.recordFuelSale({
      'nozzle_id': 1,
      'liters': '10.000',
      'method': 'cash',
      'tendered': '7000.00',
      'client_uuid': 'fuel-uuid-88',
    });
    expect(fuelRes.statusCode, 201);

    // لم يُستدعَ خادم أميال باي القديم لأي عملية من عمليات التاجر المرحّل.
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

    // مزامنة الطابور بعد التراجع: لا تُرسل op-503 (الموسومة business) إلى legacyApi!
    final syncedAfterRollback = await queue.sync();
    expect(syncedAfterRollback, 0);
    verifyNoMoreInteractions(legacyApi);
  });
}

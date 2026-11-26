// اختبار تكامل HTTP حقيقي عبر المستودعين (Flutter MerchantBusinessApiAdapter <-> خادم Amial-Merchant حي).
// يعمل تلقائياً عند تمرير متغيّر البيئة AMIAL_MERCHANT_LIVE_URL (مثلاً http://127.0.0.1:8091).
// يتخطى بهدوء عند غياب المتغيّر حتى لا تتعطل اختبارات الوحدة المعزولة دون خادم.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:mocktail/mocktail.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_refund_controller.dart';
import 'package:amial_pay/features/merchant/domain/repositories/cashier_refund_repo.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_backend_config.dart';

class _MockApiClient extends Mock implements ApiClient {}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final liveBaseUrl = Platform.environment['AMIAL_MERCHANT_LIVE_URL'] ?? '';
  final cashierLogin = Platform.environment['AMIAL_MERCHANT_CASHIER_LOGIN'] ?? 'cashier@live.test';
  final ownerLogin = Platform.environment['AMIAL_MERCHANT_OWNER_LOGIN'] ?? 'owner@live.test';
  final password = Platform.environment['AMIAL_MERCHANT_LIVE_PASSWORD'] ?? 'Secret12345';
  final deviceCode = Platform.environment['AMIAL_MERCHANT_DEVICE_CODE'] ?? 'LIVE-REG-1';
  final wrongDeviceId = int.tryParse(Platform.environment['AMIAL_MERCHANT_WRONG_DEVICE_ID'] ?? '') ?? 999999;

  test(
    'Cross-repo LIVE HTTP: Flutter MerchantBusinessApiAdapter <-> Amial-Merchant /api/v1/mobile/*',
    () async {
      final previousOverrides = HttpOverrides.current;
      HttpOverrides.global = null;
      Get.reset();
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final tokenStore = InMemoryBusinessTokenStore();
      final config = MerchantBusinessBackendConfig(
        sharedPreferences: prefs,
        tokenStore: tokenStore,
        allowInsecureHttpForTesting: true,
      );
      await config.enableForMerchant(
        merchantId: 'bootstrap',
        bearerToken: 'bootstrap',
        baseUrl: liveBaseUrl,
      );
      final legacyApi = _MockApiClient();
      final realHttp = http.Client();
      final adapter = MerchantBusinessApiAdapter(
        config: config,
        httpClient: realHttp,
      );

      try {
        // 1) F-02 + F-06: دخول كاشير مقترن بجهاز POS حقيقي
        final loginRes = await adapter.login(
          login: cashierLogin,
          password: password,
          posDeviceCode: deviceCode,
        );
        expect(loginRes.statusCode, 200, reason: 'login body=${loginRes.body}');
        expect(loginRes.body['ok'], isTrue);
        final loginData = Map<String, dynamic>.from(loginRes.body['data'] as Map);
        final cashierToken = loginData['token'] as String;
        final tenantUlid = (loginData['tenant'] as Map)['ulid'] as String;
        expect(cashierToken.startsWith('abm_'), isTrue);

        await config.enableForMerchant(
          merchantId: tenantUlid,
          bearerToken: cashierToken,
          baseUrl: liveBaseUrl,
        );

        // 2) F-02: /me يعيد المستأجر والجهاز المربوط دون أخطاء CurrentTenant
        final meRes = await adapter.me();
        expect(meRes.statusCode, 200, reason: 'me body=${meRes.body}');
        expect((meRes.body['data']['pos_device'] as Map)['code'], deviceCode);

        // 3) F-08: محاولة فتح وردية بجهاز آخر تُرفض بـ 422 POS_DEVICE_MISMATCH، والفتح الصحيح ينجح
        final mismatchRes = await adapter.openShift(
          openingFloat: '100',
          posDeviceId: wrongDeviceId,
        );
        expect(mismatchRes.statusCode, 422, reason: 'mismatch body=${mismatchRes.body}');
        expect(mismatchRes.body['code'], 'POS_DEVICE_MISMATCH');

        final shiftRes = await adapter.openShift(openingFloat: '100');
        expect(shiftRes.statusCode, 201, reason: 'shift body=${shiftRes.body}');

        // 4) Closure: الكاشير (pos.sell) يجلب العملاء والمنتجات عبر المحوّل
        final contactsRes = await adapter.listContacts();
        expect(contactsRes.statusCode, 200, reason: 'contacts body=${contactsRes.body}');
        final customers = (contactsRes.body['meta']['customers'] as List).cast<Map>();
        expect(customers, isNotEmpty);
        final customerId = customers.first['id'] as int;

        final productsRes = await adapter.products();
        expect(productsRes.statusCode, 200, reason: 'products body=${productsRes.body}');
        final products = (productsRes.body['meta']['products'] as List).cast<Map>();
        expect(products, isNotEmpty);
        final product = Map<String, dynamic>.from(products.first);

        // 5) F-03: تعليق سلة -> استئناف -> إعادة تعليق (reopen)
        final holdRes = await adapter.holdCart({
          'items': [
            {'product_id': product['id'], 'quantity': 2},
          ],
          'note': 'Live HTTP Hold',
        });
        expect(holdRes.statusCode, 201, reason: 'hold body=${holdRes.body}');
        final ticket = Map<String, dynamic>.from(holdRes.body['meta']['ticket'] as Map);
        expect((ticket['items'] as List).first['name'], isNotEmpty);

        final cartId = '${ticket['id']}';
        final resumeRes = await adapter.resumeHeld(cartId);
        expect(resumeRes.statusCode, 200, reason: 'resume body=${resumeRes.body}');
        final reopenRes = await adapter.reopenHeld(cartId);
        expect(reopenRes.statusCode, 200, reason: 'reopen body=${reopenRes.body}');

        // 6) F-01 + F-05: بيع نقدي + إعادة إرسال متطابقة بنفس client_operation_id
        final opId = 'live-http-op-${DateTime.now().millisecondsSinceEpoch}';
        final sale1 = await adapter.recordSale({
          'payment_method': 'cash',
          'amount_received': 10000,
          'client_operation_id': opId,
          'items': [
            {'product_id': product['id'], 'quantity': '2'},
          ],
        });
        expect(sale1.statusCode, 201, reason: 'sale1 body=${sale1.body}');
        final sale1Map = Map<String, dynamic>.from(sale1.body['meta']['sale'] as Map);
        final saleUlid = sale1Map['sale_ulid'] as String;
        final saleLineId = (sale1Map['lines'] as List).first['id'] as int;

        final saleReplay = await adapter.recordSale({
          'payment_method': 'cash',
          'amount_received': 10000,
          'client_operation_id': opId,
          'items': [
            {'product_id': product['id'], 'quantity': '2'},
          ],
        });
        expect(saleReplay.statusCode, 201, reason: 'replay body=${saleReplay.body}');
        expect(saleReplay.body['meta']['sale']['sale_ulid'], saleUlid);

        // 7) F-08: الكاشير (بلا صلاحية sales.create) يُرفض في البيع الآجل بـ 422 CREDIT_SALE_NOT_ALLOWED
        final cashierCreditAttempt = await adapter.recordSale({
          'payment_method': 'credit',
          'customer_id': customerId,
          'client_operation_id': 'live-credit-denied-${DateTime.now().millisecondsSinceEpoch}',
          'items': [
            {'product_id': product['id'], 'quantity': '1'},
          ],
        });
        expect(cashierCreditAttempt.statusCode, 422, reason: 'credit body=${cashierCreditAttempt.body}');
        expect(cashierCreditAttempt.body['code'], 'CREDIT_SALE_NOT_ALLOWED');

        // 8) F-03: تفصيل الفاتورة بالـ ULID عبر الكاشير
        final detailRes = await adapter.saleDetail(saleUlid);
        expect(detailRes.statusCode, 200, reason: 'detail body=${detailRes.body}');
        expect(detailRes.body['meta']['sale']['sale_ulid'], saleUlid);
        expect((detailRes.body['meta']['lines'] as List).first['refundable_quantity'], '2.0000');

        // 9) F-04: تسجيل الخروج يبطل الرمز فعلياً على الخادم
        final logoutRes = await adapter.logout();
        expect(logoutRes.statusCode, 200);
        final afterLogout = await adapter.me();
        expect(afterLogout.statusCode, 401);

        // 10) F-03 + F-08: المالك (يملك sales.refund) ينفّذ مرتجعاً جزئياً عبر CashierRefundController
        final ownerLoginRes = await adapter.login(
          login: ownerLogin,
          password: password,
        );
        expect(ownerLoginRes.statusCode, 200, reason: 'owner login=${ownerLoginRes.body}');
        final ownerTok = ownerLoginRes.body['data']['token'] as String;
        await config.enableForMerchant(
          merchantId: tenantUlid,
          bearerToken: ownerTok,
          baseUrl: liveBaseUrl,
        );
        final ownerShift = await adapter.openShift(openingFloat: '500');
        expect(ownerShift.statusCode, 201, reason: 'owner shift=${ownerShift.body}');

        final refundRepo = CashierRefundRepo(apiClient: legacyApi, businessAdapter: adapter);
        final refundCtrl = CashierRefundController(repo: refundRepo);
        await refundCtrl.loadRefundable(saleUlid);
        expect(refundCtrl.refundableInfo.value, isNotNull);

        final refunded = await refundCtrl.create(
          saleUlid: saleUlid,
          amount: '${product['price']}',
          refundMethod: 'cash',
          items: [
            {'sale_item_id': saleLineId, 'quantity': 1, 'condition': 'good', 'restock': true},
          ],
          reason: 'Live HTTP cross-repo return test',
        );
        expect(refunded, isTrue, reason: 'refund error=${refundCtrl.lastError.value}');

        // 11) F-01 + F-03: المالك (يملك sales.create) ينفّذ بيعاً آجلاً بـ customer_id ثم يحصّل دفعة آجل
        final ownerCreditSale = await adapter.recordSale({
          'payment_method': 'credit',
          'customer_id': customerId,
          'client_operation_id': 'live-credit-owner-${DateTime.now().millisecondsSinceEpoch}',
          'items': [
            {'product_id': product['id'], 'quantity': '1'},
          ],
        });
        expect(ownerCreditSale.statusCode, 201, reason: 'owner credit=${ownerCreditSale.body}');

        final collectRes = await adapter.collectFromContact(
          contactId: customerId,
          amount: '200.00',
          method: 'bank',
          idempotencyKey: 'live-collect-${DateTime.now().millisecondsSinceEpoch}',
        );
        expect(collectRes.statusCode, 201, reason: 'collect=${collectRes.body}');
        expect(collectRes.body['meta']['remaining_receivable'], '1000.0000');

        // تنظيف الجلسة بعد الاختبار
        await config.clearActiveSession(revokeRemote: () async => adapter.logout());
      } finally {
        realHttp.close();
        HttpOverrides.global = previousOverrides;
        Get.reset();
      }
    },
    skip: liveBaseUrl.isEmpty
        ? 'Live cross-repo HTTP test requires AMIAL_MERCHANT_LIVE_URL env var'
        : false,
  );
}

// اختبارات الشاشات والودجت الحقيقية (Flutter Widget/Screen Tests) في وضع أميال أعمال
// أمام حمولات JSON الحقيقية القادمة من /api/v1/mobile/* في Amial-Merchant.

import 'dart:convert';

import 'package:flutter/material.dart';
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
import 'package:amial_pay/features/merchant/screens/cashier_payment_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_receipt_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_customers_screen.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_backend_config.dart';
import 'package:amial_pay/features/merchant/services/offline_sale_queue.dart';

class _MockApiClient extends GetxService with Mock implements ApiClient {}

http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
    http.Response.bytes(
      utf8.encode(jsonEncode(body)),
      status,
      headers: const {'content-type': 'application/json; charset=utf-8'},
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences prefs;
  late InMemoryBusinessTokenStore tokenStore;
  late MerchantBusinessBackendConfig config;
  late _MockApiClient legacyApi;

  setUp(() async {
    Get.reset();
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    tokenStore = InMemoryBusinessTokenStore();
    config = MerchantBusinessBackendConfig(
      sharedPreferences: prefs,
      tokenStore: tokenStore,
      allowInsecureHttpForTesting: true,
    );
    await config.enableForMerchant(
      merchantId: 'tenant-widget-1',
      bearerToken: 'abm_widget_tok',
      baseUrl: 'https://merchant.widget.test',
    );
    legacyApi = _MockApiClient();
    Get.put<ApiClient>(legacyApi);
    Get.put<OfflineSaleQueue>(OfflineSaleQueue(apiClient: legacyApi));
  });

  tearDown(() {
    Get.reset();
  });

  testWidgets('CashierPaymentScreen في وضع أميال أعمال يخفي المختلط والشركات والولاء وينفذ دفع المحفظة عبر QR + Polling', (tester) async {
    tester.view.physicalSize = const Size(600, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    const invoiceUlid = '01JWIDGETSALE0000000000001';
    int statusPolls = 0;
    final recordedRequests = <String>[];

    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        recordedRequests.add('${req.method} ${req.url.path}');
        if (req.method == 'POST' && req.url.path == '/api/v1/mobile/pos/sales') {
          final body = jsonDecode(req.body) as Map<String, dynamic>;
          expect(body['method'], 'wallet');
          return _json({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'invoice': {
                'id': 41,
                'ulid': invoiceUlid,
                'number': 'SI-000041',
                'status': 'awaiting_payment',
                'payment_method': 'wallet',
                'total': '1500.00',
                'returned_total': '0.00',
                'lines': [
                  {
                    'id': 801,
                    'product_id': 7,
                    'name': 'حليب طازج',
                    'description': 'حليب طازج',
                    'quantity': '1.0000',
                    'returned_quantity': '0.0000',
                    'refundable_quantity': '1.0000',
                    'unit_price': '1500.00',
                    'line_total': '1500.00',
                  },
                ],
                'wallet_intent': {
                  'intent_id': '01JINTENT00000000000000001',
                  'intent_ulid': '01JINTENT00000000000000001',
                  'short_code': 'PAY-W-41',
                  'status': 'pending',
                  'qr_payload': 'amial://pay?intent=01JINTENT00000000000000001',
                  'expires_at': '2026-11-20T18:00:00Z',
                },
                'refundable': {
                  'refunded_so_far': '0.00',
                  'remaining': '1500.00',
                  'fully_refunded': false,
                  'available_methods': ['wallet'],
                },
              },
            },
          }, 201);
        }
        if (req.method == 'GET' && req.url.path == '/api/v1/mobile/pos/sales/$invoiceUlid/wallet-status') {
          statusPolls++;
          return _json({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'invoice': {
                'id': 41,
                'ulid': invoiceUlid,
                'number': 'SI-000041',
                'status': 'paid',
                'payment_method': 'wallet',
                'total': '1500.00',
                'returned_total': '0.00',
                'lines': [],
                'wallet_intent': {'short_code': 'PAY-W-41', 'status': 'succeeded'},
                'refundable': {'refunded_so_far': '0.00', 'remaining': '1500.00', 'fully_refunded': false, 'available_methods': ['wallet']},
              },
            },
          });
        }
        return _json({'ok': false, 'code': 'NOT_FOUND'}, 404);
      }),
    );

    Get.put<MerchantBusinessApiAdapter>(adapter);
    final repo = CashierRepo(apiClient: legacyApi, businessAdapter: adapter);
    final cashier = Get.put<CashierController>(CashierController(repo: repo));
    cashier.addProductToCart({'id': 7, 'name': 'حليب طازج', 'price': '1500.0'});

    await tester.pumpWidget(
      const GetMaterialApp(
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: CashierPaymentScreen(total: 1500.0),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // F-01: طرق الدفع والميزات غير المدعومة مخفية تماماً في وضع أميال أعمال.
    expect(find.text('حساب شركة'), findsNothing);
    expect(find.text('نقاط الولاء'), findsNothing);
    expect(find.text('تطبيق خصم / كوبون'), findsNothing);
    expect(find.text('دفع مختلط'), findsNothing);

    // اختيار وسيلة المحفظة "أميال باي" ثم الضغط على تأكيد الدفع.
    await tester.tap(find.text('أميال باي').first);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('تأكيد ومعالجة الدفع'));
    await tester.tap(find.text('تأكيد ومعالجة الدفع'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    // حوار الدفع بالمحفظة يظهر برمز الدفع القصير.
    expect(find.textContaining('PAY-W-41'), findsOneWidget);

    // الضغط على "تحديث الحالة" يؤكّد الدفع ويفتح شاشة الإيصال.
    await tester.tap(find.text('تحديث الحالة'));
    await tester.pumpAndSettle();

    expect(statusPolls, greaterThanOrEqualTo(1));
    expect(find.byType(CashierReceiptScreen), findsOneWidget);
    expect(recordedRequests, contains('POST /api/v1/mobile/pos/sales'));
    expect(recordedRequests, contains('GET /api/v1/mobile/pos/sales/$invoiceUlid/wallet-status'));
  });

  testWidgets('CashierRefundScreen يعرض فاتورة Amial-Merchant بأسطرها ويُرسل sales_invoice_line_id بشكل صحيح (F-03)', (tester) async {
    const saleUlid = '01JREFUNDWIDGET00000000009';
    Map<String, dynamic>? sentReturnBody;

    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        if (req.method == 'GET' && req.url.path == '/api/v1/mobile/pos/sales/$saleUlid') {
          return _json({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'invoice': {
                'id': 99,
                'ulid': saleUlid,
                'number': 'SI-000099',
                'status': 'paid',
                'payment_method': 'cash',
                'total': '2000.00',
                'returned_total': '0.00',
                'customer': {'id': 5, 'name': 'عميل اختباري', 'phone': '777000111'},
                'lines': [
                  {
                    'id': 555,
                    'product_id': 12,
                    'name': 'زيت نباتي 2 لتر',
                    'description': 'زيت نباتي 2 لتر',
                    'quantity': '2.0000',
                    'returned_quantity': '0.0000',
                    'refundable_quantity': '2.0000',
                    'unit_price': '1000.00',
                    'line_total': '2000.00',
                  },
                ],
                'refundable': {
                  'refunded_so_far': '0.00',
                  'remaining': '2000.00',
                  'fully_refunded': false,
                  'available_methods': ['cash'],
                },
              },
            },
          });
        }
        if (req.method == 'POST' && req.url.path == '/api/v1/mobile/pos/sales/$saleUlid/returns') {
          sentReturnBody = jsonDecode(req.body) as Map<String, dynamic>;
          return _json({
            'ok': true,
            'code': 'OK',
            'data': {
              'return': {'id': 10, 'number': 'SR-000010', 'total': '1000.00'},
              'invoice_returned_total': '1000.00',
            },
          }, 201);
        }
        return _json({'ok': false}, 404);
      }),
    );

    final refundRepo = CashierRefundRepo(apiClient: legacyApi, businessAdapter: adapter);
    Get.put<CashierRefundController>(CashierRefundController(repo: refundRepo));

    await tester.pumpWidget(
      const GetMaterialApp(
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: CashierRefundScreen(saleUlid: saleUlid),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // التحقق من عرض بيانات الفاتورة والسطر بلا أخطاء
    expect(find.text('زيت نباتي 2 لتر'), findsOneWidget);
    expect(find.text('عميل اختباري'), findsOneWidget);
    expect(find.text('2000.00 ر.ي'), findsAtLeastNWidgets(1));

    // زيادة كمية المرتجع بمقدار 1 ثم تأكيد العملية
    await tester.tap(find.byIcon(Icons.add_circle).first);
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('تأكيد عملية المرتجع'));
    await tester.tap(find.text('تأكيد عملية المرتجع'));
    await tester.pumpAndSettle();
    // انتظار إغلاق Get.snackbar (مؤقّت 3 ثوانٍ) قبل إنهاء الاختبار.
    await tester.pump(const Duration(seconds: 4));
    await tester.pumpAndSettle();

    expect(sentReturnBody, isNotNull);
    final sentQuantities = Map<String, dynamic>.from(sentReturnBody!['quantities'] as Map);
    expect(sentQuantities['555'], '1.0');
  });

  testWidgets('CreditCustomersScreen يعرض عملاء الآجل وأرصدتهم من Amial-Merchant دون تعطل (F-03)', (tester) async {
    final adapter = MerchantBusinessApiAdapter(
      config: config,
      httpClient: MockClient((req) async {
        if (req.method == 'GET' && req.url.path == '/api/v1/mobile/contacts') {
          return _json({
            'ok': true,
            'code': 'OK',
            'data': {
              'currency': 'YER',
              'contacts': [
                {
                  'id': 31,
                  'name': 'شركة النور التجارية',
                  'phone': '771234567',
                  'credit_limit': '50000.00',
                  'receivable': '12500.00',
                },
              ],
            },
          });
        }
        return _json({'ok': false}, 404);
      }),
    );

    final creditRepo = CustomerCreditRepo(apiClient: legacyApi, businessAdapter: adapter);
    Get.put<CustomerCreditController>(CustomerCreditController(repo: creditRepo));

    await tester.pumpWidget(
      const GetMaterialApp(
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: CreditCustomersScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('شركة النور التجارية'), findsOneWidget);
    expect(find.text('771234567'), findsOneWidget);
    expect(find.text('12500 ر.ي'), findsOneWidget);
    expect(find.text('الحد: 50000 ر.ي'), findsOneWidget);
  });
}

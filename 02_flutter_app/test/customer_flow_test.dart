// اختبار E2E لتدفّق العميل: تسجيل دخول → تحويل مال → إيصال.
//
// المنهج: نحقن **ApiClient مزيّفاً** يُرجع استجابات معلّبة حسب المسار، ثم نُشغّل
// المتحكّمات والمستودعات الحقيقية (UnifiedAuth → Transaction → Receipts) بالتسلسل.
// هذا يتحقّق من تكامل التدفّق الكامل (تسلسل النداءات + عقد الـ API + فكّ الترميز +
// مؤشّرات النجاح) دون خادم حيّ ولا إضافات native — حتمي ويعمل في CI.

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amyal_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amyal_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amyal_pay/features/transaction_money/domain/reposotories/transaction_repo.dart';
import 'package:amyal_pay/features/receipts/controllers/receipts_controller.dart';
import 'package:amyal_pay/features/receipts/domain/repositories/receipts_repo.dart';

/// ApiClient مزيّف: يُرجع استجابة ناجحة معلّبة حسب مسار الطلب.
class _FakeApiClient implements ApiClient {
  final List<String> calls = [];

  @override
  Future<Response> postData(String uri, dynamic body,
      {Map<String, String>? headers, String? idempotencyKey}) async {
    calls.add('POST $uri');
    if (uri.contains('/auth/login')) {
      return Response(statusCode: 200, body: {
        'success': true,
        'meta': {'token': 'fake-jwt-token', 'role': 'customer'},
      });
    }
    if (uri.contains('send-money')) {
      return Response(statusCode: 200, body: {
        'success': true,
        'message': 'تم التحويل بنجاح',
        'meta': {'transaction_id': 'TX-123', 'receipt_id': 1},
      });
    }
    return Response(statusCode: 404, body: {'success': false});
  }

  @override
  Future<Response> getData(String uri,
      {Map<String, dynamic>? query, Map<String, String>? headers}) async {
    calls.add('GET $uri');
    if (uri.contains('/amial/receipts/')) {
      return Response(statusCode: 200, body: {
        'meta': {
          'id': 1,
          'receipt_number': 'RCP-0001',
          'receipt_type': 'send_money',
          'amount': '100.0000',
          'fee': '1.0000',
          'net_amount': '100.0000',
          'direction': 'debit',
          'status': 'pdf_generated',
          'zone_code': 'SOUTH',
        },
      });
    }
    return Response(statusCode: 404, body: {'success': false});
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('تدفّق العميل الكامل: دخول → تحويل → إيصال', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    final sp = await SharedPreferences.getInstance();
    final api = _FakeApiClient();

    // بناء المتحكّمات بالمستودعات الحقيقية فوق الـ ApiClient المزيّف.
    final auth = UnifiedAuthController(repo: UnifiedAuthRepo(apiClient: api));
    final tx = TransactionMoneyController(
      transactionRepo: TransactionRepo(apiClient: api, sharedPreferences: sp),
      authRepo: AuthRepo(apiClient: api, sharedPreferences: sp),
    );
    final receipts = ReceiptsController(repo: ReceiptsRepo(apiClient: api));

    // ===== 1) تسجيل الدخول =====
    final loggedIn = await auth.loginCustomer(
      nationalId: '01001234567',
      phone: '0501234567',
      password: 'secret123',
    );
    expect(loggedIn, isTrue, reason: 'يجب أن ينجح تسجيل دخول العميل');
    expect(auth.currentRole.value, 'customer');
    expect(auth.lastError.value, isEmpty);
    expect(auth.isSubmitting.value, isFalse);

    // ===== 2) تحويل مال =====
    var suggested = false;
    final transfer = await tx.sendMoney(
      contactModel: ContactModel(phoneNumber: '0509998888', name: 'مستلم'),
      amount: 100,
      purpose: 'هدية',
      pinCode: '1234',
      onSuggest: () => suggested = true,
    );
    expect(transfer.statusCode, 200, reason: 'يجب أن ينجح التحويل');
    expect(suggested, isTrue, reason: 'callback النجاح يجب أن يُستدعى');

    // ===== 3) جلب الإيصال =====
    await receipts.selectReceipt(1);
    expect(receipts.selectedReceipt.value, isNotNull, reason: 'يجب جلب الإيصال');
    expect(receipts.selectedReceipt.value!.id, 1);
    expect(receipts.selectedReceipt.value!.amount, '100.0000');
    expect(receipts.lastError.value, isEmpty);

    // ===== التحقّق من تسلسل النداءات الفعلي للـ backend =====
    expect(api.calls, [
      'POST /api/v1/auth/login',
      'POST /api/v1/customer/send-money',
      'GET /api/v1/amial/receipts/1',
    ]);
  });
}

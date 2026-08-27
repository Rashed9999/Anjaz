import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantRepo extends GetxService {
  final ApiClient apiClient;
  MerchantRepo({required this.apiClient});

  // التاجر يستخدم customer routes (لأن Cash6 يعتبره user مع type=3)
  static const String _customerBase = '/api/v1/customer';

  // ====== Profile ======
  Future<Response> getProfile() => apiClient.getData('$_customerBase/get-customer');
  Future<Response> dailyStats() => apiClient.getData('/api/v1/amial/merchant/daily-stats');
  Future<Response> financialReport({String? from, String? to}) =>
      apiClient.getData('/api/v1/amial/merchant/financial-report', query: {
        if (from != null) 'from': from,
        if (to != null) 'to': to,
      });
  Future<Response> updateProfile(Map<String, dynamic> data) =>
      apiClient.putData('$_customerBase/update-profile', data);
  Future<Response> logout() => apiClient.postData('$_customerBase/logout', {});

  // ====== Generate Payment Request (QR) ======
  Future<Response> requestPayment({
    required String amount,
    String? note,
    String? customerPhone,
  }) {
    // AMIAL-MERCHANT-PAY-002 — **العنوان الصحيح: فاتورةٌ لا طلبُ صديق.**
    //
    // كان ينادي `/customer/request-money`، وهو مسارُ «اطلب مالاً من
    // شخص»: يشترط `phone` إلزاميّاً، ويشترط أن يكون المستلِمُ عميلاً،
    // **ولا يُنتج رمزاً قصيراً إطلاقاً.** فكان التاجرُ بلا رقم عميلٍ
    // يتلقّى ٤٠٣ قبل أن يُنشأ شيء — ثمّ تُعرض عليه شاشةُ «تمّ إنشاء طلب
    // الدفع» ورمزٌ فارغ.
    //
    // و`/amial/payment-requests` هو الجذرُ الصحيح: يردّ `short_code` —
    // وهو **رقمُ الفاتورة** — ويُقرأ بـ`showByCode` من تطبيق العميل،
    // ويظهر في «فواتير التجّار» بلوحة الإدارة.
    return apiClient.postData(
      '/api/v1/amial/payment-requests',
      {
        'amount': amount,
        'note': ?note,
        'recipient_phone': ?customerPhone,
        'share_method': 'qr',
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('merchant_invoice'),
    );
  }

  // ====== Transaction history (filtered to incoming) ======
  Future<Response> transactionHistory({int page = 1}) =>
      apiClient.getData('$_customerBase/transaction-history?page=$page');

  // ====== Refund (يستخدم AMIAL safe-payment إذا متاح أو send-money عكسي) ======
  Future<Response> processRefund({
    required String originalTransactionId,
    required String amount,
    String? reason,
  }) {
    return apiClient.postData(
      '/api/v1/amial/merchant/refund',
      {
        'original_transaction_id': originalTransactionId,
        'amount': amount,
        'reason': ?reason,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('merchant_refund'),
    );
  }

  // ====== Ledger (المحاسبي) ======
  Future<Response> ledger({int page = 1}) =>
      apiClient.getData('/api/v1/amial/merchant/ledger?page=$page');

  // ====== Withdraw (التاجر يسحب إلى بنكه) ======
  Future<Response> withdraw({
    required String amount,
    required int withdrawalMethodId,
    Map<String, dynamic>? methodFields,
  }) {
    return apiClient.postData(
      '$_customerBase/withdraw',
      {
        'amount': amount,
        'withdrawal_method_id': withdrawalMethodId,
        ...?methodFields,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('merchant_withdraw'),
    );
  }
}

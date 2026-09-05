import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantRepo extends GetxService {
  final ApiClient apiClient;
  MerchantRepo({required this.apiClient});

  /// مسارات 6cash القديمة — **وليست كلُّها مفتوحةً للتاجر**، انظر أدناه.
  static const String _customerBase = '/api/v1/customer';

  // ══════════════════════════════════════════════════════════════════
  // AMIAL-MERCHANT-SESSION-001 — **«التاجر يستخدم customer routes» كان
  // مكتوباً هنا، وقِيس فإذا هو غيرُ صحيح.**
  //
  //   GET /api/v1/customer/get-customer      (تاجر) → 403 Access forbidden.
  //   GET /api/v1/customer/transaction-history (تاجر) → 403 Access forbidden.
  //
  // ووسيطُ `customerAuth` يشترط `type == CUSTOMER_TYPE`، والتاجرُ ٣.
  // **فالنداءان يُردّان لكلّ تاجرٍ منذ كُتبا** — ولا خطأَ في أيّ سجلّ:
  // `MerchantController` يبتلع الاستثناء، فتُعرَض «لا حركةَ في المحفظة
  // بعد» على متجرٍ فيه حركات. **وهو غيابٌ يُقرأ صفراً** (القاعدة السابعة).
  //
  // فصار المصدرُ مسارَ التاجر: `merchant/ledger` — قيودُ محفظته من
  // الدفتر نفسِه، وهو مصدرُ الحقيقة الماليّة.
  // ══════════════════════════════════════════════════════════════════
  Future<Response> dailyStats() => apiClient.getData('/api/v1/amial/merchant/daily-stats');

  /// حركاتُ محفظة المتجر — من دفتر التاجر لا من سجلّ العميل.
  Future<Response> walletLedger({int page = 1}) =>
      apiClient.getData('/api/v1/amial/merchant/ledger', query: {'page': '$page'});
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

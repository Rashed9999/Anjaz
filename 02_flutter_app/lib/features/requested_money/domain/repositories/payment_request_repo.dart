import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-PAYMENT-REQUESTS-001 — Repo.
class PaymentRequestRepo extends GetxService {
  final ApiClient apiClient;
  PaymentRequestRepo({required this.apiClient});

  static const _base = '/api/v1/amial/payment-requests';

  Future<Response> create(Map<String, dynamic> data) => apiClient.postData(_base, data);

  Future<Response> list({String direction = 'outgoing', String? status, int page = 1}) {
    final q = <String, dynamic>{'direction': direction, 'page': page.toString()};
    if (status != null && status.isNotEmpty) q['status'] = status;
    return apiClient.getData(_base, query: q);
  }

  Future<Response> showByCode(String code) => apiClient.getData('$_base/code/$code');

  /// AMIAL-MERCHANT-PAY-002 — **ورمز المعاملات يُرسَل مع الدفع.**
  ///
  /// قِيس قبل الإضافة: `customer/send-money` يشترط PIN، ودفعُ فاتورةٍ
  /// لم يكن يشترطه. أي أنّ الدفعَ في متجرٍ كان محميّاً **أقلَّ من إرسال
  /// مالٍ لصديق** — ومن أخذ هاتفاً مفتوحاً يدفع به بلا حاجز.
  Future<Response> pay(String code, {required String pin}) =>
      apiClient.postData('$_base/code/$code/pay', {'pin': pin});

  /// AMIAL-MERCHANT-PAY-002 — البحث بفاتورةٍ حين لا تعمل الكاميرا.
  ///
  /// ويُرسل رقمُ الحساب مع رقم الفاتورة: حرفٌ يُخطأ يقع على فاتورة تاجرٍ
  /// آخر، ومطابقةُ التاجر تجعل الخطأ رسالةً لا دفعة.
  Future<Response> lookupInvoice({
    String? merchantPhone,
    int? merchantUserId,
    required String invoiceNo,
  }) =>
      apiClient.postData('$_base/invoice/lookup', {
        if (merchantPhone != null && merchantPhone.isNotEmpty) 'merchant_phone': merchantPhone,
        'merchant_user_id': ?merchantUserId,
        'invoice_no': invoiceNo,
      });

  Future<Response> cancel(int id) => apiClient.postData('$_base/$id/cancel', {});

  // AMIAL-REQUEST-DIRECT-001 — الطرفان الناقصان في «يوافق أو يرفض».
  //
  // الدفع بالرمز القصير مسارُ من وصله رابط. أمّا من وصله الطلبُ في قائمته
  // فلا يملك رمزاً يكتبه — فيراه ولا يستطيع دفعه.
  /// **وهذا بابٌ ثانٍ إلى المال نفسِه — فيحمل الرمزَ معه.**
  /// (حاجزٌ على بابٍ واحدٍ من بابين ليس حاجزاً.)
  Future<Response> payById(int id, {required String pin}) =>
      apiClient.postData('$_base/$id/pay', {'pin': pin});

  Future<Response> decline(int id, {String? reason}) =>
      apiClient.postData('$_base/$id/decline', {
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      });
}

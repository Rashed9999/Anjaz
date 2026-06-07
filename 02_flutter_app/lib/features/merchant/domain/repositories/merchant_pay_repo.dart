import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-MERCHANT-PAY-001 — دفع العميل للتاجر عبر QR أو POS (جهة العميل).
///
/// يطابق الـ backend:
///   POST /api/v1/amial/merchant/quote — معاينة (التاجر يستلم كم)
///   POST /api/v1/amial/merchant/pay   — تنفيذ الدفع
class MerchantPayRepo extends GetxService {
  final ApiClient apiClient;
  MerchantPayRepo({required this.apiClient});

  Future<Response> quote({required String amount, String channel = 'qr'}) {
    return apiClient.postData('/api/v1/amial/merchant/quote', {
      'amount': amount,
      'channel': channel,
    });
  }

  Future<Response> pay({
    String? merchantPhone,
    int? merchantUserId,
    required String amount,
    String channel = 'qr',
    int? posUserId,
    String? note,
    required String idempotencyKey,
  }) {
    return apiClient.postData(
      '/api/v1/amial/merchant/pay',
      {
        'merchant_user_id': ?merchantUserId,
        if (merchantPhone != null && merchantPhone.isNotEmpty) 'merchant_phone': merchantPhone,
        'amount': amount,
        'channel': channel,
        'pos_user_id': ?posUserId,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      idempotencyKey: idempotencyKey,
    );
  }
}

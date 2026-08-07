import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-TRANSFER-V2 — عميل مسار التحويل الجديد:
/// تحقّق من المستلم (بالاسم المقنَّع) → initiate بنافذة تراجع → إلغاء/حالة.
class AmialTransferApi {
  AmialTransferApi._();

  static const _base = '/api/v1/amial/transfer';

  /// POST /verify-recipient → verification_token + masked_name + masked_phone.
  static Future<Response> verifyRecipient(String phone) =>
      Get.find<ApiClient>().postData('$_base/verify-recipient', {'phone': phone});

  /// POST /initiate → transfer_ulid + seconds_remaining (نافذة التراجع).
  static Future<Response> initiate({
    required int recipientId,
    required String verificationToken,
    required String amount,
    required String pin,
    String? fee,
    String? note,
  }) =>
      Get.find<ApiClient>().postData(
        '$_base/initiate',
        {
          'recipient_id': recipientId,
          'verification_token': verificationToken,
          'amount': amount,
          'pin': pin,
          if (fee != null) 'fee': fee,
          if (note != null && note.isNotEmpty) 'note': note,
        },
        idempotencyKey:
            IdempotencyKeyGenerator.forFinancialAction('amial_transfer'),
      );

  /// POST /{ulid}/cancel — تراجع خلال النافذة، يسترد المبلغ كاملاً.
  static Future<Response> cancel(String ulid) =>
      Get.find<ApiClient>().postData('$_base/$ulid/cancel', {});

  /// GET /{ulid}/status — holding | completed | cancelled | failed.
  static Future<Response> status(String ulid) =>
      Get.find<ApiClient>().getData('$_base/$ulid/status');
}

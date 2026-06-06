import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة (Flutter).
///
/// يطابق الـ backend:
///   POST /api/v1/amial/merchant/split-bills            (التاجر/POS ينشئ)
///   GET  /api/v1/amial/merchant/split-bills/{ulid}     (عرض)
///   GET  /api/v1/amial/split-bills/mine                (حصص العميل)
///   POST /api/v1/amial/split-bills/participants/{id}/pay (العميل يدفع)
class SplitBillRepo extends GetxService {
  final ApiClient apiClient;
  SplitBillRepo({required this.apiClient});

  Future<Response> create({
    required String totalAmount,
    required List<String> participants,
    String channel = 'qr',
    String? note,
  }) {
    return apiClient.postData('/api/v1/amial/merchant/split-bills', {
      'total_amount': totalAmount,
      'participants': participants,
      'channel': channel,
      if (note != null && note.isNotEmpty) 'note': note,
    });
  }

  Future<Response> show(String ulid) {
    return apiClient.getData('/api/v1/amial/merchant/split-bills/$ulid');
  }

  Future<Response> myShares() {
    return apiClient.getData('/api/v1/amial/split-bills/mine');
  }

  Future<Response> payShare(int participantId, {required String idempotencyKey}) {
    return apiClient.postData(
      '/api/v1/amial/split-bills/participants/$participantId/pay',
      {},
      idempotencyKey: idempotencyKey,
    );
  }
}

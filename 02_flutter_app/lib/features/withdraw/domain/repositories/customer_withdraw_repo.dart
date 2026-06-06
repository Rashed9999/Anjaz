import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-CUSTOMER-WITHDRAW-001 — مستودع السحب من جهة العميل.
class CustomerWithdrawRepo extends GetxService {
  final ApiClient apiClient;
  CustomerWithdrawRepo({required this.apiClient});

  static const _base = '/api/v1/amial/withdraw';

  /// إنشاء طلب سحب جديد.
  /// يرجع: op_code، amount، fee، total_debit، status، expires_at، request_id.
  Future<Response> request(String amount) =>
      apiClient.postData('$_base/request', {'amount': amount});

  /// طلبات السحب المعلّقة للعميل الحالي.
  Future<Response> mine() => apiClient.getData('$_base/mine');

  /// إلغاء طلب وفكّ الحجز.
  Future<Response> cancel(int requestId) =>
      apiClient.postData('$_base/$requestId/cancel', {});
}

import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-CASHIER-REFUND-001 — مستودع المرتجعات.
class CashierRefundRepo extends GetxService {
  final ApiClient apiClient;
  CashierRefundRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/cashier';

  /// كم متبقّي للاسترداد + طرق الاسترداد المتاحة
  Future<Response> refundable(String saleUlid) =>
      apiClient.getData('$_base/sales/$saleUlid/refundable');

  /// إنشاء مرتجع
  Future<Response> create(String saleUlid, Map<String, dynamic> body) =>
      apiClient.postData('$_base/sales/$saleUlid/refund', body);

  /// قائمة المرتجعات
  Future<Response> list({int page = 1}) =>
      apiClient.getData('$_base/refunds', query: {'page': page.toString()});

  /// تفاصيل مرتجع
  Future<Response> show(int id) => apiClient.getData('$_base/refunds/$id');
}

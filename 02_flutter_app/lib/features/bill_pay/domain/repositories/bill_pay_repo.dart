import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/util/app_constants.dart';

/// AMIAL-BILL-PAY-001 (v0.9-D)
class BillPayRepo extends GetxService {
  final ApiClient apiClient;
  BillPayRepo({required this.apiClient});

  Future<Response> listProviders() async => apiClient.getData(AppConstants.amyalBillProviders);

  Future<Response> listProducts(int serviceId) async {
    return apiClient.getData('${AppConstants.amyalBillProducts}$serviceId/products');
  }

  /// **حاسم:** الـ idempotencyKey محفوظ في الـ controller للـ retry —
  /// عملية مالية، يجب أن نُمرر نفس الـ key لو الـ user حاول مرتين.
  Future<Response> pay({
    required int serviceId,
    int? productId,
    required String subscriberAccount,
    required String amount,
    Map<String, dynamic>? subscriberExtra,
    required String idempotencyKey,
  }) async {
    return apiClient.postData(
      AppConstants.amyalBillPay,
      {
        'service_id': serviceId,
        if (productId != null) 'product_id': productId,
        'subscriber_account': subscriberAccount,
        'amount': amount,
        if (subscriberExtra != null) 'subscriber_extra': subscriberExtra,
      },
      idempotencyKey: idempotencyKey,
    );
  }

  Future<Response> listOrders() async => apiClient.getData(AppConstants.amyalBillOrders);

  Future<Response> showOrder(String ulid) async {
    return apiClient.getData('${AppConstants.amyalBillOrderShow}$ulid');
  }
}

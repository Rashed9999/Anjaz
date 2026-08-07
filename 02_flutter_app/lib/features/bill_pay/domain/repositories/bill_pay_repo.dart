import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-BILL-PAY-001 (v0.9-D)
class BillPayRepo extends GetxService {
  final ApiClient apiClient;
  BillPayRepo({required this.apiClient});

  Future<Response> listProviders() async => apiClient.getData(AppConstants.amialBillProviders);

  Future<Response> listProducts(int serviceId) async {
    return apiClient.getData('${AppConstants.amialBillProducts}$serviceId/products');
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
      AppConstants.amialBillPay,
      {
        'service_id': serviceId,
        'product_id': ?productId,
        'subscriber_account': subscriberAccount,
        'amount': amount,
        'subscriber_extra': ?subscriberExtra,
      },
      idempotencyKey: idempotencyKey,
    );
  }

  Future<Response> listOrders() async => apiClient.getData(AppConstants.amialBillOrders);

  Future<Response> showOrder(String ulid) async {
    return apiClient.getData('${AppConstants.amialBillOrderShow}$ulid');
  }
}

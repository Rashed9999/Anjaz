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

  Future<Response> pay(String code) => apiClient.postData('$_base/code/$code/pay', {});

  Future<Response> cancel(int id) => apiClient.postData('$_base/$id/cancel', {});
}

import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — نظام ديون العملاء (Flutter).
class CustomerCreditRepo extends GetxService {
  final ApiClient apiClient;
  CustomerCreditRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/credit';

  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  Future<Response> listCustomers({String? search, String? filter}) {
    final q = <String, dynamic>{};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (filter != null && filter.isNotEmpty) q['filter'] = filter;
    return apiClient.getData('$_base/customers', query: q.isEmpty ? null : q);
  }

  Future<Response> upsertCustomer(Map<String, dynamic> data) =>
      apiClient.postData('$_base/customers', data);

  Future<Response> showCustomer(int id) =>
      apiClient.getData('$_base/customers/$id');

  Future<Response> statement(int id, {String? from, String? to}) {
    final q = <String, dynamic>{};
    if (from != null) q['from'] = from;
    if (to != null) q['to'] = to;
    return apiClient.getData('$_base/customers/$id/statement',
        query: q.isEmpty ? null : q);
  }

  Future<Response> recordPayment(int id, String amount, {String? note}) =>
      apiClient.postData('$_base/customers/$id/payment', {
        'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  Future<Response> recordReturn(int id, String amount, {String? note}) =>
      apiClient.postData('$_base/customers/$id/return', {
        'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  Future<Response> recordAdjustment(int id, String signedAmount, String note) =>
      apiClient.postData('$_base/customers/$id/adjustment', {
        'signed_amount': signedAmount,
        'note': note,
      });
}

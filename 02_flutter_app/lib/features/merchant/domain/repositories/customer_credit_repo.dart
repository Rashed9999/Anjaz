import 'package:get/get.dart';
import 'package:uuid/uuid.dart';
import 'package:amial_pay/data/api/api_client.dart';

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

  /// A cash collection is not a wallet deposit; the server issues its voucher.
  Future<Response> collectCash(
      int id, String amount, String key, {String? note}) =>
      apiClient.postData('$_base/customers/$id/collect-cash', {
        'amount': amount,
        'idempotency_key': key,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  /// Request payment; the customer must authorize it in their own Amial app.
  Future<Response> requestWallet(int id, String amount, String key) =>
      apiClient.postData('$_base/customers/$id/request-wallet', {
        'amount': amount,
        'idempotency_key': key,
      });

  Future<Response> confirmWallet(int collectionId) =>
      apiClient.postData('$_base/collections/$collectionId/confirm', {});

  Future<Response> pendingCollections() =>
      apiClient.getData('$_base/collections/pending');

  Future<Response> collectionStatus(int collectionId) =>
      apiClient.getData('$_base/collections/$collectionId');

  Future<Response> receiptPdf(int collectionId) =>
      apiClient.getData('$_base/collections/$collectionId/receipt',
          headers: {'Accept': 'application/pdf'});

  // Legacy callers still produce a verifiable cash collection and voucher.
  Future<Response> recordPayment(int id, String amount, {String? note}) =>
      collectCash(id, amount, const Uuid().v4(), note: note);

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

import 'package:get/get.dart';
import 'package:uuid/uuid.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — نظام ديون العملاء (Flutter).
class CustomerCreditRepo extends GetxService {
  final ApiClient apiClient;
  final MerchantBusinessApiAdapter? _injectedBusinessAdapter;
  CustomerCreditRepo({
    required this.apiClient,
    MerchantBusinessApiAdapter? businessAdapter,
  }) : _injectedBusinessAdapter = businessAdapter;

  static const _base = '/api/v1/amial/merchant/credit';

  MerchantBusinessApiAdapter? get _business {
    final adapter = _injectedBusinessAdapter ??
        (Get.isRegistered<MerchantBusinessApiAdapter>()
            ? Get.find<MerchantBusinessApiAdapter>()
            : null);
    return (adapter != null && adapter.isEnabledForActiveMerchant) ? adapter : null;
  }

  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  Future<Response> listCustomers({String? search, String? filter}) {
    final biz = _business;
    if (biz != null) {
      return biz.listContacts(search: search);
    }
    final q = <String, dynamic>{};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (filter != null && filter.isNotEmpty) q['filter'] = filter;
    return apiClient.getData('$_base/customers', query: q.isEmpty ? null : q);
  }

  Future<Response> upsertCustomer(Map<String, dynamic> data) =>
      apiClient.postData('$_base/customers', data);

  Future<Response> showCustomer(int id) =>
      apiClient.getData('$_base/customers/$id');

  Future<Response> lookupByPhone(String phone) =>
      apiClient.getData('$_base/lookup', query: {'phone': phone});

  Future<Response> statement(int id, {String? from, String? to}) {
    final q = <String, dynamic>{};
    if (from != null) q['from'] = from;
    if (to != null) q['to'] = to;
    return apiClient.getData('$_base/customers/$id/statement',
        query: q.isEmpty ? null : q);
  }

  /// A cash collection is not a wallet deposit; the server issues its voucher.
  Future<Response> collectCash(
      int id, String amount, String key, {String? note}) {
    final biz = _business;
    if (biz != null) {
      return biz.collectFromContact(
        contactId: id,
        amount: amount,
        method: 'cash',
        idempotencyKey: key,
      );
    }
    return apiClient.postData('$_base/customers/$id/collect-cash', {
      'amount': amount,
      'idempotency_key': key,
      if (note != null && note.isNotEmpty) 'note': note,
    });
  }

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

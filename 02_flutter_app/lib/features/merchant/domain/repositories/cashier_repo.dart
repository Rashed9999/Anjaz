import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-CASHIER-001 — كاشير التاجر (Flutter).
class CashierRepo extends GetxService {
  final ApiClient apiClient;
  CashierRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/cashier';

  Future<Response> products({String? search}) {
    return apiClient.getData('$_base/products',
        query: search != null && search.isNotEmpty ? {'search': search} : null);
  }

  // AMIAL-CASHIER-BARCODE-001 — بحث منتج بالباركود
  Future<Response> lookupBarcode(String barcode) {
    return apiClient.getData('$_base/products/lookup', query: {'barcode': barcode});
  }

  Future<Response> addProduct(Map<String, dynamic> data) {
    return apiClient.postData('$_base/products', data);
  }

  Future<Response> updateProduct(int id, Map<String, dynamic> data) {
    return apiClient.putData('$_base/products/$id', data);
  }

  Future<Response> recordSale(Map<String, dynamic> data) {
    return apiClient.postData('$_base/sales', data);
  }

  Future<Response> settleCredit(int saleId, {String? paidTransactionId}) {    return apiClient.postData('$_base/sales/$saleId/settle',
        {'paid_transaction_id': ?paidTransactionId});
  }

  Future<Response> report({String? date}) {
    return apiClient.getData('$_base/report',
        query: date != null ? {'date': date} : null);
  }
}

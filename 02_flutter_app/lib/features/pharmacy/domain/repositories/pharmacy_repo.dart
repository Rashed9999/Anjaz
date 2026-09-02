import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-PHARMACY-001 — Repo الصيدلية.
class PharmacyRepo extends GetxService {
  final ApiClient apiClient;
  PharmacyRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/pharmacy';

  // Pharmacy
  Future<Response> getPharmacy() => apiClient.getData(_base);
  Future<Response> upsertPharmacy(Map<String, dynamic> data) => apiClient.postData(_base, data);
  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  // Products
  Future<Response> listProducts({String? search, bool lowStockOnly = false}) {
    final q = <String, dynamic>{};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (lowStockOnly) q['low_stock_only'] = '1';
    return apiClient.getData('$_base/products', query: q.isEmpty ? null : q);
  }
  Future<Response> listCategories() => apiClient.getData('$_base/categories');
  Future<Response> similarProducts(String query, {int? categoryId}) => apiClient.getData(
      '$_base/products/similar', query: {'query': query, if (categoryId != null) 'category_id': '$categoryId'});
  Future<Response> alternatives(int productId) => apiClient.getData('$_base/products/$productId/alternatives');
  Future<Response> addProduct(Map<String, dynamic> data) => apiClient.postData('$_base/products', data);
  Future<Response> updateProduct(int id, Map<String, dynamic> data) =>
      apiClient.putData('$_base/products/$id', data);

  // Batches
  Future<Response> listBatches(int productId) => apiClient.getData('$_base/products/$productId/batches');
  Future<Response> addBatch(int productId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/products/$productId/batches', data);
  Future<Response> recallBatch(int batchId, String reason) =>
      apiClient.postData('$_base/batches/$batchId/recall', {'reason': reason});
  Future<Response> disposeBatch(int batchId, String type, String reason) =>
      apiClient.postData('$_base/batches/$batchId/dispose', {'type': type, 'reason': reason});

  // Customers
  Future<Response> listCustomers({String? search}) {
    final q = <String, dynamic>{};
    if (search != null && search.isNotEmpty) q['search'] = search;
    return apiClient.getData('$_base/customers', query: q.isEmpty ? null : q);
  }
  Future<Response> findCustomerByPhone(String phone) =>
      apiClient.getData('$_base/customers/by-phone', query: {'phone': phone});
  Future<Response> addCustomer(Map<String, dynamic> data) => apiClient.postData('$_base/customers', data);
  Future<Response> updateCustomer(int id, Map<String, dynamic> data) =>
      apiClient.putData('$_base/customers/$id', data);

  // Sales
  Future<Response> recordSale(Map<String, dynamic> data) => apiClient.postData('$_base/sales', data);
  Future<Response> listSales() => apiClient.getData('$_base/sales');
  Future<Response> showSale(String ulid) => apiClient.getData('$_base/sales/$ulid');

  // Alerts
  Future<Response> listAlerts() => apiClient.getData('$_base/alerts');
  Future<Response> scanExpiring() => apiClient.postData('$_base/alerts/scan', {});
  Future<Response> dismissAlert(int id) => apiClient.postData('$_base/alerts/$id/dismiss', {});
}

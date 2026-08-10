import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — مستودعُ قطاع التجزئة.
///
/// **ولا نداءَ HTTP خارج هذه الطبقة.** الشاشةُ تنادي المتحكّم، والمتحكّم
/// ينادي المستودع، والمستودع وحدَه يعرف المسارات.
class RetailVerticalRepo extends GetxService {
  final ApiClient apiClient;
  RetailVerticalRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/retail';

  // ── مركز العمليّات والصلاحيّات ───────────────────────────────────
  Future<Response> ops() => apiClient.getData('$_base/ops');
  Future<Response> myPermissions() => apiClient.getData('$_base/me/permissions');
  Future<Response> roles() => apiClient.getData('$_base/roles');
  Future<Response> seedRoles() => apiClient.postData('$_base/roles/seed', {});

  // ── محرّك الأصناف ────────────────────────────────────────────────
  Future<Response> categories() => apiClient.getData('$_base/categories');
  Future<Response> addCategory(Map<String, dynamic> d) =>
      apiClient.postData('$_base/categories', d);

  Future<Response> brands() => apiClient.getData('$_base/brands');
  Future<Response> addBrand(Map<String, dynamic> d) =>
      apiClient.postData('$_base/brands', d);

  Future<Response> units() => apiClient.getData('$_base/units');
  Future<Response> addUnit(Map<String, dynamic> d) =>
      apiClient.postData('$_base/units', d);

  Future<Response> scan(String barcode) =>
      apiClient.getData('$_base/scan', query: {'barcode': barcode});

  Future<Response> addBarcode(int productId, Map<String, dynamic> d) =>
      apiClient.postData('$_base/products/$productId/barcodes', d);

  Future<Response> generateVariants(int productId, Map<String, dynamic> axes) =>
      apiClient.postData('$_base/products/$productId/variants', {'axes': axes});

  // ── المخزون والمواقع ─────────────────────────────────────────────
  Future<Response> locations() => apiClient.getData('$_base/locations');
  Future<Response> addLocation(Map<String, dynamic> d) =>
      apiClient.postData('$_base/locations', d);

  Future<Response> productStock(int productId) =>
      apiClient.getData('$_base/products/$productId/stock');
  Future<Response> movements(int productId) =>
      apiClient.getData('$_base/products/$productId/movements');
  Future<Response> priceHistory(int productId) =>
      apiClient.getData('$_base/products/$productId/price-history');

  // ── التحويلات ────────────────────────────────────────────────────
  Future<Response> transfers() => apiClient.getData('$_base/transfers');
  Future<Response> showTransfer(int id) => apiClient.getData('$_base/transfers/$id');
  Future<Response> requestTransfer(Map<String, dynamic> d) =>
      apiClient.postData('$_base/transfers', d);
  Future<Response> approveTransfer(int id) =>
      apiClient.postData('$_base/transfers/$id/approve', {});
  Future<Response> shipTransfer(int id, Map<String, dynamic> shipped) =>
      apiClient.postData('$_base/transfers/$id/ship', {'shipped': shipped});
  Future<Response> receiveTransfer(int id, Map<String, dynamic> received) =>
      apiClient.postData('$_base/transfers/$id/receive', {'received': received});
  Future<Response> cancelTransfer(int id, String reason) =>
      apiClient.postData('$_base/transfers/$id/cancel', {'reason': reason});

  // ── الجرد ────────────────────────────────────────────────────────
  Future<Response> counts() => apiClient.getData('$_base/counts');
  Future<Response> openCount(Map<String, dynamic> d) =>
      apiClient.postData('$_base/counts', d);
  Future<Response> countSheet(int id) => apiClient.getData('$_base/counts/$id');
  Future<Response> enterCount(int id, Map<String, dynamic> d) =>
      apiClient.postData('$_base/counts/$id/enter', d);
  Future<Response> submitCount(int id) =>
      apiClient.postData('$_base/counts/$id/submit', {});
  Future<Response> countVariances(int id) =>
      apiClient.getData('$_base/counts/$id/variances');
  Future<Response> approveCount(int id) =>
      apiClient.postData('$_base/counts/$id/approve', {});

  // ── الهالك ───────────────────────────────────────────────────────
  Future<Response> wastes({int days = 30}) =>
      apiClient.getData('$_base/wastes', query: {'days': '$days'});
  Future<Response> recordWaste(Map<String, dynamic> d) =>
      apiClient.postData('$_base/wastes', d);
  Future<Response> approveWaste(int id) =>
      apiClient.postData('$_base/wastes/$id/approve', {});
  Future<Response> rejectWaste(int id, String reason) =>
      apiClient.postData('$_base/wastes/$id/reject', {'reason': reason});

  // ── المرتجعات ────────────────────────────────────────────────────
  Future<Response> returnableLines(String saleUlid) =>
      apiClient.getData('$_base/sales/$saleUlid/returnable');
  Future<Response> createReturn(String saleUlid, Map<String, dynamic> d) =>
      apiClient.postData('$_base/sales/$saleUlid/returns', d);
  Future<Response> approveReturn(int id) =>
      apiClient.postData('$_base/returns/$id/approve', {});

  // ── الأسعار ──────────────────────────────────────────────────────
  Future<Response> proposePrice(Map<String, dynamic> d) =>
      apiClient.postData('$_base/prices/propose', d);
  Future<Response> pendingPrices() => apiClient.getData('$_base/prices/pending');
  Future<Response> approvePrice(int id) =>
      apiClient.postData('$_base/prices/$id/approve', {});
}

import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-FUEL-001 — مستودع محطة الوقود.
class FuelStationRepo extends GetxService {
  final ApiClient apiClient;
  FuelStationRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/fuel';

  // Station
  Future<Response> getStation() => apiClient.getData('$_base/station');
  Future<Response> upsertStation(Map<String, dynamic> data) =>
      apiClient.postData('$_base/station', data);

  // Pumps
  Future<Response> listPumps() => apiClient.getData('$_base/pumps');
  Future<Response> addPump(Map<String, dynamic> data) =>
      apiClient.postData('$_base/pumps', data);
  Future<Response> updatePump(int id, Map<String, dynamic> data) =>
      apiClient.putData('$_base/pumps/$id', data);
  Future<Response> linkPumpProducts(int id, List<Map<String, dynamic>> links) =>
      apiClient.postData('$_base/pumps/$id/link-products', {'links': links});

  // Products
  Future<Response> listProducts() => apiClient.getData('$_base/products');
  Future<Response> addProduct(Map<String, dynamic> data) =>
      apiClient.postData('$_base/products', data);
  Future<Response> updateProductPrice(int id, String newPrice, {String? note}) =>
      apiClient.putData('$_base/products/$id/price',
          {'price_per_liter': newPrice, if (note != null && note.isNotEmpty) 'note': note});

  // AMIAL-FUEL-PRICE-HISTORY-001 — سجلّ تغيّر الأسعار
  Future<Response> priceHistory({int limit = 30}) =>
      apiClient.getData('$_base/price-history?limit=$limit');

  // Sales (الجوهر)
  Future<Response> recordSale(Map<String, dynamic> data) =>
      apiClient.postData('$_base/sales', data);
  Future<Response> listSales({Map<String, dynamic>? filters}) =>
      apiClient.getData('$_base/sales', query: filters);
  Future<Response> showSale(String ulid) => apiClient.getData('$_base/sales/$ulid');
  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  // Companies
  Future<Response> listCompanies() => apiClient.getData('$_base/companies');
  Future<Response> addCompany(Map<String, dynamic> data) =>
      apiClient.postData('$_base/companies', data);
  Future<Response> recordCompanyPayment(int id, String amount, {String? note}) =>
      apiClient.postData('$_base/companies/$id/payment', {
        'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  // Cards
  Future<Response> listCards(int companyId) =>
      apiClient.getData('$_base/companies/$companyId/cards');
  Future<Response> addCard(int companyId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/companies/$companyId/cards', data);
  Future<Response> updateCard(int companyId, int cardId, Map<String, dynamic> data) =>
      apiClient.putData('$_base/companies/$companyId/cards/$cardId', data);

  // Shifts
  Future<Response> currentShift() => apiClient.getData('$_base/shifts/current');
  Future<Response> openShift(Map<String, dynamic> data) =>
      apiClient.postData('$_base/shifts/open', data);
  Future<Response> closeShift(int shiftId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/shifts/$shiftId/close', data);
  Future<Response> listShifts({int limit = 20}) =>
      apiClient.getData('$_base/shifts', query: {'limit': limit.toString()});
  Future<Response> showShift(int id) => apiClient.getData('$_base/shifts/$id');

  // Variance
  Future<Response> listVariances({String? status}) {
    final q = <String, dynamic>{};
    if (status != null && status.isNotEmpty) q['status'] = status;
    return apiClient.getData('$_base/variances', query: q.isEmpty ? null : q);
  }

  // Receipt PDF URL
  String receiptUrl(String saleUlid) => '$_base/sales/$saleUlid/receipt';
}

import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-WHOLESALE-001 — Repo الجملة.
class WholesaleRepo extends GetxService {
  final ApiClient apiClient;
  WholesaleRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/wholesale';

  // Business + Dashboard
  Future<Response> getBusiness() => apiClient.getData(_base);
  Future<Response> upsertBusiness(Map<String, dynamic> data) => apiClient.postData(_base, data);
  Future<Response> addPriceTier(Map<String, dynamic> data) =>
      apiClient.postData('$_base/price-tiers', data);
  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  // Products
  Future<Response> listProducts({String? search, bool lowStockOnly = false}) {
    final q = <String, dynamic>{};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (lowStockOnly) q['low_stock_only'] = '1';
    return apiClient.getData('$_base/products', query: q.isEmpty ? null : q);
  }
  Future<Response> addProduct(Map<String, dynamic> data) => apiClient.postData('$_base/products', data);
  Future<Response> updateProduct(int id, Map<String, dynamic> data) =>
      apiClient.putData('$_base/products/$id', data);
  Future<Response> adjustStock(int id, double newStock, String reason) =>
      apiClient.postData('$_base/products/$id/adjust-stock',
          {'new_stock': newStock, 'reason': reason});
  Future<Response> listProductUnits(int id) =>
      apiClient.getData('$_base/products/$id/units');
  Future<Response> saveProductUnit(int id, Map<String, dynamic> data) =>
      apiClient.postData('$_base/products/$id/units', data);
  Future<Response> listProductLots(int id) =>
      apiClient.getData('$_base/products/$id/lots');
  Future<Response> receiveProductLot(int id, Map<String, dynamic> data) =>
      apiClient.postData('$_base/products/$id/lots', data);

  // Multi-Pricing
  Future<Response> listProductPrices(int productId) =>
      apiClient.getData('$_base/products/$productId/prices');
  Future<Response> setProductPrice(int productId, int tierId, double price, double minQty) =>
      apiClient.postData('$_base/products/$productId/prices',
          {'tier_id': tierId, 'price': price, 'min_quantity': minQty});
  /// سعرٌ إرشاديٌّ من الخادم لنفس العميل والكمية. لا يُرسل السعر من التطبيق
  /// عند إنشاء الفاتورة؛ الخادم يعيد حسابه داخل المعاملة.
  Future<Response> quoteProduct(int productId, int customerId, double quantity,
      {int? unitId}) =>
      apiClient.getData('$_base/products/$productId/quote', query: {
        'customer_id': '$customerId',
        'quantity': '$quantity',
        if (unitId != null) 'unit_id': '$unitId',
      });

  // Customers
  Future<Response> listCustomers({String? search, bool withBalanceOnly = false}) {
    final q = <String, dynamic>{};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (withBalanceOnly) q['with_balance_only'] = '1';
    return apiClient.getData('$_base/customers', query: q.isEmpty ? null : q);
  }
  Future<Response> addCustomer(Map<String, dynamic> data) => apiClient.postData('$_base/customers', data);
  Future<Response> updateCustomer(int id, Map<String, dynamic> data) =>
      apiClient.putData('$_base/customers/$id', data);

  // Invoices
  Future<Response> listInvoices({int? customerId, String? status, bool overdueOnly = false}) {
    final q = <String, dynamic>{};
    if (customerId != null) q['customer_id'] = '$customerId';
    if (status != null && status.isNotEmpty) q['status'] = status;
    if (overdueOnly) q['overdue_only'] = '1';
    return apiClient.getData('$_base/invoices', query: q.isEmpty ? null : q);
  }
  Future<Response> showInvoice(int id) => apiClient.getData('$_base/invoices/$id');
  Future<Response> createInvoice(Map<String, dynamic> data) => apiClient.postData('$_base/invoices', data);
  Future<Response> createInvoicePaymentRequest(double amount, {String? note}) =>
      apiClient.postData('$_base/invoices/amial-payment-request', {
        'amount': amount.toStringAsFixed(4),
        if (note != null && note.isNotEmpty) 'note': note,
      });
  Future<Response> cancelWholesalePaymentRequest(int requestId) =>
      apiClient.postData('$_base/payment-requests/$requestId/cancel', {});
  Future<Response> voidInvoice(int id, String reason) =>
      apiClient.postData('$_base/invoices/$id/void', {'reason': reason});

  // Returns — workflow خاص بالجملة، منفصل عن MerchantRefundScreen العام.
  Future<Response> listReturns({String? status}) => apiClient.getData('$_base/returns',
      query: status == null ? null : {'status': status});
  Future<Response> requestReturn(int invoiceId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/invoices/$invoiceId/returns', data);
  Future<Response> resolveReturn(int returnId, bool approve, {String? note}) =>
      apiClient.postData('$_base/returns/$returnId/resolve', {
        'approve': approve,
        if (note != null && note.isNotEmpty) 'decision_note': note,
      });

  /// AMIAL-WHOLESALE-PDF — تحميل PDF الفاتورة (binary).
  /// نستخدم http مباشرة بدلاً من apiClient.getData لأنّ الردّ binary وليس JSON.
  String invoicePdfUrl(int invoiceId) => '$_base/invoices/$invoiceId/pdf';

  // Collections
  Future<Response> recordCollection(int invoiceId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/invoices/$invoiceId/collect', data);
  Future<Response> createCollectionPaymentRequest(int invoiceId, double amount,
          {String? note}) =>
      apiClient.postData('$_base/invoices/$invoiceId/amial-payment-request', {
        'amount': amount.toStringAsFixed(4),
        if (note != null && note.isNotEmpty) 'note': note,
      });
  Future<Response> listCollections({int? customerId}) {
    final q = <String, dynamic>{};
    if (customerId != null) q['customer_id'] = '$customerId';
    return apiClient.getData('$_base/collections', query: q.isEmpty ? null : q);
  }

  // Sales Reps
  Future<Response> listSalesReps() => apiClient.getData('$_base/sales-reps');
  Future<Response> addSalesRep(Map<String, dynamic> data) => apiClient.postData('$_base/sales-reps', data);

  // Reports
  Future<Response> agingReport() => apiClient.getData('$_base/reports/aging');
  Future<Response> customerStatement(int customerId, {String? from, String? to}) {
    final q = <String, dynamic>{};
    if (from != null) q['from'] = from;
    if (to != null) q['to'] = to;
    return apiClient.getData('$_base/reports/customer/$customerId/statement',
        query: q.isEmpty ? null : q);
  }
  Future<Response> salesRepsReport({String? from, String? to}) {
    final q = <String, dynamic>{};
    if (from != null) q['from'] = from;
    if (to != null) q['to'] = to;
    return apiClient.getData('$_base/reports/sales-reps', query: q.isEmpty ? null : q);
  }
}

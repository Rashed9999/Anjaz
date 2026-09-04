import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-CASHIER-001 — كاشير التاجر (Flutter).
class CashierRepo extends GetxService {
  final ApiClient apiClient;
  CashierRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/cashier';

  /// AMIAL-VARIANT-EDITOR-001 — `includeVariantParents` لشاشة **الإدارة**.
  ///
  /// شبكةُ البيع لا تعرض مِظلّةَ المتغيّرات (لا تُباع)، **وشاشةُ إدارة
  /// المنتجات يجب أن تعرضها** — وإلّا لم يبقَ للتاجر بابٌ إليها: لا
  /// تعديلُ اسمٍ ولا وصولٌ إلى «الأنواع» ليوزّع مخزونَها.
  Future<Response> products({String? search, bool includeVariantParents = false}) {
    final q = <String, String>{
      if (search != null && search.isNotEmpty) 'search': search,
      if (includeVariantParents) 'include_variant_parents': '1',
    };

    return apiClient.getData('$_base/products', query: q.isEmpty ? null : q);
  }

  // AMIAL-CASHIER-BARCODE-001 — بحث منتج بالباركود
  /// AMIAL-CATALOG-001 — البحثُ في الكتالوج المشترك.
  ///
  /// **وهو غيرُ `lookupBarcode` أدناه**: ذاك يبحث في منتجات التاجر نفسِه
  /// ليضيفها للسلّة عند البيع، وهذا في الكتالوج العامّ ليملأ الحقولَ عند
  /// إنشاء صنفٍ جديد. شاشتان ونداءان — ولا يُخلطان.
  /// AMIAL-CATALOG-ADOPT-001 — يُضاف الصنفُ من الكتالوج بضغطةٍ واحدة.
  Future<Response> adoptFromCatalog(Map<String, dynamic> data) {
    return apiClient.postData('/api/v1/amial/merchant/cashier/products/adopt', data);
  }

  Future<Response> catalogLookup(String barcode) {
    return apiClient.getData('/api/v1/amial/catalog/lookup', query: {'barcode': barcode});
  }

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

  /// AMIAL-CASHIER-REFUND-001 — قائمة مبيعات اليوم بمعرّفاتها (مدخل الاسترجاع).
  Future<Response> sales({String? date}) {
    return apiClient.getData('$_base/sales',
        query: date != null ? {'date': date} : null);
  }

  /// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — تفصيلُ بيعةٍ سطراً سطراً
  /// بالتكلفة الملتقَطة والهامش لكلّ سطر.
  Future<Response> saleDetail(String saleUlid) =>
      apiClient.getData('$_base/sales/$saleUlid');

  /// AMIAL-PROFIT-001 — تقرير الربحية (إجماليات + اتجاه يومي + منتجات).
  Future<Response> profitReport({int days = 7}) =>
      apiClient.getData('$_base/profit-report?days=$days');
}

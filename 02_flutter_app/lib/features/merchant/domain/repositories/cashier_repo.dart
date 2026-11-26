import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';

/// AMIAL-CASHIER-001 — كاشير التاجر (Flutter).
///
/// AMIAL-BUSINESS-UNIFY-U2: يوجّه العمليات التشغيلية اختيارياً إلى خادم
/// أميال أعمال (`/api/v1/mobile/*`) فقط عند تفعيل `merchantBusinessBackendEnabled`
/// للتاجر النشط، ويبقي جميع التجار غير المرحّلين على مسارات أميال باي القديمة.
class CashierRepo extends GetxService {
  final ApiClient apiClient;
  final MerchantBusinessApiAdapter? _injectedBusinessAdapter;
  CashierRepo({
    required this.apiClient,
    MerchantBusinessApiAdapter? businessAdapter,
  }) : _injectedBusinessAdapter = businessAdapter;

  static const _base = '/api/v1/amial/merchant/cashier';

  MerchantBusinessApiAdapter? get _business {
    final adapter = _injectedBusinessAdapter ??
        (Get.isRegistered<MerchantBusinessApiAdapter>()
            ? Get.find<MerchantBusinessApiAdapter>()
            : null);
    if (adapter != null && adapter.isEnabledForActiveMerchant) {
      return adapter;
    }
    return null;
  }

  /// AMIAL-VARIANT-EDITOR-001 — `includeVariantParents` لشاشة **الإدارة**.
  ///
  /// شبكةُ البيع لا تعرض مِظلّةَ المتغيّرات (لا تُباع)، **وشاشةُ إدارة
  /// المنتجات يجب أن تعرضها** — وإلّا لم يبقَ للتاجر بابٌ إليها: لا
  /// تعديلُ اسمٍ ولا وصولٌ إلى «الأنواع» ليوزّع مخزونَها.
  Future<Response> products({String? search, bool includeVariantParents = false}) {
    final biz = _business;
    if (biz != null && !includeVariantParents) {
      return biz.products(search: search);
    }
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
    final biz = _business;
    if (biz != null) {
      return biz.lookupBarcode(barcode);
    }
    return apiClient.getData('$_base/products/lookup', query: {'barcode': barcode});
  }

  Future<Response> addProduct(Map<String, dynamic> data) {
    return apiClient.postData('$_base/products', data);
  }

  Future<Response> updateProduct(int id, Map<String, dynamic> data) {
    return apiClient.putData('$_base/products/$id', data);
  }

  Future<Response> recordSale(Map<String, dynamic> data) {
    final biz = _business;
    if (biz != null) {
      return biz.recordSale(data);
    }
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
    final biz = _business;
    if (biz != null) {
      return biz.sales();
    }
    return apiClient.getData('$_base/sales',
        query: date != null ? {'date': date} : null);
  }

  /// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — تفصيلُ بيعةٍ سطراً سطراً
  /// بالتكلفة الملتقَطة والهامش لكلّ سطر.
  Future<Response> saleDetail(String saleUlid) {
    final biz = _business;
    if (biz != null) {
      return biz.saleDetail(saleUlid);
    }
    return apiClient.getData('$_base/sales/$saleUlid');
  }

  /// AMIAL-PROFIT-001 — تقرير الربحية (إجماليات + اتجاه يومي + منتجات).
  Future<Response> profitReport({int days = 7}) =>
      apiClient.getData('$_base/profit-report?days=$days');

  /// AMIAL-SALES-BREAKDOWN-001 — المبيعاتُ بالصنف وبالتصنيف على مدىً
  /// يختاره التاجر، **ومطروحاً منه المرتجَع**.
  Future<Response> salesBreakdown({String? from, String? to}) {
    final q = <String>[
      if (from != null) 'from=$from',
      if (to != null) 'to=$to',
    ].join('&');

    return apiClient.getData(
        '$_base/sales-breakdown${q.isEmpty ? '' : '?$q'}');
  }

  // ── AMIAL-HELD-SALE-001 — التذاكر المفتوحة (تعليق الفاتورة) ─────────

  /// التذاكرُ المعلَّقةُ للمنشأة — **لا للجهاز**: الزبونُ قد ينتقل إلى
  /// الصندوق الآخر، فيستأنفها زميلُه.
  Future<Response> heldTickets() {
    final biz = _business;
    if (biz != null) {
      return biz.heldTickets();
    }
    return apiClient.getData('$_base/held');
  }

  Future<Response> holdCart(Map<String, dynamic> data) {
    final biz = _business;
    if (biz != null) {
      return biz.holdCart(data);
    }
    return apiClient.postData('$_base/held', data);
  }

  Future<Response> resumeHeld(String ulid) {
    final biz = _business;
    if (biz != null) {
      return biz.resumeHeld(ulid);
    }
    return apiClient.postData('$_base/held/$ulid/resume', {});
  }

  /// **تراجعٌ عن الاستئناف** — وبدونه تضيع السلّةُ إن سقط الدفع.
  Future<Response> reopenHeld(String ulid) {
    final biz = _business;
    if (biz != null) {
      return biz.reopenHeld(ulid);
    }
    return apiClient.postData('$_base/held/$ulid/reopen', {});
  }

  Future<Response> voidHeld(String ulid, String reason) {
    final biz = _business;
    if (biz != null) {
      return biz.discardHeld(ulid);
    }
    return apiClient.postData('$_base/held/$ulid/void', {'reason': reason});
  }
}

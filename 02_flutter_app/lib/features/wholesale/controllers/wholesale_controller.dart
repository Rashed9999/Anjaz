import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/features/wholesale/domain/repositories/wholesale_repo.dart';
import 'package:amial_pay/features/plans/screens/my_usage_screen.dart';

/// AMIAL-WHOLESALE-001 — متحكّم الجملة.
///
/// AMIAL-WHOLESALE-UI-002 — لا تُحوَّل قراءةٌ فاشلة إلى صفرٍ صامت.
/// تحمل [loadState] الحالة التشغيلية الأخيرة لكي تفرّق الشاشات بين:
/// loading / ready / empty / permission / offline / maintenance / error.
class WholesaleController extends GetxController implements GetxService {
  final WholesaleRepo repo;
  WholesaleController({required this.repo});

  // State
  final Rx<Map<String, dynamic>?> business = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> dashboardData = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> priceTiers = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> products = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> customers = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> invoices = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> collections = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> returns = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> salesReps = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> currentInvoice = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> agingReport = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> currentStatement = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> salesRepsReport = Rx<Map<String, dynamic>?>(null);

  // سلّة فاتورة جديدة
  final RxList<Map<String, dynamic>> cart = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> selectedCustomer = Rx<Map<String, dynamic>?>(null);

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString loadState = 'idle'.obs;

  // ============ Business + Dashboard ============

  Future<void> loadBusiness() async {
    try {
      final r = await repo.getBusiness();
      if (_ok(r)) {
        business.value = Map<String, dynamic>.from(
            (r.body['meta']?['business'] ?? {}) as Map);
        final tiers = ((business.value?['price_tiers'] ?? []) as List);
        priceTiers.assignAll(
            tiers.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {
      // ملف النشاط مساعد للعنوان، فلا يغيّر حالة الشاشة الرئيسية وحده.
    }
  }

  /// إعدادات المنشأة وشرائح العملاء حقائق خادمية؛ لا نحفظها محلياً كي لا
  /// يختلف سعر العميل بين جهاز وآخر.
  Future<bool> saveBusiness(Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.upsertBusiness(data);
      if (_ok(r)) {
        business.value = Map<String, dynamic>.from(
            (r.body['meta']?['business'] ?? {}) as Map);
        final tiers = (business.value?['price_tiers'] ?? []) as List;
        priceTiers.assignAll(
            tiers.map((e) => Map<String, dynamic>.from(e as Map)).toList());
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذر حفظ إعدادات المنشأة';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> addPriceTier(Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.addPriceTier(data);
      if (_ok(r)) {
        await loadBusiness();
        return true;
      }
      lastError.value = _msg(r) ?? 'تعذر إضافة شريحة السعر';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> loadDashboard() async {
    _startLoad();
    try {
      final r = await repo.dashboard();
      if (_ok(r)) {
        dashboardData.value =
            Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        loadState.value = 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  // ============ Products ============

  Future<void> loadProducts({String? search, bool lowStockOnly = false}) async {
    _startLoad();
    try {
      final r = await repo.listProducts(search: search, lowStockOnly: lowStockOnly);
      if (_ok(r)) {
        final list = (r.body['meta']?['products'] ?? []) as List;
        products.assignAll(
            list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
        loadState.value = products.isEmpty ? 'empty' : 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> addProduct(Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addProduct(data), () => loadProducts());

  Future<bool> updateProduct(int id, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.updateProduct(id, data), () => loadProducts());

  Future<bool> adjustStock(int id, double newStock, String reason) async =>
      _doAndReload(
          () => repo.adjustStock(id, newStock, reason), () => loadProducts());

  Future<Map<String, dynamic>?> loadProductPrices(int productId) async {
    try {
      final r = await repo.listProductPrices(productId);
      if (_ok(r)) return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      lastError.value = _msg(r) ?? 'تعذر تحميل أسعار المنتج';
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    }
    return null;
  }

  Future<bool> setProductPrice(int productId, int tierId, double price, double minQty) async =>
      _doAndReload(() => repo.setProductPrice(productId, tierId, price, minQty),
          () => loadProducts());

  Future<Map<String, dynamic>?> loadProductUnits(int productId) async {
    try {
      final r = await repo.listProductUnits(productId);
      if (_ok(r)) return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      lastError.value = _msg(r) ?? 'تعذر تحميل وحدات المنتج';
    } catch (_) { lastError.value = 'خطأ في الشبكة'; }
    return null;
  }

  Future<Map<String, dynamic>?> loadProductLots(int productId) async {
    try {
      final r = await repo.listProductLots(productId);
      if (_ok(r)) return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      lastError.value = _msg(r) ?? 'تعذر تحميل دفعات المنتج';
    } catch (_) { lastError.value = 'خطأ في الشبكة'; }
    return null;
  }

  Future<bool> saveProductUnit(int productId, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.saveProductUnit(productId, data), () => loadProducts());

  Future<bool> receiveProductLot(int productId, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.receiveProductLot(productId, data), () => loadProducts());

  // ============ Customers ============

  Future<void> loadCustomers({String? search, bool withBalanceOnly = false}) async {
    _startLoad();
    try {
      final r = await repo.listCustomers(
          search: search, withBalanceOnly: withBalanceOnly);
      if (_ok(r)) {
        final list = (r.body['meta']?['customers'] ?? []) as List;
        customers.assignAll(
            list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
        loadState.value = customers.isEmpty ? 'empty' : 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> addCustomer(Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addCustomer(data), () => loadCustomers());

  Future<bool> updateCustomer(int id, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.updateCustomer(id, data), () => loadCustomers());

  Future<void> loadSalesReps() async {
    try {
      final r = await repo.listSalesReps();
      if (_ok(r)) {
        final list = (r.body['meta']?['sales_reps'] ?? []) as List;
        salesReps.assignAll(
            list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {
      // المندوب اختياري في الفاتورة؛ لا نمنع البيع إذا تعذر تحميل قائمته.
    }
  }

  Future<bool> addSalesRep(Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addSalesRep(data), loadSalesReps);

  // ============ Cart ============

  Future<bool> addToCart(Map<String, dynamic> product, double qty,
      {Map<String, dynamic>? unit}) async {
    if (selectedCustomer.value == null) {
      lastError.value = 'اختر العميل أولاً لتطبيق شريحة السعر الصحيحة';
      return false;
    }
    Map<String, dynamic> quote = {};
    try {
      final r = await repo.quoteProduct(
        (product['id'] as num).toInt(),
        (selectedCustomer.value!['id'] as num).toInt(),
        qty,
        unitId: unit?['id'] is num ? (unit!['id'] as num).toInt() : null,
      );
      if (_ok(r)) {
        quote = Map<String, dynamic>.from((r.body['meta']?['quote'] ?? {}) as Map);
      } else {
        lastError.value = _msg(r) ?? 'تعذر تسعير الصنف للعميل';
        return false;
      }
    } catch (_) {
      lastError.value = 'تعذر الاتصال لتسعير الصنف';
      return false;
    }
    final unitId = quote['unit_id'] ?? unit?['id'];
    final idx = cart.indexWhere((c) => c['product_id'] == product['id'] && c['unit_id'] == unitId);
    final pricedProduct = {...product, 'quoted_unit_price': quote['unit_price'],
      'quoted_unit': quote['unit'] ?? unit?['name'] ?? product['unit']};
    if (idx >= 0) {
      cart[idx] = {...cart[idx], 'quantity': qty, 'product': pricedProduct};
    } else {
      cart.add({
        'product_id': product['id'],
        'product': pricedProduct,
        if (unitId != null) 'unit_id': unitId,
        'quantity': qty,
        'discount_per_unit': 0.0,
      });
    }
    cart.refresh();
    return true;
  }

  void removeFromCart(int productId, {dynamic unitId}) {
    cart.removeWhere((c) => c['product_id'] == productId && c['unit_id'] == unitId);
  }

  void clearCart() {
    cart.clear();
    selectedCustomer.value = null;
  }

  double get cartSubtotal {
    double total = 0;
    for (final item in cart) {
      final price = double.tryParse('${item['product']?['quoted_unit_price'] ?? item['product']?['base_price']}') ?? 0;
      final qty = double.tryParse('${item['quantity']}') ?? 0;
      final discount = double.tryParse('${item['discount_per_unit']}') ?? 0;
      total += (price - discount).clamp(0, double.infinity).toDouble() * qty;
    }
    return total;
  }

  // ============ Invoices ============

  Future<bool> createInvoice({
    required String paymentType,
    String? paidTransactionId,
    String? dueDate,
    String? discountAmount,
    String? taxRate,
    int? salesRepId,
    String? notes,
  }) async {
    if (cart.isEmpty || selectedCustomer.value == null) {
      lastError.value = 'السلّة فارغة أو العميل غير محدّد';
      return false;
    }

    final items = cart
        .map((c) => {
              'product_id': c['product_id'],
              'quantity': c['quantity'],
              if (c['unit_id'] != null) 'unit_id': c['unit_id'],
              if ((c['discount_per_unit'] ?? 0) > 0)
                'discount_per_unit': c['discount_per_unit'],
            })
        .toList();

    final data = <String, dynamic>{
      'items': items,
      'customer_id': selectedCustomer.value!['id'],
      'payment_type': paymentType,
      if (paidTransactionId != null && paidTransactionId.isNotEmpty)
        'paid_transaction_id': paidTransactionId,
      if (dueDate != null && dueDate.isNotEmpty) 'due_date': dueDate,
      if (discountAmount != null && discountAmount.isNotEmpty)
        'discount_amount': discountAmount,
      if (taxRate != null && taxRate.isNotEmpty) 'tax_rate': taxRate,
      if (salesRepId != null) 'sales_rep_id': salesRepId,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    };

    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.createInvoice(data);
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if (_ok(r)) {
        currentInvoice.value = Map<String, dynamic>.from(
            (r.body['meta']?['invoice'] ?? {}) as Map);
        clearCart();
        await loadDashboard();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// طلب تحصيل QR خاص بالجملة: الخادم ينشئه باسم مالك التاجر حتى عند
  /// تشغيل الشاشة من حساب نقطة البيع.
  Future<Map<String, dynamic>?> createInvoicePaymentRequest(
      double amount, String? note) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.createInvoicePaymentRequest(amount, note: note);
      if (_ok(r)) {
        return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      }
      lastError.value = _msg(r) ?? 'تعذّر إنشاء طلب تحصيل أميال باي';
      return null;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> cancelWholesalePaymentRequest(int requestId) async {
    try {
      final r = await repo.cancelWholesalePaymentRequest(requestId);
      if (_ok(r)) return true;
      lastError.value = _msg(r) ?? 'تعذّر إلغاء طلب التحصيل';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    }
  }

  Future<void> loadInvoices({String? status, bool overdueOnly = false}) async {
    _startLoad();
    try {
      final r = await repo.listInvoices(status: status, overdueOnly: overdueOnly);
      if (_ok(r)) {
        final list = (r.body['meta']?['invoices'] ?? []) as List;
        invoices.assignAll(
            list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
        loadState.value = invoices.isEmpty ? 'empty' : 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> loadInvoiceDetails(int id) async {
    _startLoad();
    currentInvoice.value = null;
    try {
      final r = await repo.showInvoice(id);
      if (_ok(r)) {
        currentInvoice.value = Map<String, dynamic>.from(
            (r.body['meta']?['invoice'] ?? {}) as Map);
        loadState.value = 'ready';
        return true;
      }
      _classifyFailure(r);
      return false;
    } catch (_) {
      _offline();
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> voidInvoice(int id, String reason) async =>
      _doAndReload(() => repo.voidInvoice(id, reason), () => loadInvoices());

  Future<void> loadReturns({String? status}) async {
    _startLoad();
    try {
      final r = await repo.listReturns(status: status);
      if (_ok(r)) {
        final list = (r.body['meta']?['returns'] ?? []) as List;
        returns.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
        loadState.value = returns.isEmpty ? 'empty' : 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> requestReturn(int invoiceId, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.requestReturn(invoiceId, data), () => loadReturns());

  Future<bool> resolveReturn(int returnId, bool approve, {String? note}) async =>
      _doAndReload(() => repo.resolveReturn(returnId, approve, note: note), () => loadReturns());

  // ============ Collections ============

  Future<bool> recordCollection(int invoiceId, Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.recordCollection(invoiceId, data);
      if (_ok(r)) {
        await loadInvoiceDetails(invoiceId);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<Map<String, dynamic>?> createCollectionPaymentRequest(
      int invoiceId, double amount, String? note) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.createCollectionPaymentRequest(invoiceId, amount,
          note: note);
      if (_ok(r)) return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      lastError.value = _msg(r) ?? 'تعذّر إنشاء طلب تحصيل أميال باي';
      return null;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ============ PDF Download ============

  Future<bool> downloadInvoicePdf(int invoiceId) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';

      final apiClient = Get.find<ApiClient>();
      final url =
          '${AppConstants.baseUrl}/api/v1/amial/merchant/wholesale/invoices/$invoiceId/pdf';
      final headers = <String, String>{'Accept': 'application/pdf'};
      if (apiClient.token != null && apiClient.token!.isNotEmpty) {
        headers['Authorization'] = 'Bearer ${apiClient.token}';
      }

      final res = await http.get(Uri.parse(url), headers: headers);
      if (res.statusCode == 200 &&
          (res.headers['content-type']?.contains('application/pdf') ?? false)) {
        await PdfDownloaderHelper.downloadAndOpenPdf(
          pdfData: Uint8List.fromList(res.bodyBytes),
          baseFileName: 'wholesale_invoice_$invoiceId',
        );
        return true;
      }
      lastError.value = 'تعذّر تحميل الـ PDF (${res.statusCode})';
      return false;
    } catch (e) {
      final detail = e.toString();
      lastError.value =
          'خطأ في التحميل: ${detail.substring(0, detail.length.clamp(0, 50))}';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ============ Reports ============

  Future<void> loadAgingReport() async {
    _startLoad();
    agingReport.value = null;
    try {
      final r = await repo.agingReport();
      if (_ok(r)) {
        agingReport.value = Map<String, dynamic>.from(
            (r.body['meta']?['report'] ?? {}) as Map);
        loadState.value = 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadCustomerStatement(int customerId) async {
    _startLoad();
    currentStatement.value = null;
    try {
      final r = await repo.customerStatement(customerId);
      if (_ok(r)) {
        currentStatement.value = Map<String, dynamic>.from(
            (r.body['meta']?['statement'] ?? {}) as Map);
        loadState.value = 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadSalesRepsReport({String? from, String? to}) async {
    _startLoad();
    salesRepsReport.value = null;
    try {
      final r = await repo.salesRepsReport(from: from, to: to);
      if (_ok(r)) {
        salesRepsReport.value = Map<String, dynamic>.from(
            (r.body['meta']?['report'] ?? {}) as Map);
        loadState.value = 'ready';
      } else {
        _classifyFailure(r);
      }
    } catch (_) {
      _offline();
    } finally {
      isLoading.value = false;
    }
  }

  // ============ Helpers ============

  void _startLoad() {
    isLoading.value = true;
    lastError.value = '';
    loadState.value = 'loading';
  }

  void _offline() {
    lastError.value = 'تعذّر الاتصال بالخادم — تحقّق من الشبكة';
    loadState.value = 'offline';
  }

  void _classifyFailure(Response r) {
    lastError.value = _msg(r) ?? 'تعذّر تحميل البيانات';
    switch (r.statusCode) {
      case 403:
        loadState.value = 'permission';
        break;
      case 503:
        loadState.value = 'maintenance';
        break;
      case 0:
      case -1:
      case null:
        loadState.value = 'offline';
        break;
      default:
        loadState.value = 'error';
    }
  }

  Future<bool> _doAndReload(
      Future<Response> Function() action, Future<void> Function() reload) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if (_ok(r)) {
        await reload();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشلت العملية';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map &&
      r.body['success'] == true;

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}

import 'dart:typed_data';
import 'package:http/http.dart' as http;
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/features/wholesale/domain/repositories/wholesale_repo.dart';
import 'package:amial_pay/features/plans/screens/my_usage_screen.dart';

/// AMIAL-WHOLESALE-001 — متحكّم الجملة.
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
  final RxList<Map<String, dynamic>> salesReps = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> currentInvoice = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> agingReport = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> currentStatement = Rx<Map<String, dynamic>?>(null);

  // سلّة فاتورة جديدة
  final RxList<Map<String, dynamic>> cart = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> selectedCustomer = Rx<Map<String, dynamic>?>(null);

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ============ Business + Dashboard ============

  Future<void> loadBusiness() async {
    try {
      final r = await repo.getBusiness();
      if (_ok(r)) {
        business.value = Map<String, dynamic>.from((r.body['meta']?['business'] ?? {}) as Map);
        final tiers = ((business.value?['price_tiers'] ?? []) as List);
        priceTiers.assignAll(tiers.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {}
  }

  Future<void> loadDashboard() async {
    try {
      final r = await repo.dashboard();
      if (_ok(r)) dashboardData.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    } catch (_) {}
  }

  // ============ Products ============

  Future<void> loadProducts({String? search, bool lowStockOnly = false}) async {
    try {
      isLoading.value = true;
      final r = await repo.listProducts(search: search, lowStockOnly: lowStockOnly);
      if (_ok(r)) {
        final list = (r.body['meta']?['products'] ?? []) as List;
        products.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addProduct(Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addProduct(data), () => loadProducts());

  // ============ Customers ============

  Future<void> loadCustomers({String? search, bool withBalanceOnly = false}) async {
    try {
      isLoading.value = true;
      final r = await repo.listCustomers(search: search, withBalanceOnly: withBalanceOnly);
      if (_ok(r)) {
        final list = (r.body['meta']?['customers'] ?? []) as List;
        customers.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addCustomer(Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addCustomer(data), () => loadCustomers());

  // ============ Cart ============

  void addToCart(Map<String, dynamic> product, double qty) {
    final idx = cart.indexWhere((c) => c['product_id'] == product['id']);
    if (idx >= 0) {
      cart[idx] = {...cart[idx], 'quantity': qty};
    } else {
      cart.add({
        'product_id': product['id'],
        'product': product,
        'quantity': qty,
        'discount_per_unit': 0.0,
      });
    }
    cart.refresh();
  }

  void removeFromCart(int productId) {
    cart.removeWhere((c) => c['product_id'] == productId);
  }

  void clearCart() {
    cart.clear();
    selectedCustomer.value = null;
  }

  double get cartSubtotal {
    double total = 0;
    for (final item in cart) {
      final price = double.tryParse('${item['product']?['base_price']}') ?? 0;
      final qty = double.tryParse('${item['quantity']}') ?? 0;
      total += price * qty;
    }
    return total;
  }

  // ============ Invoices ============

  Future<bool> createInvoice({
    required String paymentType,
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

    final items = cart.map((c) => {
      'product_id': c['product_id'],
      'quantity': c['quantity'],
      if ((c['discount_per_unit'] ?? 0) > 0) 'discount_per_unit': c['discount_per_unit'],
    }).toList();

    final data = <String, dynamic>{
      'items': items,
      'customer_id': selectedCustomer.value!['id'],
      'payment_type': paymentType,
      'due_date': ?dueDate,
      if (discountAmount != null && discountAmount.isNotEmpty) 'discount_amount': discountAmount,
      if (taxRate != null && taxRate.isNotEmpty) 'tax_rate': taxRate,
      'sales_rep_id': ?salesRepId,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    };

    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.createInvoice(data);
      // CRITICAL-001-USAGE — التقاط 402 وعرض الحوار
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if (_ok(r)) {
        currentInvoice.value = Map<String, dynamic>.from((r.body['meta']?['invoice'] ?? {}) as Map);
        clearCart();
        await loadDashboard();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isSubmitting.value = false; }
  }

  Future<void> loadInvoices({String? status, bool overdueOnly = false}) async {
    try {
      isLoading.value = true;
      final r = await repo.listInvoices(status: status, overdueOnly: overdueOnly);
      if (_ok(r)) {
        final list = (r.body['meta']?['invoices'] ?? []) as List;
        invoices.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> loadInvoiceDetails(int id) async {
    try {
      isLoading.value = true;
      final r = await repo.showInvoice(id);
      if (_ok(r)) {
        currentInvoice.value = Map<String, dynamic>.from((r.body['meta']?['invoice'] ?? {}) as Map);
        return true;
      }
      return false;
    } catch (_) { return false; } finally { isLoading.value = false; }
  }

  Future<bool> voidInvoice(int id, String reason) async =>
      _doAndReload(() => repo.voidInvoice(id, reason), () => loadInvoices());

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
    } finally { isSubmitting.value = false; }
  }

  // ============ PDF Download ============

  /// يحمّل الـ PDF binary ويفتحه بـ open_file.
  /// يستخدم نفس الـ helper الموجود في المشروع.
  ///
  /// يُرجع true عند النجاح، false عند الفشل (مع تعبئة lastError).
  Future<bool> downloadInvoicePdf(int invoiceId) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';

      final apiClient = Get.find<ApiClient>();
      final url = '${AppConstants.baseUrl}/api/v1/amial/merchant/wholesale/invoices/$invoiceId/pdf';
      final headers = <String, String>{
        'Accept': 'application/pdf',
      };
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
      lastError.value = 'خطأ في التحميل: ${e.toString().substring(0, 50)}';
      return false;
    } finally { isSubmitting.value = false; }
  }

  // ============ Reports ============

  Future<void> loadAgingReport() async {
    try {
      isLoading.value = true;
      final r = await repo.agingReport();
      if (_ok(r)) agingReport.value = Map<String, dynamic>.from((r.body['meta']?['report'] ?? {}) as Map);
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<void> loadCustomerStatement(int customerId) async {
    try {
      isLoading.value = true;
      final r = await repo.customerStatement(customerId);
      if (_ok(r)) currentStatement.value = Map<String, dynamic>.from((r.body['meta']?['statement'] ?? {}) as Map);
    } catch (_) {} finally { isLoading.value = false; }
  }

  // ============ Helpers ============

  Future<bool> _doAndReload(Future<Response> Function() action, Future<void> Function() reload) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
      // CRITICAL-001-USAGE — التقاط 402 (يصيب add_product)
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if (_ok(r)) { await reload(); return true; }
      lastError.value = _msg(r) ?? 'فشلت العملية';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isSubmitting.value = false; }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;

  String? _msg(Response r) {
    try { if (r.body is Map) return r.body['message']?.toString(); } catch (_) {}
    return null;
  }
}

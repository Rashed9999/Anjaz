import 'package:get/get.dart';
import 'package:amial_pay/features/pharmacy/domain/repositories/pharmacy_repo.dart';
import 'package:amial_pay/features/plans/screens/my_usage_screen.dart';

/// AMIAL-PHARMACY-001 — متحكّم الصيدلية.
class PharmacyController extends GetxController implements GetxService {
  final PharmacyRepo repo;
  PharmacyController({required this.repo});

  // State
  final Rx<Map<String, dynamic>?> pharmacy = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> dashboardData = Rx<Map<String, dynamic>?>(null);

  final RxList<Map<String, dynamic>> products = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> batches = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> customers = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> sales = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> alerts = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> alertsSummary = Rx<Map<String, dynamic>?>(null);

  // سلّة البيع الحالية
  final RxList<Map<String, dynamic>> cart = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> selectedCustomer = Rx<Map<String, dynamic>?>(null);

  // آخر بيع للنجاح
  final Rx<Map<String, dynamic>?> lastSale = Rx<Map<String, dynamic>?>(null);

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ============ Pharmacy + Dashboard ============

  Future<void> loadPharmacy() async {
    try {
      final r = await repo.getPharmacy();
      if (_ok(r)) pharmacy.value = Map<String, dynamic>.from((r.body['meta']?['pharmacy'] ?? {}) as Map);
    } catch (_) {}
  }

  Future<bool> savePharmacy(Map<String, dynamic> data) async {
    return _doAndReload(() => repo.upsertPharmacy(data), loadPharmacy);
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

  Future<bool> updateProduct(int id, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.updateProduct(id, data), () => loadProducts());

  // ============ Batches ============

  Future<void> loadBatches(int productId) async {
    try {
      isLoading.value = true;
      final r = await repo.listBatches(productId);
      if (_ok(r)) {
        final list = (r.body['meta']?['batches'] ?? []) as List;
        batches.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addBatch(int productId, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addBatch(productId, data), () async {
        await loadBatches(productId);
        await loadProducts(); // current_stock تحدّث
      });

  Future<bool> recallBatch(int productId, int batchId, String reason) async =>
      _doAndReload(() => repo.recallBatch(batchId, reason), () async {
        await loadBatches(productId);
        await loadProducts();
      });

  // ============ Customers ============

  Future<void> loadCustomers({String? search}) async {
    try {
      isLoading.value = true;
      final r = await repo.listCustomers(search: search);
      if (_ok(r)) {
        final list = (r.body['meta']?['customers'] ?? []) as List;
        customers.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<Map<String, dynamic>?> findCustomerByPhone(String phone) async {
    try {
      final r = await repo.findCustomerByPhone(phone);
      if (_ok(r)) {
        final c = r.body['meta']?['customer'];
        return c == null ? null : Map<String, dynamic>.from(c as Map);
      }
    } catch (_) {}
    return null;
  }

  Future<bool> addCustomer(Map<String, dynamic> data) async =>
      _doAndReload(() => repo.addCustomer(data), () => loadCustomers());

  Future<bool> updateCustomer(int id, Map<String, dynamic> data) async =>
      _doAndReload(() => repo.updateCustomer(id, data), () => loadCustomers());

  // ============ Cart Management ============

  void addToCart(Map<String, dynamic> product, double qty) {
    final idx = cart.indexWhere((c) => c['product_id'] == product['id']);
    if (idx >= 0) {
      cart[idx] = {...cart[idx], 'quantity': qty};
    } else {
      cart.add({
        'product_id': product['id'],
        'product': product,
        'quantity': qty,
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

  double get cartTotal {
    double total = 0;
    for (final item in cart) {
      final price = double.tryParse('${item['product']?['sale_price']}') ?? 0;
      final qty = double.tryParse('${item['quantity']}') ?? 0;
      total += price * qty;
    }
    return total;
  }

  /// يفحص ما إن كان أيّ منتج في السلّة يحتاج وصفة
  bool get cartRequiresPrescription => cart.any((c) =>
      c['product']?['requires_prescription'] == true);

  /// يحسب تعارضات الحساسية للعميل المحدّد مع كل المنتجات في السلّة
  List<Map<String, dynamic>> get cartAllergyConflicts {
    final customer = selectedCustomer.value;
    if (customer == null) return [];
    final allergies = (customer['allergies'] as List?)?.cast<String>() ?? [];
    if (allergies.isEmpty) return [];

    final conflicts = <Map<String, dynamic>>[];
    for (final item in cart) {
      final p = item['product'] as Map?;
      if (p == null) continue;
      final names = '${p['trade_name'] ?? ''} ${p['generic_name'] ?? ''}'.toLowerCase();
      final matched = <String>[];
      for (final allergen in allergies) {
        if (allergen.trim().isEmpty) continue;
        if (names.contains(allergen.toLowerCase())) matched.add(allergen);
      }
      if (matched.isNotEmpty) {
        conflicts.add({'product_name': p['trade_name'], 'allergies': matched});
      }
    }
    return conflicts;
  }

  // ============ Sales ============

  /// تسجيل البيع من السلّة الحالية.
  Future<bool> recordCurrentSale({
    required String paymentMethod,
    String? paidTransactionId,
    String? prescriptionNumber,
    String? prescribingDoctor,
    String? discountAmount,
    List<String>? acknowledgedWarnings,
    String? notes,
  }) async {
    if (cart.isEmpty) {
      lastError.value = 'السلّة فارغة';
      return false;
    }

    final items = cart.map((c) => {
      'product_id': c['product_id'],
      'quantity': c['quantity'],
    }).toList();

    final data = <String, dynamic>{
      'items': items,
      'payment_method': paymentMethod,
      if (selectedCustomer.value != null) 'customer_id': selectedCustomer.value!['id'],
      if (paidTransactionId != null && paidTransactionId.isNotEmpty)
        'paid_transaction_id': paidTransactionId,
      if (prescriptionNumber != null && prescriptionNumber.isNotEmpty)
        'prescription_number': prescriptionNumber,
      if (prescribingDoctor != null && prescribingDoctor.isNotEmpty)
        'prescribing_doctor': prescribingDoctor,
      if (discountAmount != null && discountAmount.isNotEmpty)
        'discount_amount': discountAmount,
      if (acknowledgedWarnings != null && acknowledgedWarnings.isNotEmpty)
        'warnings_acknowledged': acknowledgedWarnings,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    };

    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.recordSale(data);
      // CRITICAL-001-USAGE
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map && r.body['success'] == true) {
        lastSale.value = Map<String, dynamic>.from((r.body['meta']?['sale'] ?? {}) as Map);
        clearCart();
        await loadDashboard();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل تسجيل البيع';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isSubmitting.value = false; }
  }

  Future<void> loadSales() async {
    try {
      isLoading.value = true;
      final r = await repo.listSales();
      if (_ok(r)) {
        final list = (r.body['meta']?['sales'] ?? []) as List;
        sales.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  // ============ Alerts ============

  Future<void> loadAlerts() async {
    try {
      isLoading.value = true;
      final r = await repo.listAlerts();
      if (_ok(r)) {
        final list = (r.body['meta']?['alerts'] ?? []) as List;
        alerts.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
        alertsSummary.value = r.body['meta']?['summary'] == null
            ? null : Map<String, dynamic>.from(r.body['meta']!['summary'] as Map);
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> scanExpiring() async =>
      _doAndReload(() => repo.scanExpiring(), loadAlerts);

  Future<bool> dismissAlert(int id) async =>
      _doAndReload(() => repo.dismissAlert(id), loadAlerts);

  // ============ Helpers ============

  Future<bool> _doAndReload(Future<Response> Function() action, Future<void> Function() reload) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
      // CRITICAL-001-USAGE (يصيب addProduct + إضافات أخرى)
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

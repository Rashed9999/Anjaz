import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/cashier_repo.dart';
import 'package:amyal_pay/features/plans/screens/my_usage_screen.dart';

/// عنصر في سلة الكاشير (حالة محلية فقط — لا جدول خادم).
class CartLine {
  final String name;
  final double price;
  int qty;
  CartLine({required this.name, required this.price, this.qty = 1});

  double get lineTotal => price * qty;
  Map<String, dynamic> toJson() => {'name': name, 'qty': qty, 'price': price.toString()};
}

/// AMIAL-CASHIER-001 — متحكّم الكاشير.
class CashierController extends GetxController implements GetxService {
  final CashierRepo repo;
  CashierController({required this.repo});

  // المنتجات
  final RxList<Map<String, dynamic>> products = <Map<String, dynamic>>[].obs;
  final RxBool isLoadingProducts = false.obs;

  // السلة (محلية)
  final RxList<CartLine> cart = <CartLine>[].obs;
  double get cartTotal => cart.fold(0.0, (s, l) => s + l.lineTotal);

  // الحالة
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // التقرير
  final Rx<Map<String, dynamic>?> report = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoadingReport = false.obs;

  // ---- المنتجات ----
  Future<void> loadProducts({String? search}) async {
    try {
      isLoadingProducts.value = true;
      final r = await repo.products(search: search);
      if (_ok(r)) {
        final list = (r.body['meta']?['products'] ?? []) as List;
        products.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally {
      isLoadingProducts.value = false;
    }
  }

  Future<bool> addProduct(Map<String, dynamic> data) async {
    try {
      final r = await repo.addProduct(data);
      if (_ok(r)) {
        await loadProducts();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل إضافة المنتج';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    }
  }

  // ---- السلة ----
  void addToCart(String name, double price) {
    final existing = cart.firstWhereOrNull((l) => l.name == name && l.price == price);
    if (existing != null) {
      existing.qty++;
      cart.refresh();
    } else {
      cart.add(CartLine(name: name, price: price));
    }
  }

  void removeLine(int i) {
    if (i >= 0 && i < cart.length) cart.removeAt(i);
  }

  void clearCart() => cart.clear();

  // ---- تسجيل البيع ----
  /// method: cash | credit | amial_pay
  Future<Map<String, dynamic>?> recordSale({
    required double total,
    required String method,
    Map<String, String>? customer,
    String? paidTransactionId,
    String? creditDueDate, // AMIAL-CUSTOMER-CREDIT-001 — تاريخ استحقاق بيع الأجل
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final data = <String, dynamic>{
        'total': total.toString(),
        'payment_method': method,
        if (cart.isNotEmpty) 'items': cart.map((l) => l.toJson()).toList(),
        if (customer != null) 'customer': customer,
        if (paidTransactionId != null) 'paid_transaction_id': paidTransactionId,
        if (creditDueDate != null && creditDueDate.isNotEmpty) 'credit_due_date': creditDueDate,
      };
      final r = await repo.recordSale(data);
      // CRITICAL-001-USAGE — التقاط 402 وعرض الحوار
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return null;
      if (_ok(r)) {
        clearCart();
        return Map<String, dynamic>.from((r.body['meta']?['sale'] ?? {}) as Map);
      }
      lastError.value = _msg(r) ?? 'فشل تسجيل البيع';
      return null;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ---- التقرير ----
  Future<void> loadReport({String? date}) async {
    try {
      isLoadingReport.value = true;
      final r = await repo.report(date: date);
      if (_ok(r)) report.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    } catch (_) {} finally {
      isLoadingReport.value = false;
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

import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/cashier_repo.dart';
import 'package:amyal_pay/features/plans/screens/my_usage_screen.dart';

/// عنصر في سلة الكاشير (حالة محلية فقط — لا جدول خادم).
class CartLine {
  final String name;
  final double price;
  int qty;
  // AMIAL-CASHIER-BARCODE-001 — معرّف المنتج (إن وُجد) ليُخصم المخزون عند البيع.
  final int? productId;
  CartLine({required this.name, required this.price, this.qty = 1, this.productId});

  double get lineTotal => price * qty;
  Map<String, dynamic> toJson() => {
        'name': name,
        'qty': qty,
        'price': price.toString(),
        if (productId != null) 'product_id': productId,
      };
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

  // AMIAL-CASHIER-REFUND-001 — مبيعات اليوم بمعرّفاتها (مدخل الاسترجاع)
  final RxList<Map<String, dynamic>> sales = <Map<String, dynamic>>[].obs;
  final RxBool isLoadingSales = false.obs;

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
    return (await addProductReturning(data)) != null;
  }

  /// كـ [addProduct] لكن يعيد المنتج المُنشأ (بـ id) — يلزم لإضافته للسلّة بعد المسح.
  Future<Map<String, dynamic>?> addProductReturning(Map<String, dynamic> data) async {
    try {
      final r = await repo.addProduct(data);
      if (_ok(r)) {
        final product = Map<String, dynamic>.from((r.body['meta']?['product'] ?? {}) as Map);
        await loadProducts();
        return product;
      }
      lastError.value = _msg(r) ?? 'فشل إضافة المنتج';
      return null;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return null;
    }
  }

  /// AMIAL-PROFIT-001: تقرير الربحية.
  final Rx<Map<String, dynamic>?> profitReport = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoadingProfit = false.obs;

  Future<void> loadProfitReport({int days = 7}) async {
    try {
      isLoadingProfit.value = true;
      final r = await repo.profitReport(days: days);
      if (_ok(r)) {
        profitReport.value =
            Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      } else {
        lastError.value = _msg(r) ?? 'تعذّر تحميل التقرير';
      }
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoadingProfit.value = false;
    }
  }

  /// AMIAL-INVENTORY-001: تعديل منتج (سعر/كمية/تفعيل...).
  Future<bool> updateProduct(int id, Map<String, dynamic> data) async {
    try {
      final r = await repo.updateProduct(id, data);
      if (_ok(r)) {
        await loadProducts();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل تعديل المنتج';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    }
  }

  // ---- السلة ----
  void addToCart(String name, double price, {int? productId}) {
    final existing = cart.firstWhereOrNull(
        (l) => l.productId != null ? l.productId == productId : (l.name == name && l.price == price));
    if (existing != null) {
      existing.qty++;
      cart.refresh();
    } else {
      cart.add(CartLine(name: name, price: price, productId: productId));
    }
  }

  /// AMIAL-CASHIER-BARCODE-001 — يضيف منتجاً (من المسح أو القائمة) للسلّة بـ product_id.
  void addProductToCart(Map<String, dynamic> product) {
    final price = double.tryParse(
            (product['offer_price'] ?? product['price'] ?? '0').toString()) ??
        0;
    addToCart(
      (product['name'] ?? '').toString(),
      price,
      productId: product['id'] is int ? product['id'] as int : int.tryParse('${product['id']}'),
    );
  }

  /// يبحث عن منتج بالباركود ويضيفه للسلّة.
  /// يعيد: 'added' (نجح) | 'not_found' (باركود مجهول → اعرض إنشاء) | 'error'.
  Future<String> lookupAndAddByBarcode(String barcode) async {
    try {
      final r = await repo.lookupBarcode(barcode);
      if (_ok(r)) {
        final p = Map<String, dynamic>.from((r.body['meta']?['product'] ?? {}) as Map);
        addProductToCart(p);
        return 'added';
      }
      if (r.statusCode == 404) return 'not_found';
      lastError.value = _msg(r) ?? 'فشل البحث';
      return 'error';
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return 'error';
    }
  }

  void removeLine(int i) {
    if (i >= 0 && i < cart.length) cart.removeAt(i);
  }

  /// AMIAL-POS-001: زيادة/إنقاص كمية سطر في السلة (الإنقاص لصفر يحذفه).
  void incLine(int i) {
    if (i < 0 || i >= cart.length) return;
    cart[i].qty++;
    cart.refresh();
  }

  void decLine(int i) {
    if (i < 0 || i >= cart.length) return;
    if (cart[i].qty <= 1) {
      cart.removeAt(i);
    } else {
      cart[i].qty--;
      cart.refresh();
    }
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
        'customer': ?customer,
        'paid_transaction_id': ?paidTransactionId,
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

  /// AMIAL-CASHIER-REFUND-001 — تحميل قائمة مبيعات اليوم.
  Future<void> loadSales({String? date}) async {
    try {
      isLoadingSales.value = true;
      final r = await repo.sales(date: date);
      if (_ok(r)) {
        final list = (r.body['meta']?['sales'] ?? []) as List;
        sales.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally {
      isLoadingSales.value = false;
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

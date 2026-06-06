import 'package:get/get.dart';
import 'package:amyal_pay/features/admin/domain/repositories/subscriptions_repo.dart';

/// CRITICAL-001-SUBS — متحكّم الاشتراكات الإداري.
class SubscriptionsController extends GetxController implements GetxService {
  final SubscriptionsRepo repo;
  SubscriptionsController({required this.repo});

  // State
  final Rx<Map<String, dynamic>?> summary = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> expiring = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> logItems = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> logPagination = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> merchantHistory = Rx<Map<String, dynamic>?>(null);

  // Filters
  final RxInt expiringDays = 7.obs;
  final RxString logActionFilter = ''.obs;
  final RxInt logPage = 1.obs;

  final RxBool isLoading = false.obs;
  final RxString lastError = ''.obs;

  // ============ Loaders ============

  Future<void> loadSummary() async {
    try {
      isLoading.value = true;
      lastError.value = '';
      final r = await repo.summary();
      if (_ok(r)) summary.value = Map<String, dynamic>.from(r.body['meta'] as Map);
      else lastError.value = _msg(r) ?? 'فشل تحميل الملخّص';
    } catch (_) { lastError.value = 'خطأ في الشبكة'; }
    finally { isLoading.value = false; }
  }

  Future<void> loadExpiring({int? days}) async {
    if (days != null) expiringDays.value = days;
    try {
      isLoading.value = true;
      final r = await repo.expiring(days: expiringDays.value);
      if (_ok(r)) {
        expiring.assignAll(((r.body['meta']?['expiring'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) { lastError.value = 'خطأ في تحميل المنتهية'; }
    finally { isLoading.value = false; }
  }

  Future<void> loadLog({String? action, bool reset = false}) async {
    if (action != null) logActionFilter.value = action;
    if (reset) { logPage.value = 1; logItems.clear(); }
    try {
      isLoading.value = true;
      final r = await repo.log(
        action: logActionFilter.value.isEmpty ? null : logActionFilter.value,
        page: logPage.value, perPage: 20,
      );
      if (_ok(r)) {
        final items = ((r.body['meta']?['items'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
        if (reset || logPage.value == 1) logItems.assignAll(items);
        else logItems.addAll(items);
        logPagination.value = Map<String, dynamic>.from(
            (r.body['meta']?['pagination'] ?? {}) as Map);
      }
    } catch (_) { lastError.value = 'خطأ في تحميل السجل'; }
    finally { isLoading.value = false; }
  }

  Future<void> loadMerchantHistory(int merchantId) async {
    try {
      isLoading.value = true;
      final r = await repo.merchantHistory(merchantId);
      if (_ok(r)) merchantHistory.value =
          Map<String, dynamic>.from(r.body['meta'] as Map);
    } catch (_) { lastError.value = 'خطأ في تحميل التاريخ'; }
    finally { isLoading.value = false; }
  }

  // ============ Actions ============

  Future<bool> renew(int merchantId, {
    double? pricePaidSar, String? paymentMethod,
    String? paymentReference, String? notes,
  }) async {
    return _action(() => repo.renew(merchantId, {
      if (pricePaidSar != null) 'price_paid_sar': pricePaidSar,
      if (paymentMethod != null) 'payment_method': paymentMethod,
      if (paymentReference != null) 'payment_reference': paymentReference,
      if (notes != null) 'notes': notes,
    }));
  }

  Future<bool> extend(int merchantId, int days, {
    double? pricePaidSar, String? paymentMethod,
    String? paymentReference, String? notes,
  }) async {
    return _action(() => repo.extend(merchantId, {
      'days': days,
      if (pricePaidSar != null) 'price_paid_sar': pricePaidSar,
      if (paymentMethod != null) 'payment_method': paymentMethod,
      if (paymentReference != null) 'payment_reference': paymentReference,
      if (notes != null) 'notes': notes,
    }));
  }

  Future<int> processExpired() async {
    try {
      isLoading.value = true;
      final r = await repo.processExpired();
      if (_ok(r)) return (r.body['meta']?['processed'] ?? 0) as int;
      lastError.value = _msg(r) ?? 'فشل';
      return 0;
    } catch (_) { lastError.value = 'خطأ'; return 0; }
    finally { isLoading.value = false; }
  }

  // ============ Helpers ============

  Future<bool> _action(Future<Response> Function() fn) async {
    try {
      isLoading.value = true;
      lastError.value = '';
      final r = await fn();
      if (_ok(r)) {
        await loadSummary();    // حدّث الـ KPIs
        await loadExpiring();   // حدّث القائمة
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل';
      return false;
    } catch (_) { lastError.value = 'خطأ في الشبكة'; return false; }
    finally { isLoading.value = false; }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;

  String? _msg(Response r) =>
      r.body is Map ? r.body['message']?.toString() : null;
}

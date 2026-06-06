import 'package:get/get.dart';
import 'package:amyal_pay/features/admin/domain/repositories/admin_repo.dart';

/// CRITICAL-001-ADMIN — متحكّم لوحة الإدارة.
class AdminController extends GetxController implements GetxService {
  final AdminRepo repo;
  AdminController({required this.repo});

  // State
  final Rx<Map<String, dynamic>?> dashboardData = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> merchants = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> variances = <Map<String, dynamic>>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // Filters
  final RxnString filterPlan = RxnString();
  final RxnString filterBusinessType = RxnString();
  final RxnString filterVerification = RxnString();
  final RxString searchQuery = ''.obs;

  // ============ Dashboard ============

  Future<void> loadDashboard() async {
    try {
      isLoading.value = true;
      final r = await repo.dashboard();
      if (_ok(r)) dashboardData.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    } catch (_) {} finally { isLoading.value = false; }
  }

  // ============ Merchants ============

  Future<void> loadMerchants() async {
    try {
      isLoading.value = true;
      final r = await repo.listMerchants(
        search: searchQuery.value,
        plan: filterPlan.value,
        businessType: filterBusinessType.value,
        verificationStatus: filterVerification.value,
      );
      if (_ok(r)) {
        final list = (r.body['meta']?['merchants'] ?? []) as List;
        merchants.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> updateMerchantPlan(int merchantId, String plan, {String? notes}) async =>
      _doAndReload(() => repo.updateMerchantPlan(merchantId, plan, notes: notes), loadMerchants);

  Future<bool> verifyMerchant(int merchantId, String action, {String? note}) async =>
      _doAndReload(() => repo.verifyMerchant(merchantId, action, note), loadMerchants);

  // ============ Variances ============

  Future<void> loadPendingVariances() async {
    try {
      isLoading.value = true;
      final r = await repo.pendingVariances();
      if (_ok(r)) {
        final list = (r.body['meta']?['variances'] ?? []) as List;
        variances.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> resolveVariance(int id, String resolution, {String? note}) async =>
      _doAndReload(() => repo.resolveVariance(id, resolution, note), loadPendingVariances);

  // ============ Helpers ============

  Future<bool> _doAndReload(Future<Response> Function() action, Future<void> Function() reload) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
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

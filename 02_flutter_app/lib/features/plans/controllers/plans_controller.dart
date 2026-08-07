import 'package:get/get.dart';
import 'package:amial_pay/features/plans/domain/repositories/plans_repo.dart';

/// CRITICAL-001-PLANS — متحكّم الخطط.
class PlansController extends GetxController implements GetxService {
  final PlansRepo repo;
  PlansController({required this.repo});

  // State
  final RxList<Map<String, dynamic>> plans = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> currentPlan = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> usage = Rx<Map<String, dynamic>?>(null);

  final RxBool isLoading = false.obs;

  // ============ Plans Catalog ============

  Future<void> loadCatalog() async {
    try {
      isLoading.value = true;
      final r = await repo.catalog();
      if (_ok(r)) {
        final meta = (r.body['meta'] ?? {}) as Map;
        plans.assignAll(((meta['plans'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
        currentPlan.value = meta['current_plan'] != null
            ? Map<String, dynamic>.from(meta['current_plan'] as Map)
            : null;
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  // ============ Usage Snapshot ============

  Future<void> loadUsage() async {
    try {
      isLoading.value = true;
      final r = await repo.myUsage();
      if (_ok(r)) {
        usage.value = Map<String, dynamic>.from(
          (r.body['meta']?['usage'] ?? {}) as Map,
        );
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  // ============ Helpers (للواجهة) ============

  bool isCurrentPlan(String code) => currentPlan.value?['code'] == code;

  /// هل الاشتراك منتهي؟ (يظهر في الـ UI تحذير)
  bool get isExpired {
    final expAt = currentPlan.value?['expires_at'];
    if (expAt == null) return false;
    try {
      return DateTime.parse(expAt).isBefore(DateTime.now());
    } catch (_) { return false; }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;
}

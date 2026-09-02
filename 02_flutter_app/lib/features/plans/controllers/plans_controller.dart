import 'package:get/get.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
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
      final access = Get.isRegistered<AccessController>()
          ? Get.find<AccessController>()
          : null;
      final r = await repo.catalog(businessType: access?.businessType.value);
      if (_ok(r)) {
        final data = (r.body['data'] ?? {}) as Map;
        plans.assignAll(((data['plans'] ?? []) as List)
            .map((e) => _planForUi(Map<String, dynamic>.from(e as Map)))
            .toList());
        currentPlan.value = access == null ? null : {
          'code': access.subscriptionPlan.value,
          'expires_at': access.subscriptionExpiresAt.value,
        };
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

  /// يترجم عقد `/plans/capabilities` إلى نموذج شاشة المقارنة القديم، من
  /// دون إعادة كتابة ميزات الباقات يدوياً في Flutter.
  Map<String, dynamic> _planForUi(Map<String, dynamic> raw) {
    final capabilities = (raw['capabilities'] as List? ?? const [])
        .whereType<Map>()
        .where((c) => '${c['status'] ?? 'available'}' == 'available')
        .map((c) => '${c['code'] ?? ''}')
        .where((code) => code.isNotEmpty)
        .toList();
    final limits = Map<String, dynamic>.from(raw['limits'] as Map? ?? const {});

    return {
      'code': raw['code'],
      'label': raw['name'],
      'price_monthly_sar': raw['price_monthly'],
      'price_annual_sar': raw['price_annual'],
      'currency': raw['currency'],
      'is_free': raw['code'] == 'free',
      'features': capabilities,
      'limits': {
        'max_products': limits['products'],
        'monthly_operations': limits['monthly_operations'],
        'max_employees': limits['employees'],
        'max_branches': limits['branches'],
        'max_pos_devices': limits['pos_devices'],
        'archive_days': limits['archive_days'],
      },
    };
  }
}

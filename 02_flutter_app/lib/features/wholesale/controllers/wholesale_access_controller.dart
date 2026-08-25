import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-WHOLESALE-ACCESS-001 — نفس قرار الخادم في Flutter.
///
/// لا يستنتج الباقة من اسمها ولا يعيد بناء RBAC محلياً. الخادم يعيد snapshot
/// لكل فعل: available / locked_by_plan / locked_by_role / limit_reached.
class WholesaleAccessController extends GetxController implements GetxService {
  WholesaleAccessController({required this.apiClient});

  final ApiClient apiClient;

  final RxMap<String, Map<String, dynamic>> actions =
      <String, Map<String, dynamic>>{}.obs;
  final RxBool isLoading = false.obs;
  final RxBool isLoaded = false.obs;
  final RxBool isOwner = false.obs;
  final RxString plan = 'free'.obs;
  final RxString lastError = ''.obs;

  static const available = 'available';
  static const lockedByPlan = 'locked_by_plan';
  static const lockedByRole = 'locked_by_role';
  static const limitReached = 'limit_reached';

  static WholesaleAccessController ensureRegistered() {
    if (Get.isRegistered<WholesaleAccessController>()) {
      return Get.find<WholesaleAccessController>();
    }
    return Get.put(
      WholesaleAccessController(apiClient: Get.find<ApiClient>()),
      permanent: true,
    );
  }

  Future<bool> load({bool force = false}) async {
    if (isLoading.value) return false;
    if (isLoaded.value && !force) return true;

    try {
      isLoading.value = true;
      lastError.value = '';
      final r = await apiClient
          .getData('/api/v1/amial/merchant/wholesale/access');
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = r.body['meta'] is Map ? r.body['meta'] as Map : const {};
        final raw = meta['wholesale_access'] is Map
            ? meta['wholesale_access'] as Map
            : const {};
        final rawActions = raw['actions'] is Map ? raw['actions'] as Map : const {};

        actions.clear();
        for (final entry in rawActions.entries) {
          if (entry.value is Map) {
            actions['${entry.key}'] =
                Map<String, dynamic>.from(entry.value as Map);
          }
        }
        isOwner.value = raw['is_owner'] == true;
        plan.value = '${raw['subscription_plan'] ?? 'free'}';
        isLoaded.value = true;
        return true;
      }

      lastError.value = r.body is Map
          ? '${r.body['message'] ?? 'تعذر تحميل صلاحيات الجملة'}'
          : 'تعذر تحميل صلاحيات الجملة';
      return false;
    } catch (_) {
      lastError.value = 'تعذر الاتصال بالخادم للتحقق من صلاحيات الجملة';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Map<String, dynamic>? row(String action) => actions[action];
  String state(String action) => '${row(action)?['state'] ?? 'unknown'}';
  bool allows(String action) => state(action) == available;
  bool isPlanLocked(String action) => state(action) == lockedByPlan;
  bool isRoleLocked(String action) => state(action) == lockedByRole;
  bool isLimitReached(String action) => state(action) == limitReached;

  /// الموظف لا يرى أصلاً ما لا يملك دوره. المالك يرى المقفول بالباقة ليعرف
  /// ما الذي تفتحه الترقية، ولا يرى فعلًا غير منطبق أو مجهولًا.
  bool shouldShow(String action) {
    final s = state(action);
    if (s == available) return true;
    if (isOwner.value && (s == lockedByPlan || s == limitReached)) return true;
    return false;
  }

  String badge(String action) {
    final r = row(action);
    final s = state(action);
    if (s == available) return '';
    if (s == lockedByRole) return 'تحتاج صلاحية';
    if (s == limitReached) return 'بلغت الحد';
    if (s == lockedByPlan) {
      final unlock = r?['unlock'] is Map ? r!['unlock'] as Map : const {};
      return 'باقة ${unlock['plan_name'] ?? unlock['plan'] ?? 'أعلى'}';
    }
    return 'غير متاح';
  }

  String message(String action) =>
      '${row(action)?['reason'] ?? 'تعذر التحقق من صلاحية العملية'}';

  String? suggestedPlan(String action) {
    final r = row(action);
    final unlock = r?['unlock'] is Map ? r!['unlock'] as Map : const {};
    final value = '${unlock['plan_code'] ?? unlock['plan'] ?? ''}'.trim();
    return value.isEmpty ? null : value;
  }
}

import 'package:get/get.dart';
import 'package:amial_pay/features/access/domain/repositories/access_repo.dart';

/// CRITICAL-001 — Access Controller.
///
/// نقطة الحقيقة الوحيدة (Single Source of Truth) لما يراه المستخدم.
/// يُحمَّل عند تسجيل الدخول + يُعاد تحميله عند تغيير business_type/plan.
///
/// الاستخدام:
///   final access = `Get.find<AccessController>()`;
///   if (access.has('inventory')) ... ;
///   if (access.role == 'merchant' && access.businessType == 'retail') ... ;
class AccessController extends GetxController implements GetxService {
  final AccessRepo repo;
  AccessController({required this.repo});

  // الحقول الأساسية
  final RxString role = 'user'.obs;
  final RxString verificationLevel = 'basic'.obs;
  final RxnString businessType = RxnString();          // قد يكون null إن لم يختر
  final RxnString businessTypeLabel = RxnString();
  final RxString subscriptionPlan = 'free'.obs;
  final RxnString subscriptionPlanLabel = RxnString();
  final RxInt subscriptionPriceSar = 0.obs;
  final RxnString subscriptionExpiresAt = RxnString();

  // قائمة الميزات النشطة
  final RxSet<String> features = <String>{}.obs;

  // الحدود (max_products, max_employees, ...)
  final RxMap<String, dynamic> limits = <String, dynamic>{}.obs;

  // معلومات المستخدم الأساسية
  final RxnInt userId = RxnInt();
  final RxnString userName = RxnString();
  final RxnString userPhone = RxnString();

  // حالات
  final RxBool isLoading = false.obs;
  final RxBool isLoaded = false.obs;
  final RxString lastError = ''.obs;

  // ============ تحميل ============

  Future<bool> load() async {
    try {
      isLoading.value = true;
      lastError.value = '';
      final r = await repo.getAccess();
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        _hydrate(r.body['meta'] as Map);
        isLoaded.value = true;
        return true;
      }
      lastError.value = r.body is Map ? (r.body['message']?.toString() ?? 'فشل التحميل') : 'فشل';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isLoading.value = false; }
  }

  void _hydrate(Map meta) {
    final access = (meta['access'] ?? {}) as Map;
    role.value = access['role']?.toString() ?? 'user';
    verificationLevel.value = access['verification_level']?.toString() ?? 'basic';
    businessType.value = access['business_type']?.toString();
    businessTypeLabel.value = access['business_type_label']?.toString();
    subscriptionPlan.value = access['subscription_plan']?.toString() ?? 'free';
    subscriptionPlanLabel.value = access['subscription_plan_label']?.toString();
    subscriptionPriceSar.value = (access['subscription_price_sar'] as num?)?.toInt() ?? 0;
    subscriptionExpiresAt.value = access['subscription_expires_at']?.toString();

    final fList = (access['features'] as List?)?.cast<String>() ?? [];
    features
      ..clear()
      ..addAll(fList);

    final lmts = (access['limits'] as Map?) ?? {};
    limits.assignAll(Map<String, dynamic>.from(lmts));

    final user = (meta['user'] ?? {}) as Map;
    userId.value = (user['id'] as num?)?.toInt();
    userName.value = user['name']?.toString();
    userPhone.value = user['phone']?.toString();
  }

  // ============ Helpers (الواجهة الأهم) ============

  /// هل المستخدم لديه ميزة معيّنة؟
  bool has(String feature) => features.contains(feature);

  /// هل لديه أيّ من ميزات في القائمة؟
  bool hasAny(List<String> any) => any.any(features.contains);

  /// هل لديه كل الميزات؟
  bool hasAll(List<String> all) => all.every(features.contains);

  // فحوصات سريعة على الدور
  bool get isUser => role.value == 'user';
  bool get isAgent => role.value == 'agent';
  bool get isMerchant => role.value == 'merchant';
  bool get isAdmin => role.value == 'admin';
  bool get isDistributor => role.value == 'distributor';

  // فحوصات على business_type
  bool get isQuickSale => businessType.value == 'quick_sale';
  bool get isRetail => businessType.value == 'retail';
  bool get isFuel => businessType.value == 'fuel';
  bool get isPharmacy => businessType.value == 'pharmacy';
  bool get isWholesale => businessType.value == 'wholesale';
  bool get isRestaurant => businessType.value == 'restaurant';

  /// هل التاجر اختار نوع نشاطه بعد؟
  bool get needsBusinessTypeSelection => isMerchant && businessType.value == null;

  // فحوصات على الخطّة
  bool get isFreePlan => subscriptionPlan.value == 'free';
  bool get isStarterPlan => subscriptionPlan.value == 'starter';
  bool get isBusinessPlan => subscriptionPlan.value == 'business';
  bool get isProPlan => subscriptionPlan.value == 'merchant_pro';
  bool get isEnterprisePlan => subscriptionPlan.value == 'enterprise';

  // ============ تحديث ============

  /// التاجر يختار/يُغيّر نوع نشاطه.
  Future<bool> updateMyBusinessType(String newType) async {
    try {
      isLoading.value = true;
      final r = await repo.updateBusinessType(newType);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        // أعد تحميل access
        await load();
        return true;
      }
      lastError.value = r.body is Map ? (r.body['message']?.toString() ?? 'فشل') : 'فشل';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isLoading.value = false; }
  }

  /// إعادة تعيين عند تسجيل الخروج
  void reset() {
    role.value = 'user';
    verificationLevel.value = 'basic';
    businessType.value = null;
    businessTypeLabel.value = null;
    subscriptionPlan.value = 'free';
    subscriptionPlanLabel.value = null;
    subscriptionPriceSar.value = 0;
    subscriptionExpiresAt.value = null;
    features.clear();
    limits.clear();
    userId.value = null;
    userName.value = null;
    userPhone.value = null;
    isLoaded.value = false;
    lastError.value = '';
  }
}

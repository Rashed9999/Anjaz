import 'package:get/get.dart';
import 'package:amial_pay/features/access/domain/repositories/access_repo.dart';
import 'package:amial_pay/features/access/domain/models/merchant_context.dart';

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
  // AMIAL-MERCHANT-VERIFY-RECEIVE-001 — حالةُ توثيق التاجر من الخادم:
  // verified | pending_review | rejected | ... — null لغير التاجر. تقودُ
  // لافتةَ «القبضُ قيد المراجعة» في غلاف التاجر (دخولٌ محدود فوراً).
  final RxnString merchantVerificationStatus = RxnString();
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
  final RxnString merchantDisplayName = RxnString();
  final Rxn<MerchantContext> merchantContext = Rxn<MerchantContext>();

  /// ══════════════════════════════════════════════════════════════════
  /// AMIAL-ACTOR-001 — **الفاعلُ يُقرأ من الخادم، ولا يُخمَّن من الدور.**
  ///
  /// الخادمُ يصرّح به في `access.actor` بأربع قيم: `owner` · `pos` ·
  /// `staff` · `customer` (‏`FeatureAccessService`). **وكان التطبيقُ
  /// يستنتجه** من طرفين آخرين: `role.value == 'pos'` ومن
  /// `merchant_context.actor`.
  ///
  /// **وكلاهما أضعفُ من المصدر:**
  ///   · `'pos'` **ليس في `AccessConstants::ALL_ROLES` أصلاً** — القائمةُ
  ///     الرسميّةُ خمسةٌ: مستخدمٌ · وكيلٌ · موزّعٌ · تاجرٌ · مدير. فالمقارنةُ
  ///     تجري على قيمةٍ لا تُعلنها المنصّة.
  ///   · و`merchant_context` حمولةٌ أخرى قد تغيب، فيسقط التمييزُ كلُّه
  ///     إلى الدور — وهو الطرفُ الضعيف.
  ///
  /// **وغيابُه لا يُقرأ «عميلاً»** — بل يُقال إنّه لم يُصرَّح به،
  /// ويُرجَع إلى الاستنتاج القديم. فخادمٌ أقدمُ لا يُرسله كان سيجعل
  /// **صاحبَ المنشأة عميلاً** فيُغلق دونه مالُه وإدارتُه — وهو بعينه
  /// ما اشتُكي منه: «مالكُ المحطّة لا يستطيع الدخول إلى حسابه».
  /// (القاعدة السابعة: «غير معروف» ليس صفراً.)
  /// ══════════════════════════════════════════════════════════════════
  final RxString actor = 'customer'.obs;

  /// هل صرّح الخادمُ بالفاعل في هذه الجلسة؟ (لا تُخلَط بقيمته.)
  final RxBool actorDeclared = false.obs;

  // ══════════════════════════════════════════════════════════════════
  // AMIAL-POS-IDENTITY-001 — **هويّةُ موظّف نقطة البيع، استُعيدت.**
  //
  // حُذفت هذه الثلاثةُ وبقي مُنادُوها في `pos_employee_home_screen.dart`،
  // **فكان التطبيقُ لا يُصرَّف**: أربعةُ أخطاءِ `undefined_getter`. أي
  // أنّ أيَّ بناءٍ في Codemagic كان يسقط قبل أن يبدأ.
  //
  // ولا يحملها `MerchantContext` — فتُقرأ من `access['pos']` كما كانت.
  // ══════════════════════════════════════════════════════════════════
  final RxnString posNumber = RxnString();
  final RxnString posDisplayName = RxnString();
  final RxSet<String> posPermissions = <String>{}.obs;

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

    // AMIAL-ACTOR-001 — من المصدر مباشرةً، ويُوسَم غيابُه ولا يُقرأ صفراً.
    final declaredActor = access['actor']?.toString();
    actorDeclared.value = declaredActor != null && declaredActor.isNotEmpty;
    actor.value = actorDeclared.value ? declaredActor! : 'customer';

    verificationLevel.value = access['verification_level']?.toString() ?? 'basic';
    merchantVerificationStatus.value =
        access['merchant_verification_status']?.toString();
    businessType.value = access['business_type']?.toString();
    businessTypeLabel.value = access['business_type_label']?.toString();
    subscriptionPlan.value = access['subscription_plan']?.toString() ?? 'free';
    subscriptionPlanLabel.value = access['subscription_plan_label']?.toString();
    subscriptionPriceSar.value = (access['subscription_price_sar'] as num?)?.toInt() ?? 0;
    subscriptionExpiresAt.value = access['subscription_expires_at']?.toString();
    merchantDisplayName.value = access['merchant_display_name']?.toString();
    final rawContext = meta['merchant_context'];
    merchantContext.value = rawContext is Map ? MerchantContext.fromJson(rawContext) : null;
    if (merchantContext.value != null) {
      merchantDisplayName.value = merchantContext.value!.businessName;
      businessType.value = merchantContext.value!.businessType ?? businessType.value;
      businessTypeLabel.value = merchantContext.value!.businessTypeLabel ?? businessTypeLabel.value;
    }

    final pos = access['pos'];
    if (pos is Map) {
      posNumber.value = pos['pos_number']?.toString();
      posDisplayName.value = pos['display_name']?.toString();
      posPermissions
        ..clear()
        ..addAll(((pos['permissions'] as List?) ?? const []).map((e) => e.toString()));
    } else {
      posNumber.value = null;
      posDisplayName.value = null;
      posPermissions.clear();
    }

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
  bool get isPos => isPosStaff;

  /// **موظّفُ نقطة البيع** — من المصدر حين يُصرَّح به، ومن الاستنتاج
  /// القديم حين لا يُصرَّح. (AMIAL-ACTOR-001 أعلاه.)
  bool get isPosStaff => actorDeclared.value
      ? actor.value == 'pos'
      : (role.value == 'pos' || merchantContext.value?.actor == 'pos');
  /// مالك أو موظف يعمل داخل منشأة. ليست محفظة عميل حتى لو كان للموظف
  /// رقم أميال شخصي مستقل.
  bool get isMerchantSession => isMerchant || isPos || merchantContext.value?.actor == 'staff';

  /// ══════════════════════════════════════════════════════════════════
  /// **مالكُ المتجر** — وحدَه من يرى المالَ ويُدير الحساب.
  ///
  /// **استُعيدت بعد أن حُذفت وبقي مُنادُوها**: `access_gate.dart` و
  /// `merchant_wallet_screen.dart` ينادونها، فكان التطبيقُ **لا يُصرَّف
  /// إطلاقاً** — `The getter 'isMerchantOwner' isn't defined`. أي أنّ
  /// أيَّ بناءٍ في Codemagic كان سيسقط قبل أن يبدأ.
  ///
  /// **وتُقاس بالنفي لا بقيمةٍ واحدة**: الجلسةُ التاجريّةُ التي ليست
  /// نقطةَ بيعٍ ولا موظّفاً هي جلسةُ المالك. فقيمةٌ ثالثةٌ تُضاف غداً
  /// لا تجعل موظّفاً مالكاً بالخطأ — **والخطأُ هنا يفتح المالَ لكاشير.**
  ///
  /// **وصارت تُقرأ من `actor` حين يُصرَّح به** — والنفيُ يبقى مسلكَ
  /// الاحتياط حين لا يُصرَّح، فلا يُحرَم مالكٌ من متجره لأنّ حقلاً غاب.
  bool get isMerchantOwner => actorDeclared.value
      ? actor.value == 'owner'
      : (isMerchantSession && !isPos && merchantContext.value?.actor != 'staff');
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
  bool get isBusinessPlan => subscriptionPlan.value == 'business';
  bool get isEnterprisePlan => subscriptionPlan.value == 'enterprise';
  String get businessName => merchantContext.value?.businessName ?? merchantDisplayName.value ?? userName.value ?? 'تاجر أميال باي';

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

    // **ويُصفَّر الفاعلُ مع الجلسة** — وإلّا بقي «كاشير» بعد خروجه،
    // فيدخل المالكُ على الجهاز نفسِه فيجد شاشةَ موظّفه.
    actor.value = 'customer';
    actorDeclared.value = false;
    verificationLevel.value = 'basic';
    merchantVerificationStatus.value = null;
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
    merchantDisplayName.value = null;
    merchantContext.value = null;
    isLoaded.value = false;
    lastError.value = '';
  }
}
